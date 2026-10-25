<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use App\Support\ReceiptCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «دفعة راجع»: رواجع تاجرٍ سُلّمت مرّةً واحدة بإيصالٍ واحد — من المخزن أو مع
 * مندوب الاستلام. الإيصال ما يوقّع عليه، والاستلام الفعليّ ما يُحسم به
 * «وين راجعي؟».
 */
class ReturnBatch extends Model
{
    use BelongsToCompany, SeenByBranch;

    public const VIA = [
        'store'          => 'من المخزن',
        'pickup_courier' => 'مع مندوب الاستلام',
    ];

    protected $guarded = ['id'];

    /** رمز الاستلام يُطبع على إيصال الراجع وحده: لا يخرج في JSON البوابة ولا التطبيق (docs/plan/69) */
    protected $hidden = ['receipt_code'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->receipt_code ??= ReceiptCode::generate());
    }

    /** رمز ورقته كما يُطبع — ويُولَّد لورقةٍ سبقت الرموز */
    public function receiptCode(): string
    {
        if (! $this->receipt_code) {
            $this->forceFill(['receipt_code' => ReceiptCode::generate()])->saveQuietly();
        }

        return $this->receipt_code;
    }

    protected function casts(): array
    {
        return ['handed_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** مندوب الاستلام الذي حملها، إن حملها مندوب */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function handedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_by_user_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** $merchant: للتاجر — والمندوب المخفيّ عنه لا يُسمّى (docs/plan/81) */
    public function viaLabel(bool $merchant = false): string
    {
        return $this->via === 'pickup_courier' && $this->courier
            ? 'مع '.($merchant && $this->courier->hiddenFromMerchants() ? 'مندوب الشركة' : $this->courier->name)
            : (self::VIA[$this->via] ?? $this->via);
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /** فرعه فرعُ تاجره — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'merchant';
    }
}
