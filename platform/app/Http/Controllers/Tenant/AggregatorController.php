<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AggregatorEvent;
use App\Models\AggregatorJob;
use App\Models\AggregatorLink;
use App\Models\AggregatorPlace;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Services\Aggregator\AggregatorClient;
use App\Services\Aggregator\AggregatorException;
use App\Services\Aggregator\AggregatorOutbox;
use App\Services\Aggregator\PlaceMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «ربط الشركة الوسيطة» (docs/plan/104): حساب الشركة في نظامهم وتاجرها، والمندوب الوسيط عندنا،
 * والبادئة، ومفتاح الـ Webhook، وربط حالاتهم ومناطقهم، وسجلّ ما ذهب وما جاء. لصاحب «بيانات الشركة».
 */
class AggregatorController extends Controller
{
    public function edit(TenantContext $tenant): View
    {
        $link = $this->link();

        return view('tenant.aggregator.edit', [
            'link' => $link,
            'couriers' => Courier::query()->where('is_intermediary', true)->orderBy('name')->get(['id', 'name']),
            'reasons' => FailureReason::availableFor($tenant->id())->get(['id', 'name_ar', 'allows_reschedule']),
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
            'failure_reason_id' => ['nullable', 'integer', Rule::exists('failure_reasons', 'id')],
            'postpone_reason_id' => ['nullable', 'integer', Rule::exists('failure_reasons', 'id')],
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
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'postpone_reason_id' => $data['postpone_reason_id'] ?? null,
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

    public function areas(): View
    {
        $link = $this->link();
        $places = AggregatorPlace::query()->get()->groupBy('kind')->map->pluck('remote_id', 'local_id');

        return view('tenant.aggregator.areas', [
            'link' => $link,
            'governorates' => Governorate::query()->orderBy('id')->get(['id', 'name_ar']),
            'cities' => City::query()->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar'])->groupBy('governorate_id'),
            'theirCities' => $link->catalog['cities'] ?? [],
            'regions' => $link->catalog['regions'] ?? [],
            'mapped' => $places,
        ]);
    }

    public function saveAreas(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'governorate' => ['array'], 'governorate.*' => ['nullable', 'string', 'max:20'],
            'city' => ['array'], 'city.*' => ['nullable', 'string', 'max:20'],
        ]);

        $link = $this->link();
        $names = ['governorate' => $link->catalog['cities'] ?? [], 'city' => collect($link->catalog['regions'] ?? [])->flatMap(fn ($r) => $r)->all()];

        foreach (['governorate', 'city'] as $kind) {
            foreach ($data[$kind] ?? [] as $local => $remote) {
                if (blank($remote)) {
                    AggregatorPlace::query()->where('kind', $kind)->where('local_id', (int) $local)->delete();

                    continue;
                }

                AggregatorPlace::query()->updateOrCreate(
                    ['kind' => $kind, 'local_id' => (int) $local],
                    ['remote_id' => (string) $remote, 'remote_name' => $names[$kind][(string) $remote] ?? null],
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
        @set_time_limit(300);

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

                    AggregatorPlace::query()->create(['kind' => 'city', 'local_id' => $city->id, 'remote_id' => $remote, 'remote_name' => $regions[$remote]]);
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
     * يفحص ما رُبط على قوائمهم الحيّة: مدينةٌ أو منطقةٌ لم تعد عندهم، وأكثر من منطقةٍ عندنا على منطقةٍ واحدة
     * عندهم (قد يكون مقصوداً)، وما بقي بلا ربط — وهذا ما يُرفض عند الإرسال. لا يغيّر شيئاً.
     */
    public function verifyAreas(): RedirectResponse
    {
        @set_time_limit(300);

        $link = $this->link();
        $client = AggregatorClient::for($link);
        $catalog = $link->catalog ?? [];

        try {
            $catalog['cities'] = $client->cities();
            $governorates = AggregatorPlace::query()->where('kind', 'governorate')->get();
            $cities = AggregatorPlace::query()->where('kind', 'city')->get()->keyBy('local_id');
            $ours = City::query()->get(['id', 'governorate_id', 'name_ar'])->groupBy('governorate_id');
            $names = Governorate::query()->pluck('name_ar', 'id');

            $report = ['gone_cities' => [], 'gone_regions' => [], 'shared' => [], 'unmapped' => 0, 'ok' => 0, 'governorates_left' => $names->count() - $governorates->count()];

            foreach ($governorates as $place) {
                if (! isset($catalog['cities'][$place->remote_id])) {
                    $report['gone_cities'][] = $names[$place->local_id] ?? $place->local_id;

                    continue;
                }

                $catalog['regions'][$place->remote_id] = $regions = $client->regions((string) $place->remote_id);
                $used = [];

                foreach ($ours[$place->local_id] ?? [] as $city) {
                    $mapped = $cities[$city->id] ?? null;

                    if ($mapped === null) {
                        $report['unmapped']++;
                    } elseif (! isset($regions[$mapped->remote_id])) {
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

    private function link(): AggregatorLink
    {
        return AggregatorLink::query()->firstOrCreate([], ['base_url' => AggregatorLink::DEFAULT_BASE_URL]);
    }

    /** مناطق مندوبٍ وسيط لم تُربط بعد: محافظاتٌ مربوطة وفيها مناطق بلا منطقةٍ عندهم */
    private function unmappedCount(): int
    {
        $governorates = AggregatorPlace::query()->where('kind', 'governorate')->pluck('local_id');

        return City::query()->whereIn('governorate_id', $governorates)
            ->whereNotIn('id', AggregatorPlace::query()->where('kind', 'city')->pluck('local_id'))->count();
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
