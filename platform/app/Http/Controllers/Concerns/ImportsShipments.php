<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\Shipments\ImportShipments;
use App\Models\Merchant;
use App\Services\Import\ShipmentSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * منطق الاستيراد مشترك بين لوحة الشركة وبوابة التاجر: الشاشتان
 * تختلفان في من يختار التاجر، لا في قراءة الملف ولا في التحقّق.
 */
trait ImportsShipments
{
    public function template(ShipmentSheet $sheet): BinaryFileResponse
    {
        return response()
            ->download($sheet->template(), 'قالب-الشحنات.xlsx')
            ->deleteFileAfterSend();
    }

    /** يرفع الملف ويخزّنه مؤقّتاً ثم يعرض المعاينة — لا يُنشئ شيئاً بعد. */
    protected function storeUpload(Request $request): string
    {
        // mimes يفحص المحتوى، وextensions يفحص الاسم الذي يُحفَظ به الملف
        // أدناه: بدونه يُخزَّن CSV سليمٌ باسم ‎.html أو ‎.svg كما سمّاه رافعه.
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'extensions:xlsx,xls,csv,txt', 'max:5120'],
        ], [], ['file' => 'الملف']);

        $this->pruneStaleUploads($request);

        $path = $this->uploadPrefix($request).Str::uuid().'.'
            .$request->file('file')->getClientOriginalExtension();

        Storage::disk('local')->put($path, $request->file('file')->get());

        return $path;
    }

    /**
     * الملف المرفوع ملك رافعه وحده.
     *
     * المسار يعود من حقل مخفيّ في صفحة المعاينة، أي أنه مُدخَل مستخدم:
     * لولا المجلّد الخاصّ بكلّ مستخدم لاستطاع تاجرُ شركةٍ أن يرسل مسار
     * ملف شركة أخرى فيقرأ أسماء زبائنها وهواتفهم في جدول المعاينة.
     */
    protected function uploadPrefix(Request $request): string
    {
        return 'imports/'.$request->user()->company_id.'/'.$request->user()->id.'/';
    }

    /** ملفات رُفعت ولم تُؤكَّد: بيانات زبائن لا سبب لبقائها. */
    protected function pruneStaleUploads(Request $request): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files($this->uploadPrefix($request)) as $file) {
            if ($disk->lastModified($file) < now()->subHours(6)->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    /** @return array{rows: \Illuminate\Support\Collection, duplicates: array, path: string} */
    protected function preview(Request $request, string $path, Merchant $merchant, ShipmentSheet $sheet, ImportShipments $import): array
    {
        $expected = '/^'.preg_quote($this->uploadPrefix($request), '/').'[0-9a-f-]{36}\.[a-z]{3,4}$/i';

        abort_unless(preg_match($expected, $path), 404, 'انتهت صلاحية الملف المرفوع. ارفعه من جديد.');
        abort_unless(Storage::disk('local')->exists($path), 404, 'انتهت صلاحية الملف المرفوع. ارفعه من جديد.');

        $rows = $sheet->read(Storage::disk('local')->path($path));
        $duplicates = $import->existingReferences($rows, $merchant);

        // رقم طلب موجود سلفاً: خطأ صفّ لا تحذير عام، حتى يراه التاجر في مكانه
        $rows = $rows->map(function (array $row) use ($duplicates) {
            $reference = $row['data']['merchant_reference'] ?? null;

            if ($reference && isset($duplicates[$reference])) {
                $row['errors'][] = "رقم طلبك «{$reference}» مُرسَل سابقاً بالوصل {$duplicates[$reference]}";
            }

            return $row;
        });

        return ['rows' => $rows, 'duplicates' => $duplicates, 'path' => $path];
    }

    /** يحذف الملف بعد الاستيراد: لا داعي لبقاء بيانات زبائن على القرص. */
    protected function forget(string $path): void
    {
        Storage::disk('local')->delete($path);
    }
}
