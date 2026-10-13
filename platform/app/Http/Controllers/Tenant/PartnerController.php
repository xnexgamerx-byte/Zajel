<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Partners\DecidePartnerDelivery;
use App\Actions\Partners\HandOverToPartner;
use App\Actions\Partners\ReceivePartnerReturns;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerKey;
use App\Models\PartnerReport;
use App\Models\Shipment;
use App\Support\Partners\PartnerPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الشركاء الموزّعون ومفاتيح ربطهم (docs/plan/64): نُسلّم لهم الطرود، ونرى أين صارت،
 * ونراجع تقارير تسليمهم قبل أن تُحسب.
 */
class PartnerController extends Controller
{
    public function index(): View
    {
        return view('tenant.partners.index', [
            'partners' => Partner::query()->withCount([
                'shipments as held_count' => fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, PartnerPolicy::HELD)),
                'keys as active_keys_count' => fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now()),
            ])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], [], ['name' => 'اسم الشريك']);

        $partner = Partner::create(['name' => trim($data['name']), 'status' => 'active']);

        return redirect()->route('partners.show', $partner)->with('success', 'أُضيف الشريك. أنشئ له مفتاح ربطه أدناه.');
    }

    public function show(Partner $partner): View
    {
        return view('tenant.partners.show', [
            'partner' => $partner->load('keys'),
            'held' => Shipment::query()->where('partner_id', $partner->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, PartnerPolicy::HELD))
                ->with(['governorate:id,name_ar'])
                ->orderByDesc('status_changed_at')->limit(200)->get(),
            'reports' => PartnerReport::query()->pending()
                ->whereHas('key', fn ($q) => $q->where('partner_id', $partner->id))
                ->with(['shipment:id,number,status,recipient_name,partner_id', 'key:id,name'])
                ->orderBy('reported_at')->get(),
            'scopes' => PartnerKey::ABILITIES,
        ]);
    }

    public function storeKey(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', PartnerKey::ABILITIES)],
            'expires_at' => ['required', 'date', 'after:today', 'before:'.now()->addYears(2)->toDateString()],
            'ips' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'اسم المفتاح', 'expires_at' => 'تاريخ الانتهاء']);

        $ips = collect(preg_split('/[\s,،]+/u', (string) ($data['ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP))->values()->all();

        $issued = PartnerKey::issue(
            $partner,
            trim($data['name']),
            $data['abilities'],
            now()->parse($data['expires_at'])->endOfDay(),
            $ips ?: null,
            $request->user(),
        );

        // المفتاح كاملاً يظهر مرّةً واحدة هنا، ولا يُسترجع بعدها
        return redirect()->route('partners.show', $partner)
            ->with('success', 'أُنشئ المفتاح. انسخه الآن: لن يظهر كاملاً مرّةً أخرى.')
            ->with('partner_token', $issued['token']);
    }

    public function revokeKey(Partner $partner, PartnerKey $key): RedirectResponse
    {
        abort_unless($key->partner_id === $partner->id, 404);

        $key->revoke();

        return back()->with('success', 'أُلغي المفتاح «'.$key->name.'». أي نظامٍ يستعمله يُرفض من الآن.');
    }

    /** نسلّم الطرود للشريك: من «بالمخزن» فقط */
    public function handover(Request $request, Partner $partner, HandOverToPartner $handover): RedirectResponse
    {
        $ids = $this->shipmentIds($request);

        $result = $handover->handle($partner, $ids, $request->user());

        return back()->with('handover', $result)
            ->with(count($result['moved']) ? 'success' : 'error', count($result['moved']).' طرد سُلّم إلى '.$partner->name.'.');
    }

    /** الراجع يعود من الشريك إلينا: نستلمه بسجلّه ونقطع صلته به */
    public function receiveBack(Request $request, Partner $partner, ReceivePartnerReturns $receive): RedirectResponse
    {
        $ids = $this->shipmentIds($request);

        $done = $receive->handle($partner, $ids, $request->user());

        return back()->with(count($done) ? 'success' : 'error', count($done) ? 'استُلم '.count($done).' راجع من '.$partner->name.'.' : 'لا راجع من هذا الشريك بين الأرقام.');
    }

    public function approve(PartnerReport $report, Request $request, DecidePartnerDelivery $decide): RedirectResponse
    {
        $decide->approve($report, $request->user(), $request->input('note'));

        return back()->with('success', 'وافقنا على تسليم '.$report->shipment->number.'، وحُسب ماله.');
    }

    public function reject(PartnerReport $report, Request $request, DecidePartnerDelivery $decide): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], [], ['note' => 'سبب الرفض']);

        $decide->reject($report, $request->user(), $data['note']);

        return back()->with('success', 'رُفض تقرير '.$report->shipment->number.'. يبقى الطرد مع الشريك حتى يُعيد إرسال الحالة.');
    }

    /** @return list<int> */
    private function shipmentIds(Request $request): array
    {
        $numbers = collect(preg_split('/[\s,،]+/u', (string) $request->input('numbers', ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($n) => strtoupper(trim((string) $n)))->unique()->take(500)->values();

        abort_if($numbers->isEmpty(), 422, 'اكتب رقم الشحنة أو أكثر.');

        return Shipment::query()->visibleTo($request->user())->whereIn('number', $numbers->all())->pluck('id')->all();
    }
}
