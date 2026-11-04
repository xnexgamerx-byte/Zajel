<?php

namespace App\Support;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;

/**
 * حسابات الدخول وأرقامها في الشركة (docs/plan/116): من يحمل رقماً أو اسم مستخدم، وبأيّ اسمٍ يُدخل حسابٌ جديد.
 *
 * الرقم يحمله حسابٌ واحد في الشركة (التاجر والمندوب والموظّف وموظّف التاجر سواء)، إلّا ما فتحته المنصّة: «رقمٌ واحد
 * لأكثر من تاجر» (Feature::SharedPhones) — فيُسجَّل التاجر برقمٍ يحمله غيره، ويُدخل حسابه باسم مستخدمٍ غير رقمه.
 * واسم المستخدم لا يتكرّر أبداً: به يُعرف الحساب عند الدخول.
 *
 * وحين يُرفض رقمٌ يقال من يحمله، فلا يقال «مستخدم» عن رقمٍ لا يراه الموظّف في قائمة التجّار. والحساب المحذوف
 * لا يحجز رقماً ولا اسماً: يُفرَغان منه كما يُفرَغ موظّف التاجر المحذوف.
 */
final class LoginAccounts
{
    public static function sharedPhones(): bool
    {
        return FeatureGate::enabled(Feature::SharedPhones);
    }

    /** الحساب القائم في الشركة الذي يحمل هذه القيمة (phone أو username)، وnull إن لم يحملها أحد */
    public static function holder(string $column, ?string $value, ?int $except = null): ?User
    {
        if (blank($value)) {
            return null;
        }

        return User::where($column, $value)->when($except, fn ($q) => $q->whereKeyNot($except))->orderBy('id')->first();
    }

    /** من يحمل الرقم، بكلام الموظّف: «حساب المندوب «أحمد حسين»» */
    public static function describe(User $user): string
    {
        return match ($user->role) {
            UserRole::Merchant => ($user->merchant_permissions === null ? 'حساب دخول التاجر' : 'موظّف التاجر')
                .' «'.(Merchant::whereKey($user->merchant_id)->value('business_name') ?? $user->name).'»',
            UserRole::Courier => 'حساب المندوب «'.(Courier::whereKey($user->courier_id)->value('name') ?? $user->name).'»',
            default => 'حساب الموظّف «'.$user->name.'» ('.$user->role->label().')',
        };
    }

    /** لماذا لا يُسجَّل هذا الرقم لحساب الدخول، أو null إن كان حرّاً (أو مفتوحاً تكراره لهذا التاجر) */
    public static function phoneProblem(?string $phone, ?int $except = null, bool $shared = false): ?string
    {
        if ($shared) {
            return null;
        }

        $holder = self::holder('phone', $phone, $except);

        return $holder ? 'الرقم '.$phone.' مسجّلٌ في شركتك ل'.self::describe($holder).'. اكتب رقماً غيره.' : null;
    }

    /** لماذا لا يُعطى اسم المستخدم المكتوب، أو null إن كان صالحاً حرّاً */
    public static function usernameProblem(?string $typed, ?int $except = null): ?string
    {
        if (blank($typed)) {
            return null;
        }

        $username = Username::normalise($typed);
        if ($username === null) {
            return Username::RULE_MESSAGE;
        }

        $holder = self::holder('username', $username, $except);

        return $holder ? 'اسم المستخدم «'.$username.'» ل'.self::describe($holder).'. اختر اسماً غيره، أو اتركه فارغاً.' : null;
    }

    /**
     * اسم الدخول لحسابٍ جديد: المكتوب، وإلّا رقم هاتفه، وإن كان رقمه اسماً لغيره (رقمٌ مشترك) فرقمه ورمز صاحبه:
     * «07701234567-m0012».
     */
    public static function usernameFor(?string $typed, string $phone, string $suffix, ?int $except = null): string
    {
        if ($name = Username::normalise($typed)) {
            return $name;
        }

        $base = Username::canonical($phone);
        $tag = Username::canonical(preg_replace('/[^0-9A-Za-z]+/', '', $suffix));
        foreach (['', '-'.$tag, '-'.$tag.'-2', '-'.$tag.'-3', '-'.$tag.'-4'] as $end) {
            $candidate = $base.$end;
            if (Username::normalise($candidate) !== null && self::holder('username', $candidate, $except) === null) {
                return $candidate;
            }
        }

        return $base.'-'.$tag.'-'.random_int(10, 99);
    }

    /** المحذوف لا يحجز: يُفرَغ رقمه واسمه قبل أن يُعطيا لحسابٍ جديد (القاعدة تحفظ المحذوف بقيمه) */
    public static function releaseDeleted(?string $phone, ?string $username): void
    {
        if (blank($phone) && blank($username)) {
            return;
        }

        User::onlyTrashed()
            ->where(fn ($q) => $q->when($phone, fn ($q) => $q->orWhere('phone', $phone))
                ->when($username, fn ($q) => $q->orWhere('username', $username)))
            ->get()
            ->each(fn (User $u) => $u->forceFill(['phone' => 'x'.$u->id, 'username' => 'removed-'.$u->id])->saveQuietly());
    }

    /**
     * تغيّر رقم التاجر أو المندوب: حساب دخوله يتبعه، ومن كان يُدخل برقمه القديم يُدخل بالجديد — ما لم يحمله اسماً
     * غيره. يعود true إن صار اسم الدخول رقمه الجديد.
     */
    public static function followPhone(User $account, string $oldPhone, string $newPhone): bool
    {
        if ($oldPhone === $newPhone) {
            return false;
        }

        $changes = [];
        if ($account->phone === $oldPhone) {
            $changes['phone'] = $newPhone;
        }

        $newName = Username::canonical($newPhone);
        $renamed = $account->username === Username::canonical($oldPhone)
            && self::holder('username', $newName, $account->id) === null;
        if ($renamed) {
            self::releaseDeleted(null, $newName);
            $changes['username'] = $newName;
        }

        if ($changes !== []) {
            if (isset($changes['phone'])) {
                self::releaseDeleted($newPhone, null);
            }
            // username يُعاد حسابه في User::booted من الحقل نفسه: يُحفظ كما هو
            $account->forceFill($changes)->save();
        }

        return $renamed;
    }
}
