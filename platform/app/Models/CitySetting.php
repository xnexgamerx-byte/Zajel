<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ما تعنيه منطقةٌ لشركةٍ بعينها: «طرفية» تُسعَّر بمبلغ الأطراف، و«أجرة
 * النقل الخاصّة بالمنطقة» إن كانت أبعد من أن تُعامَل كمحافظتها.
 */
class CitySetting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_peripheral' => 'boolean'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
