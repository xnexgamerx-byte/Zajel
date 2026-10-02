<?php

namespace Tests\Feature\Shipments;

use App\Actions\Waybills\IssueWaybillBook;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WaybillBook;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصولات المطبوعة مسبقاً (docs/plan/23): يطبع التاجر دفتراً من بوابته بمقاس
 * طابعته، ويكتب على كل وصلٍ بيده، ثم يُمسح الوصل فتُدخَل شحنته برقمه — مرّةً
 * واحدة، ولتاجر دفتره وحده — ويجدها كل مسحٍ في النظام بالرقم المطبوع.
 */
class WaybillsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $owner;

    private User $alphaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        $this->alphaUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر ألفا', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function book(?Merchant $merchant, int $size): WaybillBook
    {
        return Tenancy::runFor($this->company, fn () => app(IssueWaybillBook::class)->handle($merchant, $size, $this->owner));
    }

    /** ما كتبه التاجر بيده على الوصل */
    private function written(array $data = []): array
    {
        return $data + [
            'merchant_id' => $this->alpha->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'city_id' => $this->area(), 'landmark' => 'قرب الجامع',
            'cod_amount' => 25_000, 'pieces_count' => 1, 'fees_paid_by' => 'merchant',
        ];
    }

    private function shipmentWith(string $code): ?Shipment
    {
        return Tenancy::runFor($this->company, fn () => Shipment::withTrashed()->where('barcode', $code)->first());
    }

    public function test_books_take_consecutive_numbers_that_never_meet_shipment_numbers(): void
    {
        $first = $this->book($this->alpha, 3);
        $second = $this->book(null, 2);

        $this->assertSame(['90000001', '90000002', '90000003'], $first->codes());
        $this->assertSame(['90000004', '90000005'], $second->codes());
        $this->assertSame($this->alpha->id, $first->merchant_id);
        $this->assertNull($second->merchant_id);

        // لكل شركةٍ تسلسلها، والرقم يُقرأ بأرقامٍ عربية وبفراغات كما يُقرأ باللاتينية
        $this->assertSame(1, WaybillBook::serialOf('٩٠٠٠٠٠٠١'));
        $this->assertSame(5, WaybillBook::serialOf(' 9000 0005 '));
        $this->assertNull(WaybillBook::serialOf('000123'));
        $this->assertNull(WaybillBook::serialOf('90000000'));
    }

    public function test_the_merchant_prints_a_book_from_the_portal_in_either_size(): void
    {
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/waybills')
            ->assertOk()->assertSee('وصولات للطباعة')->assertSee('80×120')->assertSee('100×100');

        $response = $this->actingAs($this->alphaUser)->post($this->host().'/portal/waybills', ['size' => 3, 'print_size' => '100x100']);

        $book = Tenancy::runFor($this->company, fn () => WaybillBook::sole());
        $response->assertRedirect($this->host()."/portal/waybills/{$book->id}/print?size=100x100");
        $this->assertSame('portal', $book->source);
        $this->assertSame($this->alpha->id, $book->merchant_id);

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$book->id}/print?size=100x100")
            ->assertOk()
            ->assertSee('size: 100mm 100mm', false)
            ->assertSee(['90000001', '90000002', '90000003'])
            ->assertSee('متجر M0001')                 // اسم التاجر مطبوعٌ عليه
            ->assertSee(['اسم الزبون', 'هاتف الزبون', 'المحافظة', 'المنطقة', 'أقرب نقطة دالّة', 'المبلغ', 'ملاحظات'])
            ->assertSee(WaybillBook::DEFAULT_TERMS[0]);

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$book->id}/print?size=80x120")
            ->assertOk()->assertSee('size: 80mm 120mm', false);

        // مقاسٌ لا تطبعه الملصقات يُرَدّ
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/waybills', ['size' => 3, 'print_size' => 'A4'])
            ->assertSessionHasErrors('print_size');
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/waybills', ['size' => 201, 'print_size' => '80x120'])
            ->assertSessionHasErrors('size');
    }

    public function test_a_merchant_sees_and_prints_only_his_own_books(): void
    {
        $mine = $this->book($this->alpha, 2);
        $theirs = $this->book($this->beta, 2);
        $stock = $this->book(null, 2);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/waybills')
            ->assertOk()->assertSee($mine->firstCode())->assertDontSee($theirs->firstCode())->assertDontSee($stock->firstCode());

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$theirs->id}/print")->assertNotFound();
        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$stock->id}/print")->assertNotFound();
    }

    public function test_staff_issue_assign_and_print_books(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/waybill-books', ['size' => 4, 'note' => 'للمعرض'])
            ->assertSessionHasNoErrors();

        $book = Tenancy::runFor($this->company, fn () => WaybillBook::sole());
        $this->assertNull($book->merchant_id);
        $this->assertSame('office', $book->source);

        $this->actingAs($this->owner)->get($this->host().'/waybill-books')
            ->assertOk()->assertSee('90000001–90000004')->assertSee('للمعرض')->assertSee('أسنِده لتاجر');
        $this->actingAs($this->owner)->get($this->host()."/waybill-books?assign={$book->id}")
            ->assertOk()->assertSee('أسنِد الدفتر')->assertSee('/waybill-books/'.$book->id.'/assign', false);

        $this->actingAs($this->owner)->post($this->host()."/waybill-books/{$book->id}/assign", ['merchant_id' => $this->beta->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->beta->id, $book->refresh()->merchant_id);

        $this->actingAs($this->owner)->get($this->host()."/waybill-books/{$book->id}/print?size=80x120")
            ->assertOk()->assertSee('size: 80mm 120mm', false)->assertSee('متجر M0002');

        // بعد أن استُعمل منه وصل لا يتغيّر تاجره
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['merchant_id' => $this->beta->id, 'waybill' => '90000001']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($this->host()."/waybill-books/{$book->id}/assign", ['merchant_id' => $this->alpha->id])
            ->assertSessionHasErrors('merchant_id');
        $this->assertSame($this->beta->id, $book->refresh()->merchant_id);
    }

    public function test_scanning_a_waybill_opens_the_form_with_its_number_and_merchant(): void
    {
        $book = $this->book($this->alpha, 2);

        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill')
            ->assertOk()->assertSee('رقم الوصل المطبوع');

        // باركودٌ أو QR أو رقمٌ كُتب بأرقامٍ عربية: الرقم نفسه
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code='.urlencode('٩٠٠٠٠٠٠١'))
            ->assertRedirect($this->host().'/shipments/create?waybill=90000001');

        $this->actingAs($this->owner)->get($this->host().'/shipments/create?waybill=90000001')
            ->assertOk()
            ->assertSee('شحنة من الوصل المطبوع')
            ->assertSee('name="waybill" value="90000001"', false)
            ->assertSee('<input type="hidden" name="merchant_id" value="'.$this->alpha->id.'">', false)
            ->assertDontSee('id="merchant_id" name="merchant_id"', false);

        // وصلٌ في المخزن: يُختار تاجره في النموذج
        $stock = $this->book(null, 1);
        $this->actingAs($this->owner)->get($this->host().'/shipments/create?waybill='.$stock->firstCode())
            ->assertOk()->assertSee('id="merchant_id" name="merchant_id"', false);

        // ما ليس من وصولات الشركة
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code=12345')
            ->assertOk()->assertSee('ليس من وصولات الشركة المطبوعة');
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code=90000099')
            ->assertOk()->assertSee('ليس من وصولات الشركة المطبوعة');
        $this->actingAs($this->owner)->get($this->host().'/shipments/create?waybill=90000099')
            ->assertRedirect($this->host().'/shipments/waybill?code=90000099');

        $this->assertSame($book->id, Tenancy::runFor($this->company, fn () => WaybillBook::forCode('90000002'))->id);
    }

    public function test_a_shipment_is_saved_from_a_waybill_once(): void
    {
        $book = $this->book($this->alpha, 3);

        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000002']))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->host().'/shipments/waybill')
            ->assertSessionHas('success');

        $shipment = $this->shipmentWith('90000002');
        $this->assertNotNull($shipment);
        $this->assertSame($book->id, $shipment->waybill_book_id);
        $this->assertSame('إنشاء الشحنة من الوصل المطبوع 90000002',
            Tenancy::runFor($this->company, fn () => $shipment->events()->oldest('id')->value('note')));
        $this->assertNotSame('90000002', $shipment->number);   // رقمها من تسلسل الشحنات، والمطبوع باركودها
        $this->assertSame(25_000, (int) $shipment->cod_amount);

        // الوصل نفسه مرّةً ثانية: يُرَدّ، وبعد حذف شحنته أيضاً
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000002']))
            ->assertSessionHasErrors('waybill');
        Tenancy::runFor($this->company, fn () => $shipment->delete());
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000002']))
            ->assertSessionHasErrors('waybill');
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code=90000002')
            ->assertOk()->assertSee('استُعمل هذا الوصل للشحنة '.$shipment->number);

        $this->assertSame(1, Tenancy::runFor($this->company, fn () => Shipment::withTrashed()->where('barcode', '90000002')->count()));
    }

    public function test_a_stopped_merchants_waybill_is_not_entered(): void
    {
        $this->book($this->alpha, 1);
        Tenancy::runFor($this->company, fn () => $this->alpha->update(['status' => 'suspended']));

        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code=90000001')
            ->assertOk()->assertSee('غير مفعّل الآن');
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000001']))
            ->assertSessionHasErrors('merchant_id');

        $this->assertNull($this->shipmentWith('90000001'));
    }

    /** ما ليس نصّاً في الرابط يُعامَل فارغاً، لا خطأً في الخادم */
    public function test_odd_input_is_read_as_no_waybill(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code[]=90000001')
            ->assertOk()->assertSee('رقم الوصل المطبوع');
        $this->actingAs($this->owner)->get($this->host().'/shipments/create?waybill[]=90000001')
            ->assertOk()->assertSee('شحنة جديدة');
        $this->actingAs($this->owner)->get($this->host().'/shipments/waybill?code='.str_repeat('9', 500))
            ->assertOk()->assertSee('ليس من وصولات الشركة المطبوعة');
    }

    public function test_a_waybill_from_a_merchants_book_is_entered_for_that_merchant_only(): void
    {
        $this->book($this->alpha, 1);

        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['merchant_id' => $this->beta->id, 'waybill' => '90000001']))
            ->assertSessionHasErrors('merchant_id');

        $this->assertNull($this->shipmentWith('90000001'));
    }

    public function test_the_printed_number_finds_the_shipment_everywhere(): void
    {
        $this->book($this->alpha, 2);
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000001']))
            ->assertSessionHasNoErrors();
        $shipment = $this->shipmentWith('90000001');

        // جدول المسح، وقائمة الشحنات، وبحث تطبيق المندوب (Shipment::search)
        $this->actingAs($this->owner)->getJson($this->host().'/shipments/scan/lookup?number=90000001')
            ->assertOk()->assertJson(['id' => $shipment->id, 'number' => $shipment->number]);
        $this->actingAs($this->owner)->get($this->host().'/shipments?q=90000001')
            ->assertOk()->assertSee($shipment->number);
        $this->assertSame([$shipment->id], Tenancy::runFor($this->company,
            fn () => Shipment::query()->search('90000001')->pluck('id')->all()));

        // وصلٌ مطبوع لم تُدخَل شحنته: يُدلّ المسح على مكان إدخاله
        $this->actingAs($this->owner)->getJson($this->host().'/shipments/scan/lookup?number=90000002')
            ->assertNotFound()->assertJsonFragment(['error' => 'الوصل المطبوع 90000002 لم تُدخَل شحنته بعد — أدخِلها من «شحنة من وصلٍ مطبوع».']);
    }

    public function test_reprinting_a_book_leaves_out_what_was_used(): void
    {
        $book = $this->book($this->alpha, 3);
        $this->actingAs($this->owner)->post($this->host().'/shipments', $this->written(['waybill' => '90000002']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$book->id}/print")
            ->assertOk()
            ->assertSee(['90000001', '90000003'])
            ->assertDontSee('<span class="num">90000002</span>', false)
            ->assertSee('استُعمل من الدفتر وصل واحد');

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/waybills')
            ->assertOk()->assertSee('<span class="num font-semibold">1</span>', false);
    }

    public function test_the_company_writes_its_own_terms_for_the_waybill(): void
    {
        $book = $this->book($this->alpha, 1);

        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'primary_color' => '#0F766E',
            'waybill_terms' => "  البضاعة لا تُستبدل عند المندوب. \r\n\r\nالدفع نقداً عند الاستلام.",
        ])->assertSessionHasNoErrors();

        $this->company->refresh();
        $this->assertSame("البضاعة لا تُستبدل عند المندوب.\nالدفع نقداً عند الاستلام.", $this->company->setting('waybill.terms'));

        $this->actingAs($this->alphaUser)->get($this->host()."/portal/waybills/{$book->id}/print")
            ->assertOk()
            ->assertSee(['البضاعة لا تُستبدل عند المندوب.', 'الدفع نقداً عند الاستلام.'])
            ->assertDontSee(WaybillBook::DEFAULT_TERMS[0]);
    }

    public function test_only_staff_who_create_shipments_reach_the_waybill_screens(): void
    {
        $book = $this->book(null, 1);
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/shipments/waybill')->assertForbidden();
        $this->actingAs($accountant)->get($this->host().'/waybill-books')->assertForbidden();
        $this->actingAs($accountant)->get($this->host()."/waybill-books/{$book->id}/print")->assertForbidden();
        $this->actingAs($accountant)->post($this->host().'/waybill-books', ['size' => 5])->assertForbidden();

        // والتاجر لا يبلغ شاشات الموظّفين
        $this->actingAs($this->alphaUser)->get($this->host().'/waybill-books')->assertForbidden();
    }

    public function test_a_branch_enters_only_its_own_merchants_waybills(): void
    {
        [$basraMerchant, $clerk] = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);

            return [
                Merchant::create(['code' => 'M0200', 'business_name' => 'متجر البصرة', 'phone' => '07710000200',
                    'branch_id' => $basra->id, 'price_list_id' => $this->alpha->price_list_id, 'status' => 'active']),
                User::create(['name' => 'موظف البصرة', 'phone' => '07700000200', 'password' => 'password',
                    'role' => UserRole::BranchManager, 'branch_id' => $basra->id, 'is_active' => true,
                    'permissions' => Ability::all()]),
            ];
        });

        $baghdadBook = $this->book($this->alpha, 1);
        $basraBook = $this->book($basraMerchant, 1);

        $this->actingAs($clerk)->get($this->host().'/shipments/waybill?code='.$baghdadBook->firstCode())
            ->assertOk()->assertSee('هذا الوصل من دفتر تاجرٍ في فرعٍ آخر');
        $this->actingAs($clerk)->get($this->host().'/shipments/waybill?code='.$basraBook->firstCode())
            ->assertRedirect($this->host().'/shipments/create?waybill='.$basraBook->firstCode());

        // ودفاتر فرعه وحدها
        $this->actingAs($clerk)->get($this->host().'/waybill-books')
            ->assertOk()->assertSee($basraBook->firstCode())->assertDontSee($baghdadBook->firstCode().'–');
        $this->actingAs($clerk)->get($this->host()."/waybill-books/{$baghdadBook->id}/print")->assertNotFound();
    }
}
