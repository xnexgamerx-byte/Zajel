<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToCompany, SeenByBranch {
        scopeVisibleTo as protected scopeVisibleToBranch;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'staff_unread'    => 'boolean',
            'merchant_unread' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** الكرة عندنا: كتب التاجر آخر سطر والمحادثة مفتوحة. */
    public function awaitsUs(): bool
    {
        return $this->isOpen() && $this->last_author === 'merchant';
    }

    /**
     * ما يراه الموظّف: ما لفرعه (SeenByBranch)، ومحادثةٌ عن شحنةٍ لموظّفة محافظتها
     * وحدها (docs/plan/30). والعامّة — لا شحنة فيها: حسابٌ أو دفعة — لكل الموظّفين،
     * لا تضيع لأن تاجرها في محافظةٍ بلا موظّفة.
     */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        $this->scopeVisibleToBranch($q, $user);

        $governorates = $user?->handledGovernorateIds() ?? [];

        return $governorates === [] ? $q : $q->where(fn (Builder $w) => $w
            ->whereNull('conversations.shipment_id')
            ->orWhereIn('conversations.shipment_id', Shipment::withTrashed()
                ->whereIn('shipments.governorate_id', $governorates)->select('shipments.id')));
    }

    /** فرعه فرعُ تاجره — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'merchant';
    }
}
