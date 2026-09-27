<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «إعلانات الصفحة الرئيسية بالتطبيق»: صورةٌ بعنوانها ورابطها، تظهر أعلى بوابة
 * التاجر أو تطبيق المندوب بترتيبها. الصورة في التخزين الخاصّ وتُقدَّم لمستخدمي
 * الشركة وحدهم.
 */
class AppAd extends Model
{
    use BelongsToCompany;

    public const AUDIENCES = [
        'merchants' => 'التجّار',
        'couriers'  => 'المناديب',
        'all'       => 'الجميع',
    ];

    /** «يظهر للتجّار»: اللام مع «ال» لامان لا «لـال» */
    public const SHOWN_TO = [
        'merchants' => 'للتجّار',
        'couriers'  => 'للمناديب',
        'all'       => 'للجميع',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** ما يُعرض لجمهورٍ بعينه، بترتيبه */
    public function scopeShownTo(Builder $q, string $audience): Builder
    {
        return $q->where('is_active', true)
            ->whereIn('audience', [$audience, 'all'])
            ->orderBy('sort_order')->orderByDesc('id');
    }
}
