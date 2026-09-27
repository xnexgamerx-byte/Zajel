<?php

namespace App\Support\Permissions;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * كل تغييرٍ في الصلاحيات يمرّ من هنا: يُجرى، ثم يُسأل «أبقي أحدٌ يدير
 * الصلاحيات؟» — وإلّا يُتراجع عنه كلّه.
 *
 * فالسؤال بعد التغيير لا قبله: مرتبةٌ تُعدَّل تمسّ كل من يحملها، واستثنائيةٌ
 * تُسحب، وموظّفٌ تتغيّر مرتبته — وحساب «من يبقى» لكلٍّ منها على حدة يُخطئ
 * حالةً ما. أمّا بعد التغيير فالجواب من الصلاحيات نفسها. والشركة التي تُقفَل
 * عن إدارة صلاحياتها لا طريق لها للعودة إلّا من قاعدة البيانات.
 */
final class PermissionChange
{
    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function apply(callable $change, string $field = 'abilities')
    {
        return DB::transaction(function () use ($change, $field) {
            $result = $change();

            if (! static::someoneManagesPermissions()) {
                throw ValidationException::withMessages([
                    $field => 'بهذا لا يبقى أحدٌ يدير الصلاحيات. امنحها لمستخدمٍ آخر أولاً.',
                ]);
            }

            return $result;
        });
    }

    public static function someoneManagesPermissions(): bool
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotIn('role', [UserRole::Merchant, UserRole::Courier])
            ->with(['rank', 'grants'])
            ->get()
            ->contains(fn (User $user) => $user->hasAbility(Ability::SETTINGS_PERMISSIONS));
    }

    /** في سجلّ التدقيق، ومنه «تتبّع التغييرات». */
    public static function audit(string $action, ?object $subject, ?array $old, ?array $new): void
    {
        $actor = request()->user();

        AuditLog::create([
            'user_id'        => $actor?->id,
            'user_name'      => $actor?->name,
            'action'         => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id'   => $subject?->id,
            'old_values'     => $old,
            'new_values'     => $new,
            'ip'             => request()->ip(),
        ]);
    }
}
