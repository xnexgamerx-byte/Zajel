<?php

namespace App\Models;

use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Governorate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * بترتيب الشركة الحاليّة إن رتّبتها، وإلّا بالترتيب العامّ. بلا سياق شركة
     * (المنصّة) يبقى العامّ وحده.
     */
    public function scopeOrderedForCompany(Builder $q): Builder
    {
        if (($company = Tenancy::id()) === null) {
            return $q->orderBy('governorates.sort_order');
        }

        return $q
            ->orderByRaw('coalesce((select gs.sort_order from governorate_settings gs
                where gs.governorate_id = governorates.id and gs.company_id = ?), governorates.sort_order)', [$company])
            ->orderBy('governorates.sort_order');
    }

    /** ما تشحن إليه الشركة: نشطةٌ عامّةً ولم تُطفئها في إعداداتها — بترتيبها */
    public function scopeOffered(Builder $q): Builder
    {
        $q->where('governorates.is_active', true)->orderedForCompany();

        if (($company = Tenancy::id()) !== null) {
            $q->whereNotIn('governorates.id', DB::table('governorate_settings')
                ->where('company_id', $company)->where('is_active', false)->select('governorate_id'));
        }

        return $q;
    }

    public function getNameAttribute(): string
    {
        return $this->name_ar;
    }
}
