<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعدادات المنصّة نفسها، مفتاحاً وقيمة (docs/plan/36): ما تضبطه من «إعدادات المنصّة» في
 * لوحتها بلا نشرٍ جديد. لا شركة لها: تُقرأ من النواة ومن نظام كل شركة.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
