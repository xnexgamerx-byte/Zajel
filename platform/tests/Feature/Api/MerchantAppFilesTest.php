<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WaybillBook;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * «وصولات للطباعة» و«رفع شحنات من ملف» في تطبيق التاجر (docs/plan/57): الطباعة صفحة البوابة برابطٍ
 * موقَّع، والاستيراد معاينةٌ لا تُنشئ شيئاً ثم تأكيد — بقواعد البوابة نفسها.
 */
class MerchantAppFilesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->user = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
    }

    private function api(string $path): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain').'/api/v1'.$path;
    }

    private function headers(): array
    {
        $token = $this->postJson($this->api('/login'), [
            'username' => $this->user->username, 'password' => 'password', 'app' => 'merchant',
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function sheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zajel-test-').'.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([['اسم المستلم', 'هاتف المستلم', 'المحافظة', 'المنطقة', 'أقرب نقطة دالّة', 'المبلغ المطلوب']], $rows), null, 'A1');
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'شحنات.xlsx', null, null, true);
    }

    public function test_a_waybill_book_prints_in_the_browser_by_a_signed_link(): void
    {
        $headers = $this->headers();
        $this->setFeature($this->company, Feature::Waybills, false);
        $this->getJson($this->api('/merchant/waybills'), $headers)->assertForbidden();

        $this->setFeature($this->company, Feature::Waybills);
        $this->getJson($this->api('/merchant/waybills'), $headers)->assertOk()
            ->assertJsonPath('max', WaybillBook::MAX_SIZE)->assertJsonCount(2, 'sizes')->assertJsonCount(0, 'books');

        $this->postJson($this->api('/merchant/waybills'), ['size' => 0, 'print_size' => '80x120'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('size');
        $made = $this->postJson($this->api('/merchant/waybills'), ['size' => 25, 'print_size' => '100x100'], $headers)
            ->assertCreated()->json();

        $book = Tenancy::runFor($this->company, fn () => WaybillBook::sole());
        $this->assertSame('app', $book->source);
        $this->assertSame($this->merchant->id, (int) $book->merchant_id);
        $this->assertStringContainsString('size=100x100', $made['print']);

        // يُفتح بلا دخول — برابطه الموقَّع وحده
        auth()->forgetGuards();
        $this->get($made['print'])->assertOk()->assertSee($book->firstCode())->assertDontSee('رجوع');
        // رابط دفترٍ لا يفتح دفتراً غيره
        $other = $this->postJson($this->api('/merchant/waybills'), ['size' => 5, 'print_size' => '80x120'], $this->headers())->assertCreated();
        auth()->forgetGuards();
        $this->get(str_replace("/app/waybills/{$book->id}/", '/app/waybills/'.($book->id + 1).'/', $made['print']))->assertForbidden();
        $this->get($other->json('print'))->assertOk();
        $this->get(preg_replace('/&signature=[^&]+/', '', $made['print']))->assertForbidden();

        $this->getJson($this->api('/merchant/waybills'), $this->headers())->assertOk()
            ->assertJsonPath('books.1.range', $book->firstCode().'–'.$book->lastCode())
            ->assertJsonPath('books.1.used', 0)
            ->assertJsonPath('books.1.print.80x120', fn ($url) => str_contains($url, 'signature='));
    }

    public function test_a_file_is_previewed_then_imported_with_or_without_its_bad_rows(): void
    {
        $this->setFeature($this->company, Feature::ExcelImport);
        $headers = $this->headers();

        $template = $this->getJson($this->api('/merchant/import'), $headers)->assertOk()
            ->assertJsonPath('columns.0.label', 'اسم المستلم')->json('template');
        auth()->forgetGuards();
        $this->assertStringContainsString('attachment', $this->get($template)->assertOk()->headers->get('content-disposition'));
        $headers = $this->headers();

        $preview = $this->post($this->api('/merchant/import'), ['file' => $this->sheet([
            ['زينب كاظم', '07701112233', 'بغداد', 'الكرادة', 'قرب الكلية', 75000],
            ['علي حسين', '07801234567', 'بغداد', 'المنصور', '', 40000],
            ['بلا هاتف', '', 'بغداد', 'الكرادة', '', 30000],
        ])], $headers)->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('good', 2)
            ->assertJsonPath('bad.0.row', 4)->assertJsonPath('bad.0.name', 'بلا هاتف')
            ->assertJsonPath('rows.0.name', 'زينب كاظم')->assertJsonPath('rows.0.place', 'بغداد · الكرادة')
            ->assertJsonPath('rows.0.amount', 75000)
            ->json();
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));

        $this->postJson($this->api('/merchant/import/confirm'), ['path' => $preview['path']], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'في الملف صفوف بأخطاء، عددها 1. صحّحه أو استورد الصفوف الصحيحة وحدها.');
        // ملف مستخدمٍ آخر لا يُقرأ
        $this->postJson($this->api('/merchant/import/confirm'), ['path' => str_replace('/'.$this->user->id.'/', '/999/', $preview['path']),
            'skip_errors' => true], $headers)->assertNotFound();

        $this->postJson($this->api('/merchant/import/confirm'), ['path' => $preview['path'], 'skip_errors' => true], $headers)
            ->assertCreated()->assertJsonPath('created', 2);

        Tenancy::runFor($this->company, function () {
            $this->assertSame(2, Shipment::count());
            $this->assertSame(['merchant_app'], Shipment::distinct()->pluck('source')->all());
        });
    }
}
