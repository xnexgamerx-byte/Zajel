<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * مفتاح ربطٍ لمندوبٍ وسيط (docs/plan/64): يُعطى لنظام الشركة الوسيطة، لا لشخصٍ ولا لكلمة مرور.
 *
 * - المفتاح يظهر مرّةً واحدة عند إنشائه، ويُخزَّن مُجزّأً (SHA-256) فلا يُسترجع من القاعدة.
 * - الصلاحيات محدودة بقائمةٍ مغلقة (ABILITIES): ما يفعله المندوب بشحنةٍ بيده، ولا شيء غيره.
 * - له انتهاء، وإلغاءٌ فوري، وقائمة عناوين IP اختيارية.
 */
class CourierApiKey extends Model
{
    use BelongsToCompany;

    public const READ = 'shipments.read';

    public const STATUS = 'shipments.status';

    /** كل ما يُمنح لمفتاح ربط — لا يُضاف إليها شيء إلّا بقرارٍ مكتوب */
    public const ABILITIES = [
        self::READ => 'قراءة شحناته',
        self::STATUS => 'تسجيل الحالة والتتبّع',
    ];

    public const PREFIX = 'zjp_';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * يُنشئ مفتاحاً ويُرجع المفتاح الكامل مرّةً واحدة. الصلاحيات تُقصر على القائمة المغلقة.
     *
     * @param  list<string>  $abilities
     * @param  list<string>|null  $ips
     * @return array{key: self, token: string}
     */
    public static function issue(Courier $courier, string $name, array $abilities, CarbonInterface $expires, ?array $ips, ?User $by): array
    {
        $abilities = array_values(array_intersect(array_keys(self::ABILITIES), $abilities));
        abort_if($abilities === [], 422, 'اختر صلاحيةً واحدةً على الأقل.');

        $token = self::PREFIX.Str::random(40);

        $key = self::create([
            'courier_id' => $courier->id,
            'name' => $name,
            'token_hash' => self::hash($token),
            'token_hint' => substr($token, -6),
            'abilities' => $abilities,
            'allowed_ips' => $ips ? array_values(array_unique($ips)) : null,
            'expires_at' => $expires,
            'created_by_user_id' => $by?->id,
        ]);

        return ['key' => $key, 'token' => $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** المفتاح الذي يطابق الرمز في شركة الطلب الحالية (النطاق العام للشركة)، أو null */
    public static function findByToken(string $token): ?self
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 100) {
            return null;
        }

        return self::query()->with('courier')->where('token_hash', self::hash($token))->first();
    }

    /** صالحٌ الآن: غير ملغى ولا منتهٍ، ومندوبه وسيطٌ نشط لم يُحذف */
    public function isUsable(?CarbonInterface $at = null): bool
    {
        $at ??= now();
        $courier = $this->courier;

        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->gt($at)
            && $courier !== null
            && $courier->is_intermediary
            && $courier->status === 'active';
    }

    public function isDead(): bool
    {
        return $this->revoked_at !== null || $this->expires_at === null || $this->expires_at->isPast();
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function allowsIp(?string $ip): bool
    {
        return empty($this->allowed_ips) || ($ip !== null && in_array($ip, $this->allowed_ips, true));
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
