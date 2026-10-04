<?php

namespace App\Actions\Accounts;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Username;

/**
 * اسم الدخول وكلمة المرور لحسابٍ قائم — مندوبٍ أو تاجرٍ أو موظّف — يغيّرهما من يديره.
 *
 * كلمة مرورٍ تتغيّر بيد غيره تعني غالباً هاتفاً ضاع أو موظّفاً ترك العمل، فيخرج الحساب
 * من أجهزته كلّها. والتغيير في سجلّ التدقيق باسم من غيّره، بلا كلمة المرور.
 */
class ChangeLogin
{
    /** @return bool هل تغيّر شيء */
    public function handle(User $account, ?string $username, ?string $password, User $actor): bool
    {
        $changes = [];

        $username = Username::normalise($username);
        if ($username !== null && $username !== $account->username) {
            $changes['username'] = $username;
        }

        if (filled($password)) {
            $changes['password'] = $password;
        }

        if ($changes === []) {
            return false;
        }

        $before = $account->username;
        $account->update($changes);

        if (isset($changes['password'])) {
            $account->endOtherSessions();
        }

        AuditLog::create([
            'user_id'        => $actor->id,
            'user_name'      => $actor->name,
            'action'         => 'login_changed',
            'auditable_type' => User::class,
            'auditable_id'   => $account->id,
            'old_values'     => ['username' => $before],
            'new_values'     => ['username' => $account->username, 'password_changed' => isset($changes['password'])],
            'ip'             => request()->ip(),
        ]);

        return true;
    }
}
