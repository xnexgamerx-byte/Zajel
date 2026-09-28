<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Cash\ManageDeposit;
use App\Actions\Cash\RecordExpense;
use App\Actions\Notify\Announce;
use App\Actions\Returns\HandOverReturns;
use App\Actions\Returns\ReceiveReturns;
use App\Actions\Settlements\BuildCourierSettlement;
use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CreateShipment;
use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\CourierZone;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FailureReason;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\MerchantSettlement;
use App\Models\PickupRequest;
use App\Models\PriceList;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * الفرع يرى فرعه وحده، والفرع الرئيسي يرى الفروع كلّها.
 *
 * الحارس «كناري»: كل ما في الفرع الرئيسي يحمل كلمةً لا تتكرّر — تاجرٌ ومندوبٌ
 * وموظّفٌ وصندوقٌ ومصروفٌ وزبونٌ ومحادثةٌ وإعلان — ثم يفتح مدير فرع البصرة، بكل
 * الصلاحيات، كل شاشة GET في مسارات الموظّفين ولا يجوز أن تظهر الكلمة في أيٍّ
 * منها. شاشةٌ تُضاف غداً تدخل الفحص من تلقاء نفسها.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** ما في الفرع الرئيسي وحده — ومبلغ المصروف لا يشاركه فيه شيءٌ في البصرة */
    private const CANARIES = [
        'متجر كناري', 'مندوب كناري', 'موظف كناري', 'صندوق كناري', 'مصروف كناري',
        'زبون كناري', 'محادثة كناري', 'إعلان كناري', '987,654',
    ];

    private Company $company;

    private Branch $main;

    private Branch $basra;

    private User $owner;

    /** مدير فرع البصرة بكل الصلاحيات: فلا يُخفي المنعُ (403) غيابَ العزل */
    private User $clerk;

    /** موظّفٌ في الفرع الرئيسي: يرى الفروع كلّها */
    private User $mainManager;

    private Merchant $basraMerchant;

    private Courier $basraCourier;

    private CashBox $basraBox;

    private Shipment $basraShipment;

    /** شحنة تاجرٍ من الفرع الرئيسي وصلت مركز البصرة لتوزَّع هناك */
    private Shipment $arrived;

    /** @var array<string, int> سجلّات الفرع الرئيسي بأسماء معاملات مساراتها */
    private array $theirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->seed(\Database\Seeders\ExpenseCategorySeeder::class);
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $mainMerchant = $this->makeMerchant($this->company, 'M0001');   // الفرع الرئيسي B1
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () use ($mainMerchant) {
            $this->main = Branch::where('code', 'B1')->firstOrFail();
            $this->basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);
            $mainHub = Hub::create(['code' => 'BGD', 'name' => 'مركز بغداد', 'type' => 'main', 'branch_id' => $this->main->id, 'is_active' => true]);
            $basraHub = Hub::create(['code' => 'BSR', 'name' => 'مركز البصرة', 'type' => 'branch', 'branch_id' => $this->basra->id, 'is_active' => true]);
            $list = PriceList::where('is_default', true)->firstOrFail();

            $merchant = fn (string $code, string $name, Branch $branch) => Merchant::create([
                'code' => $code, 'business_name' => $name, 'phone' => $this->phoneFrom($code, '0771'),
                'branch_id' => $branch->id, 'price_list_id' => $list->id, 'status' => 'active',
            ]);
            $courier = fn (string $code, string $name, Branch $branch) => Courier::create([
                'code' => $code, 'name' => $name, 'phone' => $this->phoneFrom($code, '0772'),
                'type' => 'delivery', 'status' => 'active', 'branch_id' => $branch->id,
                'commission_per_delivery' => 1500, 'commission_per_return' => 750,
            ]);
            $user = fn (string $name, UserRole $role, Branch $branch, bool $everything = false) => User::create([
                'name' => $name, 'phone' => $this->phoneFrom($name), 'password' => 'password',
                'role' => $role, 'branch_id' => $branch->id, 'is_active' => true,
                'permissions' => $everything ? Ability::all() : null,
            ]);

            $canaryMerchant = $merchant('M0100', 'متجر كناري', $this->main);
            $canaryCourier = $courier('C0100', 'مندوب كناري', $this->main);
            $canaryUser = $user('موظف كناري', UserRole::Operations, $this->main);
            $this->basraMerchant = $merchant('M0200', 'متجر البصرة', $this->basra);
            $this->basraCourier = $courier('C0200', 'مندوب البصرة', $this->basra);
            $this->clerk = $user('مدير البصرة', UserRole::BranchManager, $this->basra, everything: true);
            $this->mainManager = $user('مدير بغداد', UserRole::BranchManager, $this->main, everything: true);

            $box = CashBox::create(['code' => 'MB', 'name' => 'صندوق كناري', 'type' => 'branch',
                'branch_id' => $this->main->id, 'balance' => 0, 'is_active' => true]);
            $this->basraBox = CashBox::create(['code' => 'BB', 'name' => 'صندوق البصرة', 'type' => 'branch',
                'branch_id' => $this->basra->id, 'balance' => 0, 'is_active' => true]);

            $expense = app(RecordExpense::class)->handle([
                'expense_category_id' => ExpenseCategory::where('code', 'fuel')->value('id'),
                'amount' => 987_654, 'description' => 'مصروف كناري', 'spent_on' => today()->toDateString(),
                'branch_id' => $this->main->id,
            ], $this->owner);

            // شحنات تاجر الكناري: مسلَّمة، وفاشلة تنتظر المعالجة، وراجعة سُلِّمت له
            $change = app(ChangeShipmentStatus::class);
            $make = fn (Merchant $m, string $recipient = 'زبون كناري') => app(CreateShipment::class)->handle([
                'merchant_id' => $m->id, 'recipient_name' => $recipient, 'recipient_phone' => '07801234567',
                'governorate_id' => $this->baghdad()->id, 'address' => 'الكرادة', 'landmark' => 'قرب الجامع',
                'cod_amount' => 50_000,
            ], $this->owner);
            $walk = function (Shipment $s, array $path) use ($change, $canaryCourier, $mainHub) {
                foreach ($path as $status) {
                    $change->handle($s->refresh(), $status, $this->owner, [
                        'courier_id' => $status === ShipmentStatus::OutForDelivery ? $canaryCourier->id : null,
                        'hub_id' => $status === ShipmentStatus::AtHub ? $mainHub->id : null,
                        'failure_reason_id' => $status === ShipmentStatus::FailedAttempt
                            ? FailureReason::where('code', 'no_answer')->value('id') : null,
                    ]);
                }

                return $s->refresh();
            };
            $out = [ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::OutForDelivery];
            $walk($make($canaryMerchant), [...$out, ShipmentStatus::Delivered]);
            $walk($make($canaryMerchant), [...$out, ShipmentStatus::FailedAttempt]);
            $returned = $walk($make($canaryMerchant), [...$out, ShipmentStatus::FailedAttempt, ShipmentStatus::Returning]);
            app(ReceiveReturns::class)->handle([$returned->id], $this->owner);
            $batch = app(HandOverReturns::class)->handle([$returned->id], $canaryMerchant, $this->owner);

            $this->theirs = [
                'merchant'        => $canaryMerchant->id,
                'courier'         => $canaryCourier->id,
                'user'            => $canaryUser->id,
                'box'             => $box->id,
                'expense'         => $expense->id,
                'batch'           => $batch->id,
                'courierSettlement'  => app(BuildCourierSettlement::class)->handle($canaryCourier, $this->owner)->id,
                'merchantSettlement' => app(BuildMerchantSettlement::class)->handle($canaryMerchant, $this->owner)->id,
                'pickup'          => PickupRequest::create([
                    'merchant_id' => $canaryMerchant->id, 'branch_id' => $this->main->id,
                    'number' => 'PU000100', 'status' => 'pending', 'expected_count' => 2,
                ])->id,
                'merchantRequest' => MerchantRequest::create([
                    'merchant_id' => $canaryMerchant->id, 'type' => 'payment', 'number' => 'REQ-100', 'status' => 'open',
                ])->id,
                'conversation'    => app(Converse::class)->start($canaryMerchant, 'محادثة كناري', 'متى يصل الطرد؟', $this->owner, Converse::STAFF)->id,
                'zone'            => CourierZone::create(['courier_id' => $canaryCourier->id, 'governorate_id' => $this->baghdad()->id])->id,
                'announcement'    => app(Announce::class)->publish('merchants', 'إعلان كناري', 'الاستلام غداً التاسعة.', $this->mainManager)->id,
            ];
            app(ManageDeposit::class)->deposit($canaryMerchant, 5_000, $this->owner, $box);

            // البصرة: شحنة تاجرها، وشحنة تاجرٍ من الرئيسي وصلت مركزها لتوزَّع
            $this->basraShipment = $make($this->basraMerchant, 'زبون البصرة');
            $this->arrived = $make($mainMerchant, 'زبون وصل البصرة');
            $change->handle($this->arrived->refresh(), ShipmentStatus::PickedUp, $this->owner);
            $change->handle($this->arrived->refresh(), ShipmentStatus::AtHub, $this->owner, ['hub_id' => $basraHub->id]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    /** @return array<int, string> */
    private function screens(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && in_array('staff', $route->gatherMiddleware(), true)
                && ! str_contains($route->uri(), '{'))
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->unique()
            ->values()
            ->all();
    }

    // ── الرؤية ──────────────────────────────────────────────────────

    public function test_a_branch_sees_nothing_of_the_main_branch_on_any_screen(): void
    {
        $screens = $this->screens();
        $this->assertGreaterThan(60, count($screens));

        $leaks = [];

        foreach ($screens as $uri) {
            $response = $this->actingAs($this->clerk)->get($this->host().$uri);

            if ($response->getStatusCode() >= 500) {
                $leaks[] = "{$uri} → {$response->getStatusCode()}";

                continue;
            }

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            foreach (self::CANARIES as $canary) {
                if (str_contains((string) $response->getContent(), $canary)) {
                    $leaks[] = "{$uri} ← «{$canary}»";
                }
            }
        }

        $this->assertSame([], $leaks, "شاشاتٌ يرى فيها الفرعُ ما ليس له:\n".implode("\n", $leaks));
    }

    public function test_a_branch_sees_its_own_work_including_what_arrived_at_its_hub(): void
    {
        $this->actingAs($this->clerk)->get($this->host().'/merchants')->assertOk()->assertSee('متجر البصرة');
        $this->actingAs($this->clerk)->get($this->host().'/couriers')->assertOk()->assertSee('مندوب البصرة');
        $this->actingAs($this->clerk)->get($this->host().'/shipments')->assertOk()
            ->assertSee($this->basraShipment->number)->assertSee($this->arrived->number);
    }

    public function test_the_main_branch_sees_every_branch(): void
    {
        $this->actingAs($this->mainManager)->get($this->host().'/merchants')->assertOk()
            ->assertSee('متجر البصرة')->assertSee('متجر كناري');
        $this->actingAs($this->mainManager)->get($this->host().'/couriers')->assertOk()
            ->assertSee('مندوب البصرة')->assertSee('مندوب كناري');
        $this->actingAs($this->mainManager)->get($this->host().'/shipments')->assertOk()
            ->assertSee($this->basraShipment->number);
    }

    /**
     * سجلٌّ من الفرع الرئيسي لا يُفتح ولا يُكتب عليه برقمه — في كل مسار موظّفين
     * يربط سجلّاً له فرع، من جدول المسارات لا من قائمةٍ يدوية.
     */
    public function test_a_branch_cannot_reach_the_main_branchs_records_by_id(): void
    {
        $open = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('staff', $route->gatherMiddleware(), true)
                || ! preg_match_all('/\{(\w+)\??\}/', $route->uri(), $params)) {
                continue;
            }

            $uri = $route->uri();

            foreach ($params[1] as $param) {
                $key = $param === 'settlement'
                    ? (str_contains($uri, 'couriers') ? 'courierSettlement' : 'merchantSettlement')
                    : $param;

                if (! isset($this->theirs[$key])) {
                    continue 2;
                }

                $uri = str_replace('{'.$param.'}', (string) $this->theirs[$key], $uri);
            }

            $method = strtolower(collect($route->methods())->first(fn ($m) => $m !== 'HEAD'));
            $status = $this->actingAs($this->clerk)->{$method}($this->host().'/'.$uri, [])->getStatusCode();
            $checked++;

            if (! in_array($status, [403, 404], true)) {
                $open[] = strtoupper($method)." /{$uri} → {$status}";
            }
        }

        $this->assertGreaterThan(20, $checked);
        $this->assertSame([], $open, "مساراتٌ تفتح سجلّ فرعٍ آخر برقمه:\n".implode("\n", $open));
    }

    // ── إعدادات الشركة للفرع الرئيسي ────────────────────────────────

    /** الفروع والتسعيرات والمراتب والموظّفون وبيانات الشركة: لصاحب الشركة وفرعه الرئيسي */
    public function test_company_settings_are_the_main_branchs_even_with_every_permission(): void
    {
        foreach (['/branches', '/pricing', '/areas', '/governorate-settings', '/permissions', '/users', '/settings/company'] as $uri) {
            $this->actingAs($this->clerk)->get($this->host().$uri)->assertForbidden();
            $this->actingAs($this->mainManager)->get($this->host().$uri)->assertOk();
        }
    }

    // ── ما يُكتب ────────────────────────────────────────────────────

    /** تاجرٌ ومندوبٌ وصندوقٌ يضيفه موظّف الفرع: لفرعه، ولو طلب فرعاً غيره */
    public function test_what_a_branch_adds_stays_in_it(): void
    {
        $post = fn (string $uri, array $data) => $this->actingAs($this->clerk)->post($this->host().$uri, $data)
            ->assertSessionHasNoErrors();

        $post('/merchants', ['business_name' => 'متجر الزبير', 'phone' => '07711112222', 'branch_id' => $this->main->id,
            'settlement_cycle' => 'weekly', 'payout_method' => 'cash', 'status' => 'active']);
        $post('/couriers', ['name' => 'مندوب الزبير', 'phone' => '07722223333', 'type' => 'delivery',
            'vehicle_type' => 'motorcycle', 'branch_id' => $this->main->id, 'status' => 'active']);
        $post('/cash', ['name' => 'نثرية الزبير', 'code' => 'ZB', 'type' => 'petty', 'branch_id' => $this->main->id]);

        Tenancy::runFor($this->company, function () {
            $this->assertSame($this->basra->id, (int) Merchant::where('business_name', 'متجر الزبير')->value('branch_id'));
            $this->assertSame($this->basra->id, (int) Courier::where('name', 'مندوب الزبير')->value('branch_id'));
            $this->assertSame($this->basra->id, (int) CashBox::where('code', 'ZB')->value('branch_id'));
        });
    }

    /** ولا يستعمل تاجر فرعٍ آخر ولا مندوبه ولا صندوقه برقمه، في أيّ نموذج */
    public function test_a_branch_cannot_use_another_branchs_merchant_courier_or_box(): void
    {
        $post = fn (string $uri, array $data) => $this->actingAs($this->clerk)->post($this->host().$uri, $data);

        $post('/shipments', [
            'merchant_id' => $this->theirs['merchant'], 'recipient_name' => 'زبون', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'الكرادة', 'landmark' => 'قرب الجامع',
            'pieces_count' => 1, 'cod_amount' => 10_000, 'fees_paid_by' => 'merchant',
        ])->assertSessionHasErrors('merchant_id');
        $post('/shipments/assign', ['shipment_ids' => [$this->basraShipment->id], 'courier_id' => $this->theirs['courier']])
            ->assertSessionHasErrors('courier_id');
        $post('/settlements/couriers', ['courier_id' => $this->theirs['courier']])->assertSessionHasErrors('courier_id');
        $post('/settlements/merchants', ['merchant_id' => $this->theirs['merchant']])->assertSessionHasErrors('merchant_id');
        $post('/returns/handover', ['merchant_id' => $this->theirs['merchant'], 'shipment_ids' => [$this->basraShipment->id]])
            ->assertSessionHasErrors('merchant_id');
        $post('/zones', ['courier_id' => $this->theirs['courier'], 'governorate_id' => $this->baghdad()->id])
            ->assertSessionHasErrors('courier_id');
        $post('/cash/transfer', ['from_box_id' => $this->basraBox->id, 'to_box_id' => $this->theirs['box'], 'amount' => 1])
            ->assertSessionHasErrors('to_box_id');
        $post('/expenses', [
            'expense_category_id' => Tenancy::runFor($this->company, fn () => ExpenseCategory::where('code', 'fuel')->value('id')),
            'amount' => 1_000, 'spent_on' => today()->toDateString(), 'description' => 'وقود', 'pay_now' => 1,
            'cash_box_id' => $this->theirs['box'],
        ])->assertSessionHasErrors('cash_box_id');

        // ولم يُفتح كشفٌ لمندوب الفرع الرئيسي أو تاجره، ولا تحرّك صندوقه
        Tenancy::runFor($this->company, function () {
            $this->assertSame(1, CourierSettlement::where('courier_id', $this->theirs['courier'])->count());
            $this->assertSame(1, MerchantSettlement::where('merchant_id', $this->theirs['merchant'])->count());
            $this->assertSame(0, CourierZone::where('courier_id', $this->theirs['courier'])->where('id', '!=', $this->theirs['zone'])->count());
        });
    }

    /** إعلان الفرع لتجّاره وحدهم، وإعلان الفرع الرئيسي للشركة كلّها */
    public function test_a_branch_announcement_reaches_its_own_merchants_only(): void
    {
        $this->actingAs($this->clerk)->post($this->host().'/announcements', [
            'audience' => 'merchants', 'title' => 'إعلان البصرة', 'body' => 'الاستلام غداً العاشرة.',
        ])->assertSessionHasNoErrors();

        [$basraMerchantUser, $mainMerchantUser] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'تاجر البصرة', 'phone' => '07790001111', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->basraMerchant->id, 'is_active' => true]),
            User::create(['name' => 'تاجر بغداد', 'phone' => '07790002222', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->theirs['merchant'], 'is_active' => true]),
        ]);

        $this->actingAs($basraMerchantUser)->get($this->host().'/portal/inbox')->assertOk()
            ->assertSee('إعلان البصرة')->assertSee('إعلان كناري');
        $this->actingAs($mainMerchantUser)->get($this->host().'/portal/inbox')->assertOk()
            ->assertDontSee('إعلان البصرة')->assertSee('إعلان كناري');
    }

    /** نقد البصرة لا يسقط إلى صندوق بغداد إن لم يكن للبصرة صندوقٌ مفعّل */
    public function test_a_branchs_cash_never_lands_in_another_branchs_box(): void
    {
        Tenancy::runFor($this->company, function () {
            $change = app(ChangeShipmentStatus::class);
            foreach ([ShipmentStatus::PickedUp, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered] as $status) {
                $change->handle($this->basraShipment->refresh(), $status, $this->owner, [
                    'courier_id' => $status === ShipmentStatus::OutForDelivery ? $this->basraCourier->id : null,
                ]);
            }
            $this->basraBox->forceFill(['is_active' => false])->save();
        });

        $before = Tenancy::runFor($this->company, fn () => (int) CashBox::whereKey($this->theirs['box'])->value('balance'));

        $this->actingAs($this->clerk)->post($this->host().'/settlements/couriers', ['courier_id' => $this->basraCourier->id])
            ->assertSessionHasNoErrors();
        $settlement = Tenancy::runFor($this->company, fn () => CourierSettlement::where('courier_id', $this->basraCourier->id)->firstOrFail());
        $this->actingAs($this->clerk)->post($this->host()."/settlements/couriers/{$settlement->id}/confirm")
            ->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, function () use ($settlement, $before) {
            $this->assertSame('confirmed', $settlement->refresh()->status);
            $this->assertSame($before, (int) CashBox::whereKey($this->theirs['box'])->value('balance'));
        });
    }

    /** تسعير تاجرٍ بعينه: لا لتاجرٍ يقرأ أسعار غيره، ولا لفرعٍ يقرأ أسعار تاجر فرعٍ آخر */
    public function test_a_merchants_prices_are_quoted_to_its_own_staff_only(): void
    {
        $quote = ['merchant_id' => $this->theirs['merchant'], 'governorate_id' => $this->baghdad()->id, 'fees_paid_by' => 'merchant'];
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر البصرة', 'phone' => '07790003333', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->basraMerchant->id, 'is_active' => true,
        ]));

        $this->actingAs($merchantUser)->postJson($this->host().'/quote', $quote)->assertForbidden();
        $this->actingAs($this->clerk)->postJson($this->host().'/quote', $quote)->assertNotFound();
        $this->actingAs($this->mainManager)->postJson($this->host().'/quote', $quote)->assertOk();
    }
}
