<?php

namespace Tests\Feature\Import;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Import\ShipmentSheet;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ملف التاجر يصل دائماً بأخطاء: محافظة باسم مدينتها، هاتف بفواصل،
 * أرقام عربية شرقية، وطلب مكرّر. الاختبار هنا على ما يفعله النظام
 * بهذه الحالات، لا على الملفّ النظيف الذي لا يرسله أحد.
 */
class ShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $staff;

    private User $alphaUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->staff = $this->makeUser($this->company);
        $this->alphaUser = $this->merchantUser($this->alpha);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function merchantUser(Merchant $merchant): User
    {
        return Tenancy::runFor($this->company, fn () => User::create([
            'name'        => 'تاجر '.$merchant->code,
            'phone'       => '0779'.str_pad((string) $merchant->id, 7, '0', STR_PAD_LEFT),
            'password'    => 'password',
            'role'        => UserRole::Merchant,
            'merchant_id' => $merchant->id,
            'is_active'   => true,
        ]));
    }

    /** يكتب ملفاً حقيقياً على القرص: القراءة هي موضع الاختبار، لا التزييف. */
    private function sheetFile(array $header, array $rows, string $extension = 'xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zajel-test-').'.'.$extension;

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([$header], $rows), null, 'A1');
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'شحنات.'.$extension, null, null, true);
    }

    /** الترتيب الكامل كما في القالب. */
    private function fullHeader(): array
    {
        return array_values(ShipmentSheet::COLUMNS);
    }

    private function row(array $overrides = []): array
    {
        $values = array_merge([
            'recipient_name'      => 'علي حسين',
            'recipient_phone'     => '07801234567',
            'recipient_phone_alt' => '',
            'governorate'         => 'بغداد',
            'city'                => '',
            'address'             => 'الكرادة، شارع 62',
            'landmark'            => 'مقابل الجامع',
            'cod_amount'          => 50000,
            'pieces_count'        => 1,
            'weight_grams'        => 1000,
            'description'         => 'ملابس',
            'notes'               => '',
            'merchant_reference'  => '',
            'fees_paid_by'        => 'التاجر',
        ], $overrides);

        return array_map(fn ($field) => $values[$field], array_keys(ShipmentSheet::COLUMNS));
    }

    private function read(array $header, array $rows)
    {
        $file = $this->sheetFile($header, $rows);

        return Tenancy::runFor($this->company, fn () => app(ShipmentSheet::class)->read($file->getPathname()));
    }

    public function test_it_maps_columns_by_name_not_by_position(): void
    {
        // التاجر يعيد ترتيب أعمدته ويضيف عموداً لا نعرفه — وهذا وارد دائماً
        $rows = $this->read(
            ['المبلغ المطلوب', 'رقم الفاتورة عندي', 'اسم المستلم', 'المحافظة', 'العنوان', 'أقرب نقطة دالّة', 'هاتف المستلم'],
            [[75000, 'X-1', 'زينب كاظم', 'بغداد', 'الجادرية', 'قرب الكلية', '07701112233']],
        );

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('زينب كاظم', $rows[0]['data']['recipient_name']);
        $this->assertSame('07701112233', $rows[0]['data']['recipient_phone']);
        $this->assertSame(75000, $rows[0]['data']['cod_amount']);
    }

    public function test_a_shortened_header_does_not_steal_another_field_column(): void
    {
        // «هاتف» وحدها تحتمل الحقلين؛ المطابقة التامّة تحسم أيّهما
        $rows = $this->read(
            ['اسم المستلم', 'هاتف بديل', 'هاتف', 'المحافظة', 'العنوان', 'أقرب نقطة دالّة', 'المبلغ المطلوب'],
            [['علي', '07701112233', '07809998877', 'بغداد', 'الكرادة', 'قرب الجامع', 25000]],
        );

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('07809998877', $rows[0]['data']['recipient_phone']);
        $this->assertSame('07701112233', $rows[0]['data']['recipient_phone_alt']);
    }

    public function test_it_reads_the_template_it_generates(): void
    {
        // القالب يضع نجمة على الحقول المطلوبة؛ لو لم تُقرأ عناوينه لكان بلا فائدة
        $path = Tenancy::runFor($this->company, fn () => app(ShipmentSheet::class)->template());
        $rows = Tenancy::runFor($this->company, fn () => app(ShipmentSheet::class)->read($path));

        @unlink($path);

        $this->assertCount(1, $rows, 'صفّ المثال في القالب لم يُقرأ');
        $this->assertSame([], $rows[0]['errors'], 'صفّ المثال في القالب يجب أن يمرّ بلا خطأ');
        $this->assertSame('علي حسين', $rows[0]['data']['recipient_name']);
        $this->assertSame('ORD-1001', $rows[0]['data']['merchant_reference']);
    }

    #[DataProvider('phoneSamples')]
    public function test_it_normalises_phone_numbers(string $raw, ?string $expected): void
    {
        $rows = $this->read($this->fullHeader(), [$this->row(['recipient_phone' => $raw])]);

        $this->assertSame($expected, $rows[0]['data']['recipient_phone'], "الهاتف «{$raw}»");
    }

    public static function phoneSamples(): array
    {
        return [
            'قياسي'            => ['07801234567', '07801234567'],
            'بفواصل'           => ['0780-123-4567', '07801234567'],
            'بمسافات'          => ['0780 123 4567', '07801234567'],
            'برمز الدولة'      => ['+9647801234567', '07801234567'],
            'برمز بلا زائد'    => ['9647801234567', '07801234567'],
            'بلا صفر'          => ['7801234567', '07801234567'],
            'أرقام عربية'      => ['٠٧٨٠١٢٣٤٥٦٧', '07801234567'],
            'أرقام فارسية'     => ['۰۷۸۰۱۲۳۴۵۶۷', '07801234567'],
            'ناقص رقماً'       => ['0780123456', null],
            'لا يبدأ بسبعة'    => ['06801234567', null],
        ];
    }

    public function test_an_invalid_phone_is_an_error_not_a_silent_null(): void
    {
        $rows = $this->read($this->fullHeader(), [$this->row(['recipient_phone' => '123'])]);

        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertStringContainsString('هاتف المستلم', $rows[0]['errors'][0]);
    }

    public function test_an_empty_alternate_phone_is_not_an_error(): void
    {
        $rows = $this->read($this->fullHeader(), [$this->row(['recipient_phone_alt' => ''])]);

        $this->assertSame([], $rows[0]['errors']);
        $this->assertNull($rows[0]['data']['recipient_phone_alt']);
    }

    #[DataProvider('governorateSamples')]
    public function test_it_matches_governorates_written_many_ways(string $raw, string $code): void
    {
        $rows = $this->read($this->fullHeader(), [$this->row(['governorate' => $raw])]);

        $this->assertSame([], $rows[0]['errors'], "المحافظة «{$raw}» لم تُقبل");
        $this->assertSame(
            $code,
            Tenancy::runFor($this->company, fn () => \App\Models\Governorate::find($rows[0]['data']['governorate_id'])->code),
        );
    }

    public static function governorateSamples(): array
    {
        return [
            'اسم مباشر'        => ['بغداد', 'BGD'],
            'بهمزة'            => ['الأنبار', 'ANB'],
            'بتاء مربوطة'      => ['البصرة', 'BSR'],
            'بتاء مفتوحة'      => ['البصره', 'BSR'],
            'بمسافات زائدة'    => ['  بغداد  ', 'BGD'],
            'بالإنجليزية'      => ['Baghdad', 'BGD'],
            'بالرمز'           => ['NIN', 'NIN'],
            'باسم المدينة'     => ['الموصل', 'NIN'],
            'مدينة بتاء'       => ['الحلة', 'BBL'],
            'مدينة بهاء'       => ['الديوانيه', 'QAD'],
        ];
    }

    public function test_an_unknown_governorate_names_itself_in_the_error(): void
    {
        $rows = $this->read($this->fullHeader(), [$this->row(['governorate' => 'دمشق'])]);

        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertStringContainsString('دمشق', $rows[0]['errors'][0]);
        $this->assertNull($rows[0]['data']['governorate_id']);
    }

    public function test_it_reads_arabic_indic_digits_and_thousands_separators_in_amounts(): void
    {
        $rows = $this->read($this->fullHeader(), [
            $this->row(['cod_amount' => '٥٠,٠٠٠']),
            $this->row(['cod_amount' => '75,000']),
            $this->row(['cod_amount' => '30000 د.ع']),
        ]);

        $this->assertSame([50000, 75000, 30000], $rows->pluck('data.cod_amount')->all());
    }

    public function test_it_reports_the_excel_row_number_so_the_merchant_can_find_it(): void
    {
        $rows = $this->read($this->fullHeader(), [
            $this->row(),
            $this->row(['landmark' => '']),
        ]);

        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame(3, $rows[1]['row'], 'رقم الصفّ يجب أن يطابق ما يراه التاجر في Excel');
        $this->assertStringContainsString('أقرب نقطة دالّة', $rows[1]['errors'][0]);
    }

    public function test_every_missing_required_field_is_reported_at_once(): void
    {
        $rows = $this->read($this->fullHeader(), [
            $this->row(['recipient_name' => '', 'address' => '', 'landmark' => '']),
        ]);

        $this->assertCount(3, $rows[0]['errors'], 'التاجر يصحّح الملف مرّة واحدة لا ثلاثاً');
    }

    public function test_blank_rows_are_skipped_without_an_error(): void
    {
        $blank = array_fill(0, count(ShipmentSheet::COLUMNS), '');

        $rows = $this->read($this->fullHeader(), [$this->row(), $blank, $blank]);

        $this->assertCount(1, $rows);
    }

    public function test_a_reference_repeated_inside_the_file_is_caught(): void
    {
        $rows = $this->read($this->fullHeader(), [
            $this->row(['merchant_reference' => 'ORD-7']),
            $this->row(['merchant_reference' => 'ORD-8']),
            $this->row(['merchant_reference' => 'ORD-7']),
        ]);

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame([], $rows[1]['errors']);
        $this->assertNotEmpty($rows[2]['errors'], 'رقم طلب مكرّر داخل الملف يعني شحنتين لطلب واحد');
        $this->assertStringContainsString('الصفّ 2', $rows[2]['errors'][0]);
    }

    public function test_fees_paid_by_the_customer_is_understood_from_arabic(): void
    {
        $rows = $this->read($this->fullHeader(), [
            $this->row(['fees_paid_by' => 'الزبون']),
            $this->row(['fees_paid_by' => 'المستلم']),
            $this->row(['fees_paid_by' => 'التاجر']),
            $this->row(['fees_paid_by' => '']),
        ]);

        $this->assertSame(
            ['customer', 'customer', 'merchant', 'merchant'],
            $rows->pluck('data.fees_paid_by')->all(),
        );
    }

    // ── الشاشة: رفع ← معاينة ← تأكيد ────────────────────────────────

    public function test_staff_import_creates_the_shipments_after_confirmation(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [
            $this->row(['recipient_name' => 'أحمد']),
            $this->row(['recipient_name' => 'سارة', 'governorate' => 'الموصل']),
        ]);

        $preview = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->assertOk()
            ->assertSee('جاهزة للاستيراد');

        $path = $preview->viewData('path');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()), 'المعاينة لا تُنشئ شيئاً');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id])
            ->assertRedirect($this->host().'/shipments')
            ->assertSessionHas('success');

        Tenancy::runFor($this->company, function () {
            $this->assertSame(2, Shipment::count());
            $this->assertSame(
                ['أحمد', 'سارة'],
                Shipment::orderBy('id')->pluck('recipient_name')->all(),
            );
            $this->assertSame([$this->alpha->id, $this->alpha->id], Shipment::pluck('merchant_id')->all());
            $this->assertSame('import', Shipment::first()->source);
        });

        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_shipments_are_priced_like_any_other(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [$this->row(['weight_grams' => 1000])]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->viewData('path');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id]);

        Tenancy::runFor($this->company, function () {
            $shipment = Shipment::firstOrFail();
            $this->assertSame(5000, $shipment->delivery_fee, 'الاستيراد يمرّ بنفس التسعيرة');
            $this->assertSame(45000, $shipment->merchant_due);
            $this->assertNotEmpty($shipment->number);
        });
    }

    public function test_confirmation_refuses_a_file_with_errors_unless_the_bad_rows_are_skipped(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [
            $this->row(),
            $this->row(['governorate' => 'دمشق']),
        ]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->viewData('path');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()));

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', [
                'path' => $path, 'merchant_id' => $this->alpha->id, 'skip_errors' => 1,
            ])
            ->assertRedirect($this->host().'/shipments');

        Tenancy::runFor($this->company, fn () => $this->assertSame(1, Shipment::count()));
    }

    public function test_a_reference_already_used_by_this_merchant_is_flagged_against_its_receipt(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [$this->row(['merchant_reference' => 'ORD-9'])]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->viewData('path');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id]);

        $number = Tenancy::runFor($this->company, fn () => Shipment::firstOrFail()->number);

        $again = $this->sheetFile($this->fullHeader(), [$this->row(['merchant_reference' => 'ORD-9'])]);

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $again])
            ->assertOk()
            ->assertSee('ORD-9')
            ->assertSee($number);

        Tenancy::runFor($this->company, fn () => $this->assertSame(1, Shipment::count()));
    }

    public function test_the_same_reference_at_another_merchant_is_not_a_duplicate(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [$this->row(['merchant_reference' => 'ORD-9'])]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->viewData('path');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id]);

        $other = $this->sheetFile($this->fullHeader(), [$this->row(['merchant_reference' => 'ORD-9'])]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->beta->id, 'file' => $other])
            ->viewData('path');

        $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->beta->id])
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, fn () => $this->assertSame(2, Shipment::count()));
    }

    public function test_nothing_is_created_when_the_import_fails_midway(): void
    {
        // صفّ صحيح ظاهرياً لكن إنشاؤه يفشل: الملف كلّه يجب أن يُلغى
        $file = $this->sheetFile($this->fullHeader(), [$this->row(), $this->row(['recipient_name' => 'ثانٍ'])]);

        $path = $this->actingAs($this->staff)
            ->post($this->host().'/shipments/import', ['merchant_id' => $this->alpha->id, 'file' => $file])
            ->viewData('path');

        $this->mock(\App\Actions\Shipments\CreateShipment::class, function ($mock) {
            $mock->shouldReceive('handle')->once()->andReturnUsing(fn () => throw new \RuntimeException('فشل'));
        });

        try {
            $this->actingAs($this->staff)->withoutExceptionHandling()
                ->post($this->host().'/shipments/import/confirm', ['path' => $path, 'merchant_id' => $this->alpha->id]);
            $this->fail('كان يجب أن يرتفع الاستثناء');
        } catch (\RuntimeException) {
            // متوقّع
        }

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Shipment::count()), 'الاستيراد إمّا كلّه أو لا شيء');
    }

    public function test_a_merchant_login_cannot_reach_the_staff_import_screen(): void
    {
        $this->actingAs($this->alphaUser)
            ->get($this->host().'/shipments/import')
            ->assertForbidden();
    }

    public function test_the_portal_imports_only_for_the_signed_in_merchant(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [$this->row()]);

        $path = $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import', ['file' => $file, 'merchant_id' => $this->beta->id])
            ->assertOk()
            ->viewData('path');

        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import/confirm', [
                'path' => $path, 'merchant_id' => $this->beta->id,
            ])
            ->assertRedirect($this->host().'/portal/shipments');

        Tenancy::runFor($this->company, function () {
            $this->assertSame(1, Shipment::count());
            $this->assertSame(
                $this->alpha->id,
                Shipment::firstOrFail()->merchant_id,
                'التاجر لا يستورد لغيره مهما أرسل في الطلب',
            );
            $this->assertSame('merchant_portal', Shipment::firstOrFail()->source);
        });
    }

    public function test_a_merchant_cannot_confirm_a_file_uploaded_by_another_company(): void
    {
        $other = $this->makeCompany('barq', 'البرق');
        $otherMerchant = $this->makeMerchant($other, 'M0001');
        $otherUser = Tenancy::runFor($other, fn () => User::create([
            'name' => 'تاجر البرق', 'phone' => '07751112233', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $otherMerchant->id, 'is_active' => true,
        ]));

        $file = $this->sheetFile($this->fullHeader(), [$this->row(['recipient_name' => 'سرّي'])]);

        $stolen = $this->actingAs($otherUser)
            ->post('http://barq.'.config('zajel.tenant_domain').'/portal/shipments/import', ['file' => $file])
            ->viewData('path');

        // ملف شركة أخرى على القرص، والمسار يصل من المتصفّح: لا يجوز أن يُقرأ
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import/confirm', ['path' => $stolen])
            ->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));
    }

    public function test_a_colleague_cannot_confirm_a_file_uploaded_by_another_user(): void
    {
        $file = $this->sheetFile($this->fullHeader(), [$this->row()]);

        $path = $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import', ['file' => $file])
            ->viewData('path');

        $colleague = $this->merchantUser($this->beta);

        $this->actingAs($colleague)
            ->post($this->host().'/portal/shipments/import/confirm', ['path' => $path])
            ->assertNotFound();

        Tenancy::runFor($this->company, fn () => $this->assertSame(0, Shipment::count()));
    }

    public function test_a_made_up_path_is_refused_rather_than_traversed(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import/confirm', [
                'path' => 'imports/'.$this->alphaUser->company_id.'/'.$this->alphaUser->id.'/../../../../.env',
            ])
            ->assertNotFound();
    }

    public function test_the_upload_rejects_a_file_that_is_not_a_sheet(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/shipments/import', [
                'file' => UploadedFile::fake()->create('فاتورة.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_the_template_downloads_as_a_sheet(): void
    {
        $this->actingAs($this->alphaUser)
            ->get($this->host().'/portal/shipments/import/template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
