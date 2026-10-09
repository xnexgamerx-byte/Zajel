<?php

namespace Tests\Feature\Support;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Rank;
use App\Models\StaffMessage;
use App\Models\User;
use App\Support\Permissions\Ability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مراسلة الموظّفين داخل النظام (docs/plan/41): موظّفٌ لموظّف، أو لقسمٍ كلّه يراه كل
 * من فيه — وما لم يُقرأ يُعدّ على القائمة لكلٍّ وحده.
 */
class StaffChatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $returnsOne;

    private User $returnsTwo;

    private User $accountant;

    private Rank $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () {
            $this->returns = Rank::create(['name' => 'موظّف الراجع', 'abilities' => [Ability::RETURNS_MANAGE]]);
            $staff = fn (string $name, string $phone, UserRole $role, ?int $rank = null) => User::create([
                'name' => $name, 'phone' => $phone, 'password' => 'password', 'role' => $role,
                'rank_id' => $rank, 'is_active' => true,
            ]);
            $this->returnsOne = $staff('حسن الراجع', '07700000101', UserRole::Operations, $this->returns->id);
            $this->returnsTwo = $staff('زينب الراجع', '07700000102', UserRole::Operations, $this->returns->id);
            $this->accountant = $staff('سعد المحاسب', '07700000103', UserRole::Accountant);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function unread(User $user): int
    {
        return Tenancy::runFor($this->company, fn () => app(\App\Actions\Support\StaffChat::class)->unreadCount($user));
    }

    public function test_a_message_to_a_department_reaches_everyone_in_it(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/messages?tab=chat')->assertOk()
            ->assertSee('قسم موظّف الراجع')->assertSee('قسم محاسب')->assertSee('سعد المحاسب');

        $this->actingAs($this->owner)->post($this->host().'/messages', [
            'to' => 'rank:'.$this->returns->id, 'body' => 'وصل الراجع للفرع الرئيسي؟',
        ])->assertRedirect();

        // كل من في القسم يراه جديداً، ومن خارجه لا يراه
        $this->assertSame(1, $this->unread($this->returnsOne));
        $this->assertSame(1, $this->unread($this->returnsTwo));
        $this->assertSame(0, $this->unread($this->accountant));
        $this->assertSame(0, $this->unread($this->owner));

        $thread = StaffMessage::withoutGlobalScopes()->firstOrFail()->staff_thread_id;
        $this->actingAs($this->accountant)->get($this->host().'/messages?thread='.$thread)
            ->assertOk()->assertDontSee('وصل الراجع للفرع الرئيسي؟');

        // أحدهم يقرأ ويردّ: الردّ يصل كاتب الرسالة، ويبقى جديداً عند زميله
        $this->actingAs($this->returnsOne)->get($this->host().'/messages?thread='.$thread)
            ->assertOk()->assertSee('وصل الراجع للفرع الرئيسي؟')->assertSee('قسم موظّف الراجع');
        $this->assertSame(0, $this->unread($this->returnsOne));

        $this->actingAs($this->returnsOne)->post($this->host().'/messages', ['thread_id' => $thread, 'body' => 'نعم، وصل اليوم.']);
        $this->assertSame(1, $this->unread($this->owner));
        $this->assertSame(1, $this->unread($this->returnsTwo));
        $this->assertSame(0, $this->unread($this->returnsOne));
    }

    public function test_a_message_to_one_employee_is_between_the_two_of_them(): void
    {
        $this->actingAs($this->returnsOne)->post($this->host().'/messages', [
            'to' => 'user:'.$this->accountant->id, 'body' => 'متى تُصرف أجور المناديب؟',
        ])->assertRedirect();

        $this->assertSame(1, $this->unread($this->accountant));
        $this->assertSame(0, $this->unread($this->returnsTwo));
        $this->assertSame(0, $this->unread($this->owner));

        // والرسالة الثانية بينهما في المحادثة نفسها
        $this->actingAs($this->accountant)->post($this->host().'/messages', [
            'to' => 'user:'.$this->returnsOne->id, 'body' => 'الخميس.',
        ]);
        $this->assertSame(1, StaffMessage::withoutGlobalScopes()->distinct()->count('staff_thread_id'));

        // والعدّاد على القائمة
        $this->actingAs($this->returnsOne)->get($this->host().'/')->assertOk()->assertSee($this->host().'/messages', false);
        $this->actingAs($this->returnsOne)->get($this->host().'/messages?tab=chat')->assertOk()
            ->assertSee('سعد المحاسب')->assertSee('جديد');
    }

    public function test_a_message_can_name_its_shipment_and_strangers_are_refused(): void
    {
        $merchant = $this->makeMerchant($this->company);
        $shipment = Tenancy::runFor($this->company, fn () => app(\App\Actions\Shipments\CreateShipment::class)->handle([
            'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'cod_amount' => 50_000,
        ], $this->owner));

        $this->actingAs($this->owner)->post($this->host().'/messages', [
            'to' => 'user:'.$this->accountant->id, 'body' => 'هذا الوصل', 'shipment' => $shipment->number,
        ]);
        $this->actingAs($this->accountant)->get($this->host().'/messages?thread='.StaffMessage::withoutGlobalScopes()->value('staff_thread_id'))
            ->assertSee($shipment->number);

        $this->actingAs($this->owner)->post($this->host().'/messages', ['to' => 'user:999999', 'body' => 'x'])
            ->assertSessionHasErrors('to');
        $this->actingAs($this->owner)->post($this->host().'/messages', ['to' => 'rank:999999', 'body' => 'x'])
            ->assertSessionHasErrors('to');
        $this->actingAs($this->owner)->post($this->host().'/messages', ['to' => 'user:'.$this->accountant->id, 'body' => 'x', 'shipment' => 'NOPE'])
            ->assertSessionHasErrors('shipment');

        // التاجر ليس موظّفاً: لا يدخل
        $merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000009', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
        ]));
        $this->actingAs($merchantUser)->get($this->host().'/messages')->assertForbidden();
    }

    /** واتساب بالأساس: الموظّفون وجنب كلٍّ زرّ محادثته، ومن صفحة الشحنة برقمها */
    public function test_the_employees_are_listed_with_a_whatsapp_button(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/messages')->assertOk()
            ->assertSee('الموظفون — واتساب')
            ->assertSee('سعد المحاسب')->assertSee('زينب الراجع')
            ->assertSee('https://wa.me/9647700000103', false)
            ->assertSee(route('staff-chat.index', ['to' => 'user:'.$this->accountant->id]), false);

        $this->actingAs($this->owner)->get($this->host().'/messages?q=سعد')->assertOk()
            ->assertSee('سعد المحاسب')->assertDontSee('زينب الراجع');

        $this->actingAs($this->owner)->get($this->host().'/messages?shipment=000118')->assertOk()
            ->assertSee(e(\App\Support\Phone::whatsappUrl('07700000103', 'بخصوص الشحنة 000118: ')), false);
    }
}
