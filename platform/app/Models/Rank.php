<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Permissions\Ability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مرتبةٌ تسمّيها الشركة («محاسب رئيسي»، «موظّف رواجع») بما تفتحه من صلاحيات.
 * الموظّف الذي يحملها ينال صلاحياتها بدل افتراضي دوره — انظر User::abilities().
 */
class Rank extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return array<int, string> ما هو صلاحيةٌ فعلاً، بترتيب القوائم */
    public function abilityList(): array
    {
        return Ability::ordered($this->abilities ?? []);
    }
}
