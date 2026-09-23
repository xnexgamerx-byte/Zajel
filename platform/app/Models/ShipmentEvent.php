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
        'return_received'  => 'استلام راجع',
        'return_arrived'   => 'وصول راجع لفرع',
        'return_sorted'    => 'فرز راجع لفرع',
        'bagged'           => 'إضافة لكيس',
        'unbagged'         => 'إخراج من كيس',
        'bag_missing'      => 'ناقص من كيس',
    ];

    /** @var array<string, string> */
    public const ACTORS = [
        'user'     => 'موظّف',
        'courier'  => 'مندوب',
        'merchant' => 'تاجر',
        'api'      => 'واجهة برمجية',
        'system'   => 'النظام',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->event_type] ?? $this->event_type;
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
