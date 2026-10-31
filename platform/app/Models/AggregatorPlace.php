<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** محافظةٌ عندنا بمدينةٍ عندهم (governorate)، ومنطقةٌ عندنا بمنطقةٍ عندهم (city) — docs/plan/104 */
class AggregatorPlace extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];
}
