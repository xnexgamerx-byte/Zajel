<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\ProcessFailedAttempt;
use App\Actions\Support\Converse;
use App\Actions\Support\CourierChat;
use App\Enums\Feature;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Shipments\ShipmentFilters;
use App\Support\FeatureGate;
use App\Support\MerchantMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «شحنات للمعالجة» كما في المعتاد: المحاولة الفاشلة لا تعود إلى المندوب
 * تلقائياً — يتّصل موظّف المتابعة بالزبون ويقرّر: إعادة توصيل، أو تأجيلٌ إلى
 * موعدٍ اتُّفق عليه، أو إرجاعٌ للتاجر. والقرار يُسجَّل بمن اتّخذه وبعد كم
 * انتظرت الشحنة، ومنه تُقرأ «موظّفو المتابعة» و«أداء المراجعة».
 *
 * وكل موظّفة كول سنتر تعالج شحنات محافظات اختصاصها وحدها (docs/plan/30): معالجة
 * بغداد لا تصل موظّفة البصرة، ولا تُعالَج منها برقمها.
 */
class ProcessingController extends Controller
{
    public const ACTIONS = ProcessFailedAttempt::ACTIONS;

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'done' ? 'done' : 'pending';

        $pending = Shipment::query()
            ->visibleTo($request->user())
            ->inGovernoratesOf($request->user())
            ->where('shipments.status', ShipmentStatus::FailedAttempt->value);

        // «الزبون اتّصل بخصوص الوصل كذا»: البحث برقم الوصل أو هاتفه، ومناديب
        // الواحد بعينه — والشارة تعدّ كل ما ينتظر لا ما وافق البحث
        $found = ShipmentFilters::apply(clone $pending, $request);

        $mine = $request->user()->handledGovernorateIds();

        $page = $tab === 'pending'
            ? $found->select('shipments.*')->with(['merchant:id,business_name,phone', 'governorate:id,name_ar', 'city:id,name_ar',
                    'deliveryCourier:id,name,phone', 'lastFailureReason:id,name_ar'])
                ->withMax(['events as asked_at' => fn ($q) => $q->where('event_type', 'merchant_asked')
                    ->whereColumn('shipment_events.created_at', '>=', 'shipments.status_changed_at')], 'created_at')
                // من كتب أوّلاً (docs/plan/43): تاجرٌ ردّ عن الشحنة، أو مندوبٌ كتب عنها، أو تذكرةٌ مفتوحة لها
                ->selectRaw(self::WAITING_ON_US.' as waiting_on_us')
                ->orderByDesc('waiting_on_us')
                ->orderBy('shipments.status_changed_at')
                ->paginate(config('zajel.per_page'))
                ->withQueryString()
            : null;
        $canChat = FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply');

        return view('tenant.processing.index', [
            'canChat'   => $canChat,
            'chats'     => $page && $canChat ? $this->chats($page->getCollection()) : ['merchant' => collect(), 'courier' => collect()],
            'tab'       => $tab,
            'canAsk'    => FeatureGate::enabled(Feature::Conversations),
            'governorates' => $mine === [] ? collect()
                : Governorate::query()->whereIn('id', $mine)->orderedForCompany()->pluck('name_ar'),
            'pendingCount' => (clone $pending)->count(),
            'filtered'  => $request->filled('q') || $request->filled('courier_id'),
            'couriers'  => $tab === 'pending' ? Courier::delivering()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']) : collect(),
            'shipments' => $page,
            // ما عولج في الأيام السبعة الأخيرة: القرار ومن اتّخذه وبعد كم
            'done'      => $tab === 'done'
                ? ShipmentEvent::query()
                    ->where('event_type', 'processed')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('shipment_id', Shipment::query()->visibleTo($request->user())
                        ->inGovernoratesOf($request->user())->select('shipments.id'))
                    ->with('shipment:id,number,status,merchant_id', 'shipment.merchant:id,business_name')
                    ->latest('id')
                    ->paginate(config('zajel.per_page'))
                    ->withQueryString()
                : null,
        ]);
    }

    /**
     * شحنةٌ كتب عنها أحدٌ ينتظر ردّنا — تُقدَّم في القائمة: محادثة التاجر عنها وآخر سطرٍ منه، أو
     * سطرٌ من المندوب عنها في محادثته ولم نقرأها، أو تذكرة تغيير مبلغٍ مفتوحة.
     */
    private const WAITING_ON_US = "(exists (select 1 from conversations c where c.shipment_id = shipments.id and c.staff_unread = 1)
        or exists (select 1 from courier_messages m join courier_threads t on t.id = m.courier_thread_id
            where m.shipment_id = shipments.id and m.author = 'courier' and t.staff_unread = 1)
        or exists (select 1 from shipment_tickets k where k.shipment_id = shipments.id and k.status = 'open'))";

    /**
     * محادثتا الشحنة في صفّها (docs/plan/43): محادثة التاجر عنها، ومحادثة مندوبها — تُقرأ ويُردّ
     * عليها من شاشة المعالجة نفسها، لا من شاشةٍ أخرى.
     *
     * @param  \Illuminate\Support\Collection<int, Shipment>  $shipments
     * @return array{merchant: \Illuminate\Support\Collection, courier: \Illuminate\Support\Collection}
     */
    private function chats($shipments): array
    {
        $merchant = Conversation::query()
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->with(['messages' => fn ($q) => $q->latest('id')->limit(30)])
            ->latest('id')->get()->unique('shipment_id')->keyBy('shipment_id');

        $courier = \App\Models\CourierThread::query()
            ->whereIn('courier_id', $shipments->pluck('delivery_courier_id')->filter()->unique())
            ->with(['messages' => fn ($q) => $q->with('shipment:id,number')->latest('id')->limit(30)])
            ->get()->keyBy('courier_id');

        return ['merchant' => $merchant, 'courier' => $courier];
    }

    /** ردٌّ على التاجر من صفّ الشحنة: في محادثتها، أو محادثةٌ جديدة عنها */
    public function merchantChat(Request $request, Shipment $shipment, Converse $converse): RedirectResponse
    {
        abort_unless($shipment->inGovernoratesOf($request->user()) && $shipment->merchant, 404);
        abort_unless(FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply'), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الرسالة']);

        $open = Conversation::query()->where('shipment_id', $shipment->id)->latest('id')->first();

        if ($open) {
            $converse->reply($open, $data['body'], $request->user(), Converse::STAFF);
            $converse->markRead($open->refresh(), Converse::STAFF);
        } else {
            $converse->start($shipment->merchant, "بخصوص الوصل {$shipment->number}", $data['body'], $request->user(), Converse::STAFF, $shipment->number);
        }

        return $this->backToRow($shipment, 'merchant');
    }

    /** ردٌّ على المندوب من صفّ الشحنة: في محادثته، والسطر يحمل رقم الشحنة */
    public function courierChat(Request $request, Shipment $shipment, CourierChat $chat): RedirectResponse
    {
        abort_unless($shipment->inGovernoratesOf($request->user()) && $shipment->deliveryCourier, 404);
        abort_unless(FeatureGate::enabled(Feature::Conversations) && $request->user()->can('support.reply'), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'الرسالة']);

        $chat->send($shipment->deliveryCourier, $data['body'], $request->user(), CourierChat::STAFF, $shipment);
        $chat->markRead($chat->thread($shipment->deliveryCourier)->refresh(), CourierChat::STAFF);

        return $this->backToRow($shipment, 'courier');
    }

    /** إلى الشاشة نفسها والصفّ نفسه، والمحادثة مفتوحة */
    private function backToRow(Shipment $shipment, string $chat): RedirectResponse
    {
        return redirect()->to(url()->previous().'#row-'.$shipment->id)->with('open_chat', "{$chat}-{$shipment->id}");
    }

    public function store(Request $request, Shipment $shipment, ProcessFailedAttempt $process): RedirectResponse
    {
        // معالجة محافظةٍ أخرى لموظّفتها: لا تُفتح برقم الشحنة
        abort_unless($shipment->inGovernoratesOf($request->user()), 404);

        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'until'  => ['nullable', 'required_if:action,postpone', 'date', 'after_or_equal:today', 'before:+60 days'],
            'note'   => ['nullable', 'string', 'max:255'],
        ], [
            'until.required_if' => 'التأجيل إلى متى؟ اختر اليوم الذي اتُّفق عليه مع الزبون.',
            'until.after_or_equal' => 'موعد التأجيل اليوم أو بعده.',
        ], ['until' => 'موعد التأجيل', 'note' => 'ما قاله الزبون']);

        $process->handle($shipment, $data['action'], $request->user(), 'staff', $data['until'] ?? null,
            $data['note'] ?? null, $request->ip());

        return back()->with('success', "عولجت {$shipment->number}: ".self::ACTIONS[$data['action']]
            .($data['action'] === 'postpone' ? ' إلى '.$data['until'] : '').'.');
    }

    /** «رسالتي الثابتة للتاجر» في قائمة المتابعة (docs/plan/41) */
    public function editMessage(): View
    {
        return view('tenant.processing.message');
    }

    /** الرسالة الثابتة للتاجر: نصّ الموظّف نفسه، وفارغاً يعود القالب (docs/plan/41) */
    public function message(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_message' => ['nullable', 'string', 'max:'.MerchantMessage::MAX],
        ], [], ['merchant_message' => 'الرسالة']);

        $text = trim((string) ($data['merchant_message'] ?? ''));
        // القالب نفسه لا يُحفظ نسخةً: يبقى يتبع ما يأتي مع النظام
        $request->user()->update(['merchant_message' => $text === '' || $request->boolean('reset')
            || $text === MerchantMessage::TEMPLATE ? null : $text]);

        return back()->with('success', $request->boolean('reset') || $text === '' ? 'عادت رسالتك إلى القالب.' : 'حُفظت رسالتك للتاجر.');
    }

    /**
     * «أرسل للتاجر» بلا قرار (docs/plan/41): رسالة الموظّف الثابتة تصل التاجر في «المحادثات» عن
     * هذه الشحنة، وتبقى الشحنة في المعالجة حتى يردّ ثم يُتّخذ القرار. ويُكتب في سجلّها متى سُئل.
     */
    public function ask(Request $request, Shipment $shipment, Converse $converse): RedirectResponse
    {
        abort_unless($shipment->inGovernoratesOf($request->user()), 404);

        if (! FeatureGate::enabled(Feature::Conversations)) {
            return back()->withErrors(['action' => 'المحادثات مع التجّار غير مفعّلة لشركتك — أرسلها بواتساب التاجر.']);
        }

        abort_unless($shipment->status === ShipmentStatus::FailedAttempt && $shipment->merchant, 404);

        $user = $request->user();
        $body = MerchantMessage::for($shipment->loadMissing(['merchant', 'governorate', 'city', 'lastFailureReason', 'deliveryCourier']), $user);

        $conversation = DB::transaction(function () use ($shipment, $user, $body, $converse) {
            $open = Conversation::query()->where('merchant_id', $shipment->merchant_id)
                ->where('shipment_id', $shipment->id)->where('status', 'open')->latest('id')->first();

            if ($open) {
                $converse->reply($open, $body, $user, Converse::STAFF);
            } else {
                $open = $converse->start($shipment->merchant, "بخصوص الوصل {$shipment->number}", $body, $user, Converse::STAFF, $shipment->number);
            }

            ShipmentEvent::create([
                'shipment_id' => $shipment->id,
                'from_status' => $shipment->status->value,
                'to_status'   => $shipment->status->value,
                'event_type'  => 'merchant_asked',
                'actor_type'  => 'user',
                'actor_id'    => $user->id,
                'actor_name'  => $user->name,
                'courier_id'  => $shipment->delivery_courier_id,
                'note'        => 'أُرسلت للتاجر رسالةٌ قبل المعالجة',
                'meta'        => ['conversation_id' => $open->id],
            ]);

            return $open;
        });

        return back()->with('success', "أُرسلت الرسالة إلى {$shipment->merchant->business_name} عن {$shipment->number}. "
            .'ردّه يصل «المحادثات»، والشحنة باقيةٌ هنا حتى تقرّر.');
    }
}
