<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «طلب تغيير المبلغ» من المندوب عند الباب (docs/plan/30).
 *
 * المندوب لا يغيّر المبلغ: يسلّم بالأصلي، أو يفتح هذه التذكرة فتصل الكول سنتر
 * المختصّة بمحافظة الشحنة. تعتمد المبلغ الجديد أو ترفضه، والمندوب يرى الجواب
 * في صفحة الشحنة نفسها.
 *
 * نوعان: «السعر تغيّر» — يُعدَّل مبلغ الشحنة فور الاعتماد ويسلّم المندوب «واصل»؛
 * و«أخذ جزءاً وأرجع الباقي» — يُفتح للمندوب «واصل جزئي» بالمبلغ المعتمد وحده.
 */
class ShipmentTicket extends Model
{
    use BelongsToCompany;

    public const KINDS = [
        'price'   => 'السعر تغيّر',
        'partial' => 'أخذ جزءاً وأرجع الباقي',
    ];

    public const STATUSES = [
        'open'     => 'بانتظار الكول سنتر',
        'approved' => 'اعتُمد',
        'rejected' => 'رُفض',
        'closed'   => 'أُغلق',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'current_amount'   => 'integer',
            'requested_amount' => 'integer',
            'approved_amount'  => 'integer',
            'handled_at'       => 'datetime',
            'used_at'          => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('shipment_tickets.status', 'open');
    }

    /**
     * ما يراه $user: تذاكر محافظات اختصاصه — ومن لا محافظات له يرى كلّها — ومن
     * شحناتٍ يراها فرعه.
     */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if ($user === null) {
            return $q->whereRaw('1 = 0');
        }

        $governorates = $user->handledGovernorateIds();

        return $q
            ->when($governorates !== [], fn (Builder $w) => $w->whereIn('shipment_tickets.governorate_id', $governorates))
            ->when($user->isBranchLimited(), fn (Builder $w) => $w
                ->whereIn('shipment_tickets.shipment_id', Shipment::query()->visibleTo($user)->select('shipments.id')));
    }

    /** واصلٌ جزئيّ اعتُمد ولم يُسلَّم به بعد: يفتح للمندوب زرّه بمبلغه */
    public function scopeAwaitingPartial(Builder $q): Builder
    {
        return $q->where('shipment_tickets.kind', 'partial')
            ->where('shipment_tickets.status', 'approved')
            ->whereNull('shipment_tickets.used_at');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** تذكرةٌ من غير محافظات الموظّف أو فرعه لا تُفتح برقمها: 404 */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->visibleTo(auth()->user())
            ->first();
    }
}
