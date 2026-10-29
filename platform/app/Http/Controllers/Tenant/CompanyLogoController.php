<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوغو الشركة (docs/plan/45): يُرفع من الإعدادات — صورةٌ من معرض الهاتف أو كاميرته أو من الحاسوب —
 * فيحلّ محلّ الحرف في رأس كل شاشة: نظام الموظّفين، وبوابة التاجر، وتطبيق المندوب، وصفحة التتبّع.
 *
 * يُعاد رسم الصورة هنا PNG مربّعاً لا يتجاوز ٥١٢ نقطة: لا يبقى منها إلّا بكسلاتها — لا بيانات
 * الكاميرا ولا ما خُبّئ في الملف — ولا تُقبل SVG.
 */
class CompanyLogoController extends Controller
{
    public const MAX = 512;

    public function show(TenantContext $tenant): Response
    {
        return $this->serve($tenant->company()?->logo_path);
    }

    /** شعار الوصل المختصر (docs/plan/94) */
    public function showMark(TenantContext $tenant): Response
    {
        return $this->serve($tenant->company()?->mark_path);
    }

    private function serve(?string $path): Response
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'logo.png', [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $this->save($request, $tenant, 'logo');

        return redirect()->route('settings.company')->with('success', 'رُفع اللوغو — يظهر الآن في رأس كل شاشة.');
    }

    /** شعار الوصل المختصر (docs/plan/94): حرف الشعار وحده، يُطبع كبيراً في أعلى الوصل */
    public function storeMark(Request $request, TenantContext $tenant): RedirectResponse
    {
        $this->save($request, $tenant, 'mark');

        return redirect()->route('settings.company')->with('success', 'رُفع شعار الوصل — يُطبع في أعلى كلّ وصل.');
    }

    /** logo · mark: الصورة من الحقل باسمه، وتُحفظ في عمود {اسمه}_path */
    private function save(Request $request, TenantContext $tenant, string $kind): void
    {
        $noun = $kind === 'logo' ? 'اللوغو' : 'شعار الوصل';
        $request->validate([
            // حدّ PHP للرفع ٨ ميغابايت (docker/php.ini): الرسالة العربية قبله
            $kind => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:7168'],
        ], [
            "{$kind}.required" => "اختر صورة {$noun}.",
            "{$kind}.mimes"    => "{$noun} صورة JPG أو PNG أو WEBP.",
            "{$kind}.max"      => 'الصورة أكبر من ٧ ميغابايت — اختر أصغر منها.',
            "{$kind}.uploaded" => 'تعذّر رفع الصورة — أعد.',
        ]);

        $company = $tenant->company();
        $source = @imagecreatefromstring((string) file_get_contents($request->file($kind)->getRealPath()));

        if (! $source) {
            throw ValidationException::withMessages([$kind => 'تعذّرت قراءة الصورة — اختر صورةً أخرى.']);
        }

        // يُصغَّر داخل مربّعٍ شفّاف بحجمه: لا يُقصّ ولا يتمطّط
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $side = max($w, $h);

        $canvas = imagecreatetruecolor($side, $side);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, intdiv($side - $w, 2), intdiv($side - $h, 2), 0, 0, $w, $h, $width, $height);

        ob_start();
        imagepng($canvas, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        $column = "{$kind}_path";
        $old = $company->{$column};
        $path = 'logos/'.$company->id.'/'.$kind.'-'.now()->format('YmdHis').'.png';
        Storage::disk('local')->put($path, $png);
        $company->forceFill([$column => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    public function destroy(TenantContext $tenant): RedirectResponse
    {
        $company = $tenant->company();

        if ($company->logo_path) {
            Storage::disk('local')->delete($company->logo_path);
            $company->forceFill(['logo_path' => null])->save();
        }

        return redirect()->route('settings.company')->with('success', 'أُزيل اللوغو — يعود الحرف الأوّل من اسم الشركة.');
    }

    public function destroyMark(TenantContext $tenant): RedirectResponse
    {
        $company = $tenant->company();

        if ($company->mark_path) {
            Storage::disk('local')->delete($company->mark_path);
            $company->forceFill(['mark_path' => null])->save();
        }

        return redirect()->route('settings.company')->with('success', 'أُزيل شعار الوصل — يُطبع اللوغو كاملاً.');
    }
}
