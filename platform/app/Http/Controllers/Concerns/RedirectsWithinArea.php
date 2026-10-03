<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ExpiredForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * بعد الدخول: الصفحة التي قُصدت قبله إن كانت من جهة هذا الدخول، وإلا البيت.
 *
 * بلا نطاقٍ تكون لوحة المنصّة ونظام الشركة على عنوانٍ واحد وجلسةٍ واحدة:
 * من فتح نظام الشركة ثم دخل اللوحة كان يُعاد إلى صفحة الشركة، فتُخرجه إلى
 * دخولها — والعكس.
 */
trait RedirectsWithinArea
{
    protected function intendedWithin(Request $request, bool $platform, string $home): RedirectResponse
    {
        $intended = (string) $request->session()->pull('url.intended');
        $path = ltrim((string) parse_url($intended, PHP_URL_PATH), '/');

        $target = $intended !== '' && Str::is(['admin', 'admin/*'], $path) === $platform ? $intended : $home;

        // صفحةٌ انتهت جلستها وفيها ما كُتب: يعود إليها ما كتبه
        return ExpiredForm::restore($request, redirect()->to($target));
    }
}
