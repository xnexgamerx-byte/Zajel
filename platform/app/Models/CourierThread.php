<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** محادثة المكتب مع مندوبٍ واحد (docs/plan/38). */
class CourierThread extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'staff_unread'    => 'boolean',
            'courier_unread'  => 'boolean',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class)->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CourierMessage::class)->orderBy('id');
    }

    /** فرعه فرعُ مندوبه — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'courier';
    }
}
