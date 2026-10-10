<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\FitsColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ إضافة فقط. لا updated_at، ولا تعديل، ولا حذف —
 * وعليه يُبنى تتبّع الزبون وتقارير الأداء ومطابقة الحسابات.
 */
class ShipmentEvent extends Model
{
    use AppendOnly, BelongsToCompany, FitsColumns;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** نصٌّ مُركَّب يُقصّ على عموده بدل أن يُسقط الحفظ — FitsColumns */
    protected array $fits = ['note' => 500];

    /**
     * أنواع الأحداث — مصدرٌ واحد لا قائمتان.
     *
     * كانت الأنواع سلاسل حرفية متناثرة في الأفعال، وشاشةُ التتبّع تحمل
     * قائمتها الخاصّة. فاختلفتا: القائمة تعرض «مسح» و«إسناد» ولا وجود
     * لهما، وتُخفي الكيس والراجع وتأكيد المبلغ وهي تُكتب فعلاً. وحارسٌ
     * في الاختبارات يقارن هذه بما تكتبه الأفعال حتى لا تفترقا ثانيةً.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'status_change'    => 'تغيير حالة',
        'forced_status'    => 'حالة إجبارية',
        'money'            => 'مالي',
        'amount_confirmed' => 'تأكيد مبلغ',
        'edited'           => 'تعديل بيانات',
        'return_received'  => 'استلام راجع',
        'return_arrived'   => 'وصول راجع لفرع',
        'return_sorted'    => 'فرز راجع لفرع',
        'return_departed'  => 'خروج راجع إلى فرعه',
        'return_confirmed' => 'تأكيد استلام راجع',
        'bagged'           => 'إضافة لكيس',
        'unbagged'         => 'إخراج من كيس',
        'bag_missing'      => 'ناقص من كيس',
        'shipment_missing' => 'لم يصل بين الفروع',
        'deleted'          => 'مسح',
        'restored'         => 'استرجاع من الممسوحة',
        'processed'        => 'معالجة',
        'merchant_asked'   => 'رسالة للتاجر قبل المعالجة',
        'reviewed'         => 'إجازة بعد المراجعة',
        'prepaid_fee'      => 'قبض أجرة مقدّماً',
        'ticket'           => 'طلب تغيير المبلغ',
    ];

    /**
     * ما يراه التاجر في مسار شحنته: تغيّرات الحالة، وخطوات راجعه حتى يصل يده —
     * لا الأكياس ولا المال ولا التعديل. كان يرى الحالات وحدها، فراجعٌ استُلم
     * وفُرز وسافر إلى فرعه ظهر عنده «راجع» ثابتاً أيّاماً حتى سُلِّم.
     */
    public const MERCHANT_EVENTS = [
        'status_change', 'forced_status',
        'return_received', 'return_sorted', 'return_departed', 'return_arrived', 'return_confirmed',
    ];

    /** خطوات الراجع بكلام التاجر: بلا أرقام أكياسنا وكشوفنا */
    private const MERCHANT_WORDING = [
        'return_received'  => 'وصل الراجع مخزننا من المندوب',
        'return_sorted'    => 'جُهِّز الراجع للإرسال إلى فرعك',
        'return_departed'  => 'الراجع في الطريق إلى فرعك',
        'return_arrived'   => 'وصل الراجع فرعك — جاهزٌ للتسليم',
        'return_confirmed' => 'تأكّد استلامك للراجع',
    ];

    /** @var array<string, string> */
    public const ACTORS = [
        'user'     => 'موظّف',
        'courier'  => 'مندوب',
        'merchant' => 'تاجر',
        'api'      => 'واجهة برمجية',
        'system'   => 'النظام',
    ];

    /**
     * ما يُكتب في to_status وليس حالةَ شحنة: علاماتٌ على حدثٍ ماليّ.
     * كانت تظهر في سجلّ الشحنة كما خُزِّنت («settled_with_courier»).
     */
    public const MARKERS = [
        'settled_with_courier'  => 'سُوّيت مع المندوب',
        'settled_with_merchant' => 'سُوّيت مع التاجر',
        'settlement_cancelled'  => 'أُلغي كشفها',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->event_type] ?? $this->event_type;
    }

    /** عنوان الحدث في السجلّ: اسم الحالة، أو اسم العلامة، بالعربية. */
    public function toLabel(): string
    {
        return \App\Enums\ShipmentStatus::tryFrom((string) $this->to_status)?->label()
            ?? self::MARKERS[$this->to_status]
            ?? (string) $this->to_status;
    }

    /**
     * عنوان السطر في سجلّ الشحنة: الحالة التي صارت إليها، أو — لحدثٍ لا يغيّرها
     * (استلام راجع، تعديل، كيس، تأكيد مبلغ…) — اسمُ الحدث نفسه.
     *
     * كانت كل الأحداث تُعنوَن بالحالة، فشحنةٌ رجعت مرّةً واحدة ظهرت «قيد الإرجاع»
     * مرّتين في سجلّها، والمعدَّلة ثلاثاً تكرّرت حالتها أربعاً.
     */
    public function headline(): string
    {
        $keepsStatus = $this->from_status === $this->to_status
            && ! in_array($this->event_type, ['status_change', 'forced_status'], true);

        return $keepsStatus ? $this->typeLabel() : $this->toLabel();
    }

    /** عنوان السطر في مسار الشحنة عند التاجر */
    public function merchantHeadline(): string
    {
        return self::MERCHANT_WORDING[$this->event_type] ?? $this->toLabel();
    }

    /** ملاحظة السطر عند التاجر: في تغيّر الحالة وحده — سبب الإجبار وملاحظات المخزن داخلية */
    public function merchantNote(): ?string
    {
        return $this->event_type === 'status_change' ? $this->note : null;
    }

    public function actorLabel(): string
    {
        return self::ACTORS[$this->actor_type] ?? $this->actor_type;
    }

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function failureReason(): BelongsTo
    {
        return $this->belongsTo(FailureReason::class);
    }
}
