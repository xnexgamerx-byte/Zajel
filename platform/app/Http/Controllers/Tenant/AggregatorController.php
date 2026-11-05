<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AggregatorEvent;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Services\Aggregator\AggregatorClient;
use App\Services\Aggregator\AggregatorException;
use App\Services\Aggregator\AggregatorInbox;
use App\Services\Aggregator\AggregatorOutbox;
use App\Services\Aggregator\AggregatorScanReview;
use App\Services\Aggregator\PlaceMatcher;
use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «ربط الشركة الوسيطة» (docs/plan/104): حساب الشركة في نظامهم وتاجرها، والمندوب الوسيط عندنا،
 * والبادئة، ومفتاح الـ Webhook، وربط حالاتهم ومناطقهم، وسجلّ ما ذهب وما جاء. لصاحب «بيانات الشركة».
 */
class AggregatorController extends Controller
{
    /** صفّ مناطق المحافظة الواحدة: في كلّ صفٍّ قائمةٌ بمناطق مدينتها عندهم، وفي بغداد مئاتٌ منها */
    private const AREAS_PER_PAGE = 15;

    public function edit(): View
    {
        $link = $this->link();

        return view('tenant.aggregator.edit', [
            'link' => $link,
            'couriers' => Courier::query()->where('is_intermediary', true)->orderBy('name')->get(['id', 'name']),
            'health' => $this->health($link),
            'statuses' => $link->catalog['statuses'] ?? [],
            'sizes' => $link->catalog['package_sizes'] ?? [],
            'merchants' => $link->catalog['merchants'] ?? [],
            'unmapped' => $this->unmappedCount(),
            'failing' => AggregatorJob::query()->where('status', 'failed')->count(),
            'hook' => session('aggregator_hook'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $link = $this->link();

        $data = $request->validate([
            'base_url' => ['required', 'url:https', 'max:200'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'],
            'courier_id' => ['nullable', Rule::exists('couriers', 'id')->where('company_id', $link->company_id)->where('is_intermediary', true)],
            'order_prefix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z_]*$/', 'regex:/[A-Z].*[A-Z].*[A-Z]/'],
            'package_size_id' => ['nullable', 'string', 'max:20'],
            'webhook_header' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            'sends_orders' => ['nullable', 'boolean'],
        ], [
            'order_prefix.regex' => 'البادئة حروفٌ إنكليزية كبيرة (ثلاثة على الأقل) ويجوز «_»، بلا أرقام ولا مسافات.',
            'webhook_header.regex' => 'اسم الترويسة حروفٌ إنكليزية وأرقام و«-» فقط.',
            'courier_id.exists' => 'اختر مندوباً معلَّماً «شركة وسيطة».',
        ], [
            'base_url' => 'عنوان نظامهم', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'courier_id' => 'المندوب الوسيط',
            'order_prefix' => 'البادئة', 'package_size_id' => 'حجم الطرد', 'webhook_header' => 'اسم الترويسة',
        ]);

        $changed = $data['username'] !== $link->username || filled($data['password'] ?? null);

        $link->fill([
            'base_url' => rtrim($data['base_url'], '/'),
            'username' => $data['username'],
            'courier_id' => $data['courier_id'] ?? null,
            'order_prefix' => $data['order_prefix'] ?: null,
            'package_size_id' => $data['package_size_id'] ?: null,
            'webhook_header' => $data['webhook_header'],
            'sends_orders' => $request->boolean('sends_orders'),
        ]);

        // كلمة المرور تُكتب لتتغيّر، وتغيّر الحساب يُسقط رمزه
        if (filled($data['password'] ?? null)) {
            $link->password = $data['password'];
        }
        if ($changed) {
            $link->aggre_token = null;
            $link->aggre_token_at = null;
        }

        // الإرسال لا يُفتح ناقصاً، لكن ما كُتب يُحفظ كلّه: يُفتح حين يكتمل
        $missing = [];
        if ($link->sends_orders && ! $link->readyToSend()) {
            $link->sends_orders = false;
            $missing = array_keys(array_filter([
                'الحساب' => blank($link->username) || blank($link->password),
                'التاجر' => blank($link->merchant_id),
                'المندوب الوسيط' => blank($link->courier_id),
                'حجم الطرد' => blank($link->package_size_id),
            ]));
        }

        $link->save();
        $this->audit('aggregator_changed', ['sends_orders' => $link->sends_orders, 'courier_id' => $link->courier_id, 'prefix' => $link->order_prefix]);

        return back()->with('success', $missing
            ? 'حُفظ الربط، لكن الإرسال لم يُفتح: ينقصه '.implode('، ', $missing).'. أكمله ثم فعّل الإرسال.'
            : 'حُفظ الربط.');
    }

    /** يدخل بالحساب، ويجلب تجّار الحساب وحالاتهم ومدنهم وأحجام الطرود */
    public function connect(): RedirectResponse
    {
        $link = $this->link();

        if (blank($link->username) || blank($link->password)) {
            return back()->withErrors(['connect' => 'اكتب اسم المستخدم وكلمة المرور واحفظ أوّلاً.']);
        }

        try {
            $client = AggregatorClient::for($link);
            $client->login();
            $merchants = $client->merchants();
            $catalog = $link->catalog ?? [];
            $catalog['merchants'] = array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'username' => $m['username']], $merchants);
            $catalog['cities'] = $client->cities();
            $catalog['package_sizes'] = $client->packageSizes();

            // تاجرٌ واحد في الحساب: هو التاجر
            if (count($merchants) === 1 && blank($link->merchant_id)) {
                $link->fill(['merchant_id' => $merchants[0]['id'], 'merchant_name' => $merchants[0]['name'], 'merchant_token' => $merchants[0]['token']]);
            }

            if (filled($link->merchant_token)) {
                $catalog['statuses'] = AggregatorClient::for($link)->statuses();
            }

            $link->catalog = $catalog;
            $link->save();
        } catch (AggregatorException $e) {
            return back()->withErrors(['connect' => 'لم يتمّ الاتصال: '.$e->getMessage()]);
        }

        return back()->with('success', 'اتّصل بنظامهم: '.count($merchants).' تاجر، و'.count($catalog['cities']).' مدينة.');
    }

    /** التاجر الذي تُرسل الطلبات باسمه: رمزه يُجلب من نظامهم عند الاختيار ولا يُعرض */
    public function merchant(Request $request): RedirectResponse
    {
        $link = $this->link();
        $id = (string) $request->validate(['merchant_id' => ['required', 'string', 'max:40']])['merchant_id'];

        try {
            $client = AggregatorClient::for($link);
            $chosen = collect($client->merchants())->firstWhere('id', $id);

            if ($chosen === null) {
                return back()->withErrors(['merchant_id' => 'هذا التاجر ليس في حسابكم عندهم.']);
            }

            $link->fill(['merchant_id' => $chosen['id'], 'merchant_name' => $chosen['name'], 'merchant_token' => $chosen['token']])->save();
            $link->catalog = ['statuses' => AggregatorClient::for($link)->statuses()] + ($link->catalog ?? []);
            $link->save();
        } catch (AggregatorException $e) {
            return back()->withErrors(['merchant_id' => $e->getMessage()]);
        }

        $this->audit('aggregator_changed', ['merchant_id' => $link->merchant_id]);

        return back()->with('success', "تُرسل الطلبات باسم «{$link->merchant_name}».");
    }

    /** مفتاحٌ جديد للـ Webhook: يُرى الآن مرّةً واحدة، ويُعطى لهم بصيغة «الترويسة:المفتاح» */
    public function hook(): RedirectResponse
    {
        $link = $this->link();
        $token = Str::random(48);

        $link->forceFill(['webhook_token_hash' => hash('sha256', $token), 'webhook_token_last4' => substr($token, -4)])->save();
        $this->audit('aggregator_hook_reset', ['last4' => $link->webhook_token_last4]);

        return back()->with('aggregator_hook', $link->webhook_header.':'.$token)
            ->with('success', 'وُلّد مفتاحٌ جديد: انسخه الآن وأعطه لهم، فلن يظهر ثانيةً. المفتاح القديم لم يعد يعمل.');
    }

    public function statuses(Request $request): RedirectResponse
    {
        $link = $this->link();
        $map = $request->validate([
            'map' => ['array'],
            'map.*' => [Rule::in(array_keys(AggregatorLink::ACTIONS))],
        ])['map'] ?? [];

        $link->status_map = collect($map)->mapWithKeys(fn ($action, $id) => [(string) $id => $action])->all();
        $link->save();

        return back()->with('success', 'حُفظ ربط الحالات.');
    }

    /**
     * هل تُقرأ تحديثاتهم؟ (docs/plan/107): آخر ما وصل، وآخر ما رُفض ولماذا، وما جرى لتحديثات الأسبوع، وما ينتظر
     * التطبيق أكثر من دقيقتين (المجدوِل لا يعمل)، وشحنات المندوب الوسيط التي لا رقم لها عندهم.
     *
     * @return array<string, mixed>
     */
    private function health(AggregatorLink $link): array
    {
        $active = fn () => Shipment::query()->where('delivery_courier_id', $link->courier_id)
            ->whereIn('status', [ShipmentStatus::InTransit->value, ShipmentStatus::OutForDelivery->value]);

        return [
            'week' => AggregatorEvent::query()->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('result, count(*) as n')->groupBy('result')->pluck('n', 'result')->map(fn ($n) => (int) $n)->all(),
            'stuck' => AggregatorEvent::query()->where('result', 'pending')->where('created_at', '<', now()->subMinutes(2))->count(),
            'active' => $link->courier_id ? $active()->count() : 0,
            'unsent' => $link->courier_id ? $active()->whereNull('aggregator_order_id')->count() : 0,
        ];
    }

    /** المحافظات ومدنهم، وكم من مناطق كلٍّ منها رُبط. مناطقها نفسها في صفحتها: آلاف الخيارات لا تُبنى في صفحة */
    public function areas(): View
    {
        $link = $this->link();
        $mapped = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');
        $cityIds = City::query()->get(['id', 'governorate_id'])->groupBy('governorate_id');
        $done = AggregatorPlace::query()->where('kind', 'city')->pluck('remote_id', 'local_id');

        return view('tenant.aggregator.areas', [
            'link' => $link,
            'governorates' => Governorate::query()->orderBy('id')->get(['id', 'name_ar'])->map(function ($g) use ($cityIds, $done) {
                $ids = ($cityIds[$g->id] ?? collect())->pluck('id');
                $g->total = $ids->count();
                $g->mapped = $ids->filter(fn ($id) => isset($done[$id]))->count();

                return $g;
            }),
            'theirCities' => $link->catalog['cities'] ?? [],
            'mapped' => $mapped,
        ]);
    }

    /** مناطق محافظةٍ بمناطق مدينتها عندهم: صفحاتٌ وبحثٌ، وغير المربوطة أوّلاً */
    public function governorateAreas(Request $request, Governorate $governorate): View
    {
        $link = $this->link();
        $remoteCity = AggregatorPlace::query()->where('kind', 'governorate')->where('local_id', $governorate->id)->value('remote_id');
        $theirCities = $link->catalog['cities'] ?? [];

        // مدينةٌ أخرى عندهم: لمنطقةٍ هي عندهم في محافظةٍ غير محافظتها عندنا
        $shown = $remoteCity;
        $other = (string) $request->query('their_city', '');
        if ($remoteCity && $other !== '' && $other !== (string) $remoteCity && isset($theirCities[$other])) {
            $shown = $other;
        }
        $regionsError = null;
        if ($shown && ! isset($link->catalog['regions'][$shown])) {
            try {
                $catalog = $link->catalog;
                $catalog['regions'][$shown] = AggregatorClient::for($link)->regions((string) $shown);
                $link->forceFill(['catalog' => $catalog])->save();
            } catch (AggregatorException $e) {
                $regionsError = $e->getMessage();
            }
        }
        $regions = $shown ? ($link->catalog['regions'][$shown] ?? []) : [];
        $places = AggregatorPlace::query()->where('kind', 'city')->get(['local_id', 'remote_id', 'remote_city_id', 'remote_name'])->keyBy('local_id');
        $mapped = $places->map->remote_id;
        // ما رُبط بأول الاسم يُعلَّم ليُراجَع، ويُعرض وحده إن طُلب
        $prefixed = AggregatorPlace::query()->where('kind', 'city')->where('method', 'prefix')->pluck('local_id')->flip();

        $show = in_array($request->query('show'), ['all', 'prefix'], true) ? $request->query('show') : 'unmapped';
        $all = $show === 'all';
        $search = trim((string) $request->query('q', ''));

        $cities = City::query()->where('governorate_id', $governorate->id)->orderBy('name_ar')->get(['id', 'name_ar'])
            ->when($show === 'unmapped', fn ($c) => $c->filter(fn ($city) => ! isset($mapped[$city->id])))
            ->when($show === 'prefix', fn ($c) => $c->filter(fn ($city) => isset($prefixed[$city->id])))
            ->when($search !== '', fn ($c) => $c->filter(fn ($city) => str_contains(Arabic::looseFold($city->name_ar), Arabic::looseFold($search))))
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator($cities->forPage($page, self::AREAS_PER_PAGE)->values(), $cities->count(), self::AREAS_PER_PAGE, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('tenant.aggregator.areas-governorate', [
            'governorate' => $governorate,
            'remoteCity' => $remoteCity,
            'remoteCityName' => $remoteCity ? ($theirCities[$remoteCity] ?? $remoteCity) : null,
            'theirCities' => $theirCities,
            'shown' => $shown,
            'regionsError' => $regionsError,
            'regions' => $regions,
            'mapped' => $mapped,
            'places' => $places,
            'prefixed' => $prefixed,
            'rows' => $rows,
            'show' => $show,
            'all' => $all,
            'search' => $search,
        ]);
    }

    public function saveAreas(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'governorate' => ['array'], 'governorate.*' => ['nullable', 'string', 'max:20'],
            'city' => ['array'], 'city.*' => ['nullable', 'string', 'max:20'],
            'their_city' => ['nullable', 'string', 'max:20'],
        ]);

        $link = $this->link();
        // أسماء مناطقهم بأرقامها: الاتحاد يحفظ الأرقام مفاتيح، وكلّ مدينةٍ لها قائمتها
        $regions = array_reduce(array_values($link->catalog['regions'] ?? []), fn (array $carry, $list) => $carry + (array) $list, []);
        $names = ['governorate' => $link->catalog['cities'] ?? [], 'city' => $regions];

        // ما ليس من محافظات الشركة ومناطقها لا يُربط ولو أُرسل رقمه
        $known = ['governorate' => Governorate::query()->pluck('id')->flip(), 'city' => City::query()->pluck('id')->flip()];

        // صفحةٌ تعرض مناطق مدينةٍ أخرى عندهم: ما يُربط فيها يُحفظ بمدينته، وما يُربط بمدينة محافظته بلا مدينة
        $theirCity = filled($data['their_city'] ?? null) && isset($names['governorate'][$data['their_city']]) ? (string) $data['their_city'] : null;
        $governorateCity = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');
        $areaGovernorate = City::query()->pluck('governorate_id', 'id');
        if ($theirCity !== null) {
            $names['city'] = ($link->catalog['regions'][$theirCity] ?? []) + $names['city'];
        }

        foreach (['governorate', 'city'] as $kind) {
            foreach ($data[$kind] ?? [] as $local => $remote) {
                // «كما هي»: مربوطةٌ في مدينةٍ غير المعروضة، فلا يمسّها حفظ الصفحة
                if (! isset($known[$kind][(int) $local]) || $remote === 'keep') {
                    continue;
                }

                if (blank($remote)) {
                    AggregatorPlace::query()->where('kind', $kind)->where('local_id', (int) $local)->delete();

                    continue;
                }

                $other = null;
                if ($kind === 'city' && $theirCity !== null
                    && $theirCity !== (string) ($governorateCity[$areaGovernorate[(int) $local] ?? 0] ?? '')) {
                    // من مدينةٍ أخرى: يُقبل ما هو من مناطقها وحدها
                    if (! isset($link->catalog['regions'][$theirCity][(string) $remote])) {
                        continue;
                    }
                    $other = $theirCity;
                }

                AggregatorPlace::query()->updateOrCreate(
                    ['kind' => $kind, 'local_id' => (int) $local],
                    ['remote_id' => (string) $remote, 'remote_name' => $names[$kind][(string) $remote] ?? null, 'method' => 'manual']
                        + ($kind === 'city' ? ['remote_city_id' => $other] : []),
                );
            }
        }

        return back()->with('success', 'حُفظ ربط المناطق.');
    }

    /**
     * يربط بالاسم ما لم يُربط: المحافظات بمدنهم، ثم يجلب مناطق كل مدينةٍ مربوطة ويربط مناطقنا بها.
     * ما يحتمل أكثر من اسمٍ، أو لا يوجد عندهم، لا يُربط بالتخمين: يُذكر في الرسالة ويُترك للموظّف.
     * ما يُجلب يُحفظ مع كل مدينة، فانقطاعٌ في الطريق لا يُضيع ما جُلب وإعادة الضغط تكمل منه.
     */
    public function autoAreas(): RedirectResponse
    {
        $this->longRequest();

        $link = $this->link();
        $theirCities = $link->catalog['cities'] ?? [];

        if ($theirCities === []) {
            return back()->withErrors(['areas' => 'اتّصل بنظامهم أوّلاً من صفحة الربط لتُجلب مدنهم.']);
        }

        $mapped = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');
        $added = 0;
        $left = ['ambiguous' => 0, 'none' => 0];
        $unmatchedGovernorates = [];

        foreach (Governorate::query()->get(['id', 'name_ar']) as $governorate) {
            if (isset($mapped[$governorate->id])) {
                continue;
            }

            [$remote, $state] = PlaceMatcher::lookup($governorate->name_ar, $theirCities);

            if ($remote === null) {
                $unmatchedGovernorates[] = $governorate->name_ar;

                continue;
            }

            AggregatorPlace::query()->create(['kind' => 'governorate', 'local_id' => $governorate->id, 'remote_id' => $remote, 'remote_name' => $theirCities[$remote]]);
            $mapped[$governorate->id] = $remote;
            $added++;
        }

        $catalog = $link->catalog;
        $client = AggregatorClient::for($link);
        $cityMapped = AggregatorPlace::query()->where('kind', 'city')->pluck('remote_id', 'local_id');

        try {
            foreach ($mapped as $governorateId => $remoteCity) {
                if (! isset($catalog['regions'][$remoteCity])) {
                    $catalog['regions'][$remoteCity] = $client->regions((string) $remoteCity);
                    $link->forceFill(['catalog' => $catalog])->save();
                }
                $regions = $catalog['regions'][$remoteCity];

                foreach (City::query()->where('governorate_id', $governorateId)->get(['id', 'name_ar']) as $city) {
                    if (isset($cityMapped[$city->id])) {
                        continue;
                    }

                    [$remote, $state] = PlaceMatcher::lookup($city->name_ar, $regions);

                    if ($remote === null) {
                        $left[$state]++;

                        continue;
                    }

                    AggregatorPlace::query()->create(['kind' => 'city', 'local_id' => $city->id, 'remote_id' => $remote, 'remote_name' => $regions[$remote], 'method' => 'name']);
                    $added++;
                }
            }
        } catch (AggregatorException $e) {
            return back()->withErrors(['areas' => "جُلب ما أمكن وحُفظ، ثم تعذّر: {$e->getMessage()} — اضغط «اربط بالاسم» ثانيةً لتكمل من حيث وقفت."]);
        }

        $report = "رُبط بالاسم {$added}.";
        if ($left['ambiguous']) {
            $report .= " {$left['ambiguous']} منطقةً اسمها يشبه أكثر من منطقةٍ عندهم فلم تُربط بالتخمين.";
        }
        if ($left['none']) {
            $report .= " {$left['none']} منطقةً لا اسم مثلها عندهم.";
        }
        if ($unmatchedGovernorates) {
            $report .= ' محافظاتٌ لم تجد مدينةً بنفس اسمها: '.implode('، ', $unmatchedGovernorates).' — اختر مدينتها بيدك.';
        }
        if ($left['ambiguous'] || $left['none'] || $unmatchedGovernorates) {
            $report .= ' ما بقي اختره بيدك.';
        }

        return back()->with('success', $report);
    }

    /**
     * ما بقي بلا ربطٍ يُربط بأطول بدايةٍ من اسمه تطابق منطقةً واحدةً عندهم: «دجيل الحاتمية» ← «دجيل»،
     * «مدينة الصدر حي طارق» ← «مدينة الصدر». ما ربطه أحدٌ لا يُمسّ، وما لا بداية له عندهم أو تحتمل بدايته
     * أكثر من منطقة يُترك للموظّف. وكلّ ما رُبط هكذا يُعلَّم «بأول الاسم» ليُراجَع، ويُذكر في الرسالة.
     */
    public function prefixAreas(): RedirectResponse
    {
        $this->longRequest();

        $link = $this->link();
        $governorates = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');

        if ($governorates->isEmpty()) {
            return back()->withErrors(['areas' => 'اربط المحافظات بمدنهم أوّلاً.']);
        }

        $catalog = $link->catalog;
        $client = AggregatorClient::for($link);
        $mapped = AggregatorPlace::query()->where('kind', 'city')->pluck('remote_id', 'local_id');
        $names = Governorate::query()->pluck('name_ar', 'id');
        $added = 0;
        $exact = 0;
        $left = ['ambiguous' => 0, 'none' => 0];
        $done = [];

        try {
            foreach ($governorates as $governorateId => $remoteCity) {
                if (! isset($catalog['regions'][$remoteCity])) {
                    $catalog['regions'][$remoteCity] = $client->regions((string) $remoteCity);
                    $link->forceFill(['catalog' => $catalog])->save();
                }
                $regions = $catalog['regions'][$remoteCity];
                $index = PlaceMatcher::index($regions);

                foreach (City::query()->where('governorate_id', $governorateId)->get(['id', 'name_ar']) as $city) {
                    if (isset($mapped[$city->id])) {
                        continue;
                    }

                    [$remote, $state] = PlaceMatcher::lookupPrefix($city->name_ar, $index);

                    if ($remote === null) {
                        $left[$state]++;

                        continue;
                    }

                    // الاسم كاملاً ربطٌ بالاسم لا يحتاج مراجعة؛ البداية وحدها تُعلَّم وتُذكر
                    AggregatorPlace::query()->create([
                        'kind' => 'city', 'local_id' => $city->id, 'remote_id' => $remote,
                        'remote_name' => $regions[$remote], 'method' => $state === 'prefix' ? 'prefix' : 'name',
                    ]);
                    if ($state === 'prefix') {
                        $added++;
                        $done[] = ($names[$governorateId] ?? '').' — '.$city->name_ar.' ← '.$regions[$remote];
                    } else {
                        $exact++;
                    }
                }
            }
        } catch (AggregatorException $e) {
            return back()->withErrors(['areas' => "رُبط ما أمكن، ثم تعذّر: {$e->getMessage()} — اضغط ثانيةً لتكمل."]);
        }

        if ($added || $exact) {
            $this->audit('aggregator_areas_prefix', ['count' => $added, 'exact' => $exact]);
        }

        $report = "رُبط بأول الاسم {$added}، وكلّها معلَّمة «بأول الاسم» لتراجعها.";
        if ($exact) {
            $report .= " ورُبط {$exact} باسمه كاملاً.";
        }
        if ($left['ambiguous']) {
            $report .= " {$left['ambiguous']} منطقةً بدايتها تحتمل أكثر من منطقةٍ عندهم.";
        }
        if ($left['none']) {
            $report .= " {$left['none']} منطقةً لا بداية لها عندهم.";
        }
        if ($left['ambiguous'] || $left['none']) {
            $report .= ' هذه تُربط بيدك.';
        }

        return back()->with('success', $report)->with('aggregator_prefix', array_slice($done, 0, 500));
    }

    /**
     * ملف الربط كلّه للمراجعة خارج النظام: مدنهم ومناطق ما جُلب منها، ومحافظاتنا ومناطقنا بربطها الحاليّ.
     * لا أسرار فيه ولا يتّصل بنظامهم: ما لم تُجلب مناطقه بعدُ يُجلب بـ«اربط بالاسم» أوّلاً.
     */
    public function exportAreas(): StreamedResponse
    {
        $link = $this->link();
        $catalog = $link->catalog ?? [];
        $governorates = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');
        $places = AggregatorPlace::query()->where('kind', 'city')->get(['local_id', 'remote_id', 'remote_city_id', 'method'])->keyBy('local_id');
        $areas = City::query()->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar'])->groupBy('governorate_id');

        $data = [
            'format' => 'aggregator-areas/1',
            'their_cities' => $catalog['cities'] ?? [],
            // مناطق كل مدنهم المجلوبة: لمنطقةٍ هي عندهم في مدينةٍ غير مدينة محافظتها
            'their_regions_by_city' => $catalog['regions'] ?? [],
            'governorates' => Governorate::query()->orderBy('id')->get(['id', 'name_ar'])->map(function ($g) use ($governorates, $catalog, $places, $areas) {
                $remote = $governorates[$g->id] ?? null;

                return [
                    'id' => $g->id,
                    'name' => $g->name_ar,
                    'their_city_id' => $remote,
                    'their_regions' => $remote ? ($catalog['regions'][$remote] ?? null) : null,
                    'areas' => ($areas[$g->id] ?? collect())->map(fn ($city) => [
                        'id' => $city->id,
                        'name' => $city->name_ar,
                        'their_region_id' => $places[$city->id]->remote_id ?? null,
                        // في مدينةٍ أخرى عندهم، وإلّا فارغ
                        'their_city_id' => $places[$city->id]->remote_city_id ?? null,
                        'method' => $places[$city->id]->method ?? null,
                    ])->values(),
                ];
            })->values(),
        ];

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'aggregator-areas-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * يرفع ربطاً رُوجع خارج النظام: {"governorates": {محافظتنا: مدينتهم}, "areas": {منطقتنا: منطقتهم}}،
     * والمنطقة التي هي عندهم في مدينةٍ أخرى: {منطقتنا: {"city": مدينتهم, "region": منطقتهم}}.
     * لا يُقبل إلّا ما هو من محافظات الشركة ومناطقها، ومنطقتهم من مناطق مدينة محافظتها المجلوبة.
     * ما ربطه موظّفٌ بيده لا يُغيَّر، وما رُفع يُعلَّم «file».
     */
    public function importAreas(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data) || (! is_array($data['areas'] ?? null) && ! is_array($data['governorates'] ?? null) && ! is_array($data['new'] ?? null))) {
            return back()->withErrors(['areas' => 'الملف ليس ملف ربطٍ صالحاً: يُنتظر فيه «areas» أو «governorates» أو «new».']);
        }

        // ملف التصدير («نزّل ملف الربط») ليس ملف ربطٍ: فيه قائمة محافظاتٍ بتفاصيلها، ورفعه يُربك الرفع
        if (is_array($data['governorates'] ?? null) && array_is_list($data['governorates'])) {
            return back()->withErrors(['areas' => 'هذا ملف التصدير الحالي، وليس ملف ربطٍ مراجَعاً. ارفع الملف الذي فيه «areas» أو «new» (مثل ربط-المناطق-الجديدة.json).']);
        }

        $link = $this->link();
        $theirCities = $link->catalog['cities'] ?? [];
        $regions = $link->catalog['regions'] ?? [];
        $counts = ['governorates' => 0, 'areas' => 0, 'manual' => 0, 'rejected' => 0];

        $ourGovernorates = Governorate::query()->pluck('id')->flip();
        $existing = AggregatorPlace::query()->get()->groupBy('kind')->map->keyBy('local_id');

        foreach ((array) ($data['governorates'] ?? []) as $local => $remote) {
            if (! is_scalar($remote)) {
                $counts['rejected']++;

                continue;
            }
            $remote = (string) $remote;
            if (! isset($ourGovernorates[(int) $local]) || ! isset($theirCities[$remote])) {
                $counts['rejected']++;

                continue;
            }
            if (($existing['governorate'][(int) $local]->method ?? null) === 'manual' && $existing['governorate'][(int) $local]->remote_id !== $remote) {
                $counts['manual']++;

                continue;
            }

            AggregatorPlace::query()->updateOrCreate(
                ['kind' => 'governorate', 'local_id' => (int) $local],
                ['remote_id' => $remote, 'remote_name' => $theirCities[$remote], 'method' => 'file'],
            );
            $counts['governorates']++;
        }

        $governorates = AggregatorPlace::query()->where('kind', 'governorate')->pluck('remote_id', 'local_id');
        $ourAreas = City::query()->pluck('governorate_id', 'id');
        $manual = AggregatorPlace::query()->where('kind', 'city')->where('method', 'manual')->pluck('remote_id', 'local_id');

        foreach ((array) ($data['areas'] ?? []) as $local => $remote) {
            // «منطقتهم»، أو {"city": مدينتهم, "region": منطقتهم} لمنطقةٍ في مدينةٍ غير مدينة محافظتها
            $governorate = $ourAreas[(int) $local] ?? null;
            $own = $governorate && isset($governorates[$governorate]) ? (string) $governorates[$governorate] : null;
            if (is_array($remote) ? ! is_scalar($remote['city'] ?? null) || ! is_scalar($remote['region'] ?? null) : ! is_scalar($remote)) {
                $counts['rejected']++;

                continue;
            }
            $city = is_array($remote) ? (string) ($remote['city'] ?? '') : $own;
            $remote = is_array($remote) ? (string) ($remote['region'] ?? '') : (string) $remote;
            $options = $governorate && $city !== null && $city !== '' ? ($regions[$city] ?? []) : [];
            $other = $city !== $own ? $city : null;

            if (! isset($options[$remote])) {
                $counts['rejected']++;

                continue;
            }
            if (isset($manual[(int) $local]) && (string) $manual[(int) $local] !== $remote) {
                $counts['manual']++;

                continue;
            }

            AggregatorPlace::query()->updateOrCreate(
                ['kind' => 'city', 'local_id' => (int) $local],
                ['remote_id' => $remote, 'remote_city_id' => $other, 'remote_name' => $options[$remote], 'method' => 'file'],
            );
            $counts['areas']++;
        }

        // مناطق عندهم لا مقابل لها عندنا: تُضاف لنا باسمها عندهم، كما تضيفها الشركة لنفسها من «منطقة ناقصة».
        // ما يطابق منطقةً موجودةً عندنا بكتابةٍ أخرى يُربط بها ولا يُكرَّر، وما ليس من مدنهم أو مناطقها يُرفض.
        $companyId = $request->user()->company_id;
        $offered = Governorate::offered()->pluck('id')->flip();
        $handled = [];
        foreach ((array) ($data['new'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_scalar($entry['governorate'] ?? null) || ! is_scalar($entry['city'] ?? null) || ! is_scalar($entry['region'] ?? null)) {
                $counts['rejected']++;

                continue;
            }
            $governorateId = (int) ($entry['governorate'] ?? 0);
            $city = (string) ($entry['city'] ?? '');
            $region = (string) ($entry['region'] ?? '');
            $remoteOwn = $governorates[$governorateId] ?? null;

            if (! isset($offered[$governorateId]) || $remoteOwn === null || ! isset($regions[$city][$region])) {
                $counts['rejected']++;

                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', (string) $regions[$city][$region]));
            $same = City::query()->where('governorate_id', $governorateId)->get(['id', 'company_id', 'name_ar', 'is_active'])
                ->filter(fn (City $candidate) => Arabic::looseFold($candidate->name_ar) === Arabic::looseFold($name))
                ->sortByDesc('is_active')
                ->first();

            if ($same?->is_active) {
                $cityId = $same->id;
            } elseif ($same && $same->company_id === $companyId) {
                $same->update(['is_active' => true]);
                $cityId = $same->id;
            } else {
                $cityId = City::query()->create(['governorate_id' => $governorateId, 'company_id' => $companyId, 'name_ar' => $name, 'is_active' => true])->id;
                $counts['created'] = ($counts['created'] ?? 0) + 1;
            }

            // الاسم نفسه مرّتين في الملف (مكرّر عندهم): أوّل رقمٍ منه فقط
            if (isset($handled[$cityId])) {
                continue;
            }
            $handled[$cityId] = true;

            $current = AggregatorPlace::query()->where('kind', 'city')->where('local_id', $cityId)->first();
            if ($current && $current->method === 'manual' && ((string) $current->remote_id !== $region || (string) $current->remote_city_id !== ($city !== (string) $remoteOwn ? $city : ''))) {
                $counts['manual']++;

                continue;
            }

            AggregatorPlace::query()->updateOrCreate(
                ['kind' => 'city', 'local_id' => $cityId],
                ['remote_id' => $region, 'remote_city_id' => $city !== (string) $remoteOwn ? $city : null, 'remote_name' => $name, 'method' => 'file'],
            );
            $counts['areas']++;
        }

        $this->audit('aggregator_areas_import', $counts);

        $report = "رُفع الربط: {$counts['governorates']} محافظة و{$counts['areas']} منطقة.";
        if (! empty($counts['created'])) {
            $report .= " أُضيفت {$counts['created']} منطقةً جديدةً لنا باسمها عندهم.";
        }
        if ($counts['manual']) {
            $report .= " {$counts['manual']} ربطها موظّفٌ بيده بغير ما في الملف فبقيت كما هي.";
        }
        if ($counts['rejected']) {
            $report .= " {$counts['rejected']} لم تُقبل: ليست من مناطقنا، أو منطقتهم ليست من مدينة محافظتها.";
        }

        return back()->with('success', $report);
    }

    /**
     * يفحص ما رُبط على قوائمهم الحيّة: مدينةٌ أو منطقةٌ لم تعد عندهم، وأكثر من منطقةٍ عندنا على منطقةٍ واحدة
     * عندهم (قد يكون مقصوداً)، وما بقي بلا ربط — وهذا ما يُرفض عند الإرسال. لا يغيّر شيئاً.
     */
    public function verifyAreas(): RedirectResponse
    {
        $this->longRequest();

        $link = $this->link();
        $client = AggregatorClient::for($link);
        $catalog = $link->catalog ?? [];

        try {
            $catalog['cities'] = $client->cities();
            $governorates = AggregatorPlace::query()->where('kind', 'governorate')->get();
            $cities = AggregatorPlace::query()->where('kind', 'city')->get()->keyBy('local_id');
            $ours = City::query()->get(['id', 'governorate_id', 'name_ar'])->groupBy('governorate_id');
            $names = Governorate::query()->pluck('name_ar', 'id');

            $fetched = [];
            $report = ['gone_cities' => [], 'gone_regions' => [], 'shared' => [], 'unmapped' => 0, 'ok' => 0, 'governorates_left' => $names->count() - $governorates->count()];

            foreach ($governorates as $place) {
                if (! isset($catalog['cities'][$place->remote_id])) {
                    $report['gone_cities'][] = $names[$place->local_id] ?? $place->local_id;

                    continue;
                }

                $catalog['regions'][$place->remote_id] = $regions = $fetched[(string) $place->remote_id] ??= $client->regions((string) $place->remote_id);
                $used = [];

                foreach ($ours[$place->local_id] ?? [] as $city) {
                    $mapped = $cities[$city->id] ?? null;

                    // في مدينةٍ أخرى عندهم: تُفحص على مناطق تلك المدينة
                    $options = $regions;
                    if ($mapped?->remote_city_id) {
                        $other = (string) $mapped->remote_city_id;
                        if (! isset($fetched[$other])) {
                            $fetched[$other] = isset($catalog['cities'][$other]) ? $client->regions($other) : [];
                            $catalog['regions'][$other] = $fetched[$other];
                        }
                        $options = $fetched[$other];
                    }

                    if ($mapped === null) {
                        $report['unmapped']++;
                    } elseif (! isset($options[$mapped->remote_id])) {
                        $report['gone_regions'][] = ($names[$place->local_id] ?? '').' — '.$city->name_ar;
                    } else {
                        $report['ok']++;
                        $used[$mapped->remote_id][] = $city->name_ar;
                    }
                }

                foreach ($used as $remote => $list) {
                    if (count($list) > 1) {
                        $report['shared'][] = ($regions[$remote] ?? $remote).' ← '.implode('، ', $list);
                    }
                }
            }
        } catch (AggregatorException $e) {
            return back()->withErrors(['areas' => 'تعذّر الفحص: '.$e->getMessage()]);
        }

        $link->forceFill(['catalog' => $catalog])->save();

        return back()->with('aggregator_check', $report);
    }

    public function log(Request $request): View
    {
        return view('tenant.aggregator.log', [
            'jobs' => AggregatorJob::query()->with('shipment:id,number')->where('status', '!=', 'done')
                ->orderByRaw("case when status = 'failed' then 0 else 1 end")->latest('id')->limit(200)->get(),
            'events' => AggregatorEvent::query()->with('shipment:id,number')->latest('id')
                ->when($request->query('result'), fn ($q, $r) => $q->where('result', $r))->paginate(50)->withQueryString(),
        ]);
    }

    public function retry(AggregatorJob $job): RedirectResponse
    {
        AggregatorOutbox::retry($job);

        return back()->with('success', 'يُعاد إرساله خلال دقيقة.');
    }

    /**
     * يعيد تطبيق تحديثٍ تعذّر أو ينتظر أو لم تُعرف شحنته (docs/plan/106): بعد اختيار سببٍ كان ناقصاً، أو ربط
     * الشحنة. ولا يُعاد ما جاء بعده تحديثٌ أحدث لشحنته: الأحدث هو ما عليه الطرد عندهم الآن.
     */
    public function replay(AggregatorEvent $event, AggregatorInbox $inbox): RedirectResponse
    {
        if (! in_array($event->result, ['error', 'waiting', 'unmatched'], true)) {
            return back()->withErrors(['event' => 'هذا التحديث طُبّق، فلا يُعاد.']);
        }

        $newer = $event->shipment_id !== null && AggregatorEvent::query()->where('shipment_id', $event->shipment_id)
            ->where('id', '>', $event->id)->whereNotIn('result', ['unmatched'])->exists();

        if ($newer) {
            return back()->withErrors(['event' => 'وصل بعده تحديثٌ أحدث لهذه الشحنة، والأحدث هو ما عليه الآن عندهم.']);
        }

        // يعود «بانتظار التطبيق» ويُطبَّق بقفل الشركة وترتيبها، كما يُطبَّق ما يصل منهم
        $event->forceFill(['result' => 'pending', 'message' => null])->save();
        $inbox->processPending($this->link(), wait: 5, seconds: 20);
        $event->refresh();

        $this->audit('aggregator_event_replayed', ['event' => $event->id, 'result' => $event->result]);

        if ($event->result === 'pending') {
            return back()->with('success', 'سيُطبَّق خلال دقيقة.');
        }

        return back()->with('success', 'أُعيد تطبيقه: '.(AggregatorEvent::RESULTS[$event->result] ?? $event->result)
            .($event->message ? ' — '.$event->message : '.'));
    }

    /** في كلّ «تحقّق من نظامهم»: سؤالٌ لكلّ شحنة، فلا يطول الطلب */
    public const VERIFY_BATCH = 150;

    /**
     * «مراجعة مسح الوسيط» (docs/plan/121): كلّ شحنةٍ مفتوحةٍ مع المندوب الوسيط، وأيقرأ مندوبهم طردها، ولماذا لا،
     * وما يُصلحها: طبع وصلها، أو إرسالها. وما سُئل عنه نظامهم بعدُ يظهر بجوابه.
     */
    public function review(Request $request, AggregatorScanReview $review): View
    {
        $link = $this->link();
        $rows = $review->rows($link, $this->verified());
        $bad = $rows->filter(fn ($row) => $row['problems'] !== []);

        return view('tenant.aggregator.review', [
            'link' => $link,
            'rows' => $request->boolean('all') ? $rows : $bad,
            'all' => $request->boolean('all'),
            'total' => $rows->count(),
            'bad' => $bad->count(),
            'merchants' => AggregatorScanReview::byMerchant($rows),
            'toPrint' => $bad->filter(fn ($row) => array_intersect($row['problems'], AggregatorScanReview::LABEL_PROBLEMS) !== [])
                ->map(fn ($row) => $row['shipment']->id)->values(),
            'toSend' => $bad->filter(fn ($row) => array_intersect($row['problems'], AggregatorScanReview::SEND_PROBLEMS) !== [])->count(),
            'verifiedCount' => count($this->verified()),
        ]);
    }

    /**
     * يسأل نظامهم عن كلّ شحنةٍ برقمها عندهم (check-order-exists-by-company-order-number)، دفعةً بعد دفعة.
     * وما وُجد عندهم ولم يصلنا رقمه يُربط به، فلا يُنشأ ثانيةً.
     */
    public function verifyReview(AggregatorScanReview $review): RedirectResponse
    {
        $this->longRequest();
        $link = $this->link();
        $verified = $this->verified();
        $pending = $review->rows($link, $verified)->reject(fn ($row) => array_key_exists($row['shipment']->id, $verified))
            ->take(self::VERIFY_BATCH);

        if ($pending->isEmpty()) {
            return back()->with('success', 'سُئل نظامهم عن كلّ الشحنات. ابدأ من جديد بـ«امسح الجواب» إن تغيّر شيء.');
        }

        $client = AggregatorClient::for($link);
        $adopted = 0;

        try {
            foreach ($pending as $row) {
                $shipment = $row['shipment'];
                $order = $client->findOrder($row['expected']);
                $verified[$shipment->id] = $order !== null;

                $remote = (string) ($order['id'] ?? $order['qr_id'] ?? '');
                if ($remote !== '' && blank($shipment->aggregator_order_id)) {
                    $shipment->forceFill(['aggregator_order_id' => $remote])->saveQuietly();
                    $adopted++;
                }
            }
        } catch (AggregatorException $e) {
            $this->remember($verified);

            return back()->withErrors(['verify' => 'توقّف السؤال: '.$e->getMessage()]);
        }

        $this->remember($verified);
        $this->audit('aggregator_review_verified', ['asked' => $pending->count(), 'adopted' => $adopted]);

        $left = $review->rows($link, $verified)->reject(fn ($row) => array_key_exists($row['shipment']->id, $verified))->count();

        return back()->with('success', 'سُئل نظامهم عن '.Arabic::shipments($pending->count()).'.'
            .($adopted ? ' ووُجد عندهم ما لم يصلنا رقمه فرُبط: '.Arabic::shipments($adopted).'.' : '')
            .($left ? ' وبقي '.Arabic::shipments($left).': اضغط مرّةً أخرى.' : ''));
    }

    /** ينسى أجوبة نظامهم، فيُسأل من جديد */
    public function forgetReview(): RedirectResponse
    {
        Cache::forget($this->verifyKey());

        return back()->with('success', 'مُسحت أجوبة نظامهم.');
    }

    /** يرسل إلى نظامهم ما لم يصلهم: ما لم يُرسل أصلاً، وما رفضوه أو تعذّر (يُعاد بعد إصلاح سببه) */
    public function resendReview(AggregatorScanReview $review): RedirectResponse
    {
        $link = $this->link();

        if (! $link->sends_orders) {
            return back()->withErrors(['resend' => 'إرسال الطلبات إلى نظامهم مطفأ في إعداد الربط: شغّله أوّلاً.']);
        }

        $queued = $review->rows($link, $this->verified())
            ->filter(fn ($row) => array_intersect($row['problems'], AggregatorScanReview::SEND_PROBLEMS) !== [])
            ->filter(fn ($row) => AggregatorOutbox::resend($row['shipment']))
            ->count();

        $this->audit('aggregator_review_resent', ['queued' => $queued]);

        return back()->with('success', $queued
            ? 'تُرسل '.Arabic::shipments($queued).' خلال دقيقة. ما رفضوه لسببٍ (منطقةٍ غير مربوطة مثلاً) يُرفض ثانيةً حتى يُصلح.'
            : 'لا شيء يُرسل.');
    }

    /** @return array<int, bool> */
    private function verified(): array
    {
        return (array) Cache::get($this->verifyKey(), []);
    }

    /** @param  array<int, bool>  $verified */
    private function remember(array $verified): void
    {
        Cache::put($this->verifyKey(), $verified, now()->addHour());
    }

    private function verifyKey(): string
    {
        return 'aggregator-review-verified:'.$this->link()->company_id;
    }

    /**
     * جلب مناطق ١٨ مدينةٍ من نظامهم يطول على مهلة الطلب العادية (٣٠ ثانية). وفي الاختبارات لا يُمَسّ الحدّ:
     * set_time_limit يبدأ عدّاً جديداً للعملية كلّها فتموت مجموعةٌ طويلةٌ في وسطها.
     */
    private function longRequest(): void
    {
        if (! app()->runningUnitTests()) {
            @set_time_limit(300);
        }
    }

    private function link(): AggregatorLink
    {
        return AggregatorLink::query()->firstOrCreate([], ['base_url' => AggregatorLink::DEFAULT_BASE_URL]);
    }

    /** مناطق مندوبٍ وسيط لم تُربط بعد: محافظاتٌ مربوطة وفيها مناطق بلا منطقةٍ عندهم */
    private function unmappedCount(): int
    {
        $governorates = AggregatorPlace::query()->where('kind', 'governorate')->pluck('local_id')->flip();
        $done = AggregatorPlace::query()->where('kind', 'city')->pluck('local_id')->flip();

        return City::query()->get(['id', 'governorate_id'])
            ->filter(fn ($city) => isset($governorates[$city->governorate_id]) && ! isset($done[$city->id]))->count();
    }

    private function audit(string $action, array $values): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
            'action' => $action,
            'auditable_type' => AggregatorLink::class,
            'auditable_id' => $this->link()->id,
            'new_values' => $values,
            'ip' => request()->ip(),
        ]);
    }
}
