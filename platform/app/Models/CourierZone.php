<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierZone extends Model
{
    use BelongsToCompany, SeenByBranch;

    protected $guarded = ['id'];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** فرعه فرعُ مندوبه — SeenByBranch */
    protected function branchThrough(): ?string
    {
        return 'courier';
    }
}
