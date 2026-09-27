<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «إعدادات المحافظة» لشركةٍ بعينها: تشحن إليها أم لا، وأين تظهر في
 * القوائم، وكم يأخذ المندوب إلى مركزها وإلى أطرافها.
 */
class GovernorateSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }
}
