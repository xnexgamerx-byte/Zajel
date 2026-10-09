<?php

namespace Tests\Feature\Support;

use App\Actions\Support\Converse;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Merchant;
use App\Models\User;
use App\Support\DeliveryDeadline;
use App\Support\MerchantHours;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التاجر يراسل الشركة من ٩ صباحاً إلى ١١ ليلاً، وخارجها لا تُرسَل رسالته (docs/plan/39).
 */
class MerchantHoursTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $merchant;

    private User $owner;

    private User $merchantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);
        $this->merchantUser = Tenancy::runFor($this->company, fn () => User::create([
            'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $this->merchant->id, 'is_active' => true,
        ]));
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function at(int $hour, int $minute = 0): void
    {
        $this->travelTo(now()->setTime($hour, $minute));
    }

    public function test_a_merchant_cannot_write_after_eleven_at_night_and_sees_when_it_opens(): void
    {
        $this->at(23, 5);

        $this->actingAs($this->merchantUser)->get($this->host().'/portal/support')
            ->assertOk()
            ->assertSee('المراسلة مغلقة الآن')
            ->assertSee('تُفتح غداً الساعة 9 صباحاً')
            ->assertSee('من 9 صباحاً إلى 11 ليلاً');

        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support', [
            'subject' => 'شحنة لم تصل', 'body' => 'وين صارت؟',
        ])->assertSessionHasErrors(['body' => MerchantHours::closedMessage($this->company)]);

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Conversation::count()));
    }

    public function test_the_window_opens_at_nine_and_closes_at_eleven(): void
    {
        $this->at(9);
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support', [
            'subject' => 'سؤال', 'body' => 'متى الحساب؟',
        ])->assertSessionHasNoErrors();

        $conversation = Tenancy::runFor($this->company, fn () => Conversation::sole());

        // قبل التاسعة بدقيقة: الردّ لا يُرسَل، ويرى أنها تُفتح اليوم
        $this->at(8, 59);
        $this->actingAs($this->merchantUser)->get($this->host().'/portal/support/'.$conversation->id)
            ->assertSee('تُفتح اليوم الساعة 9 صباحاً');
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support/'.$conversation->id.'/reply', ['body' => 'أنتظر'])
            ->assertSessionHasErrors('body');

        // العاشرة و٥٩ دقيقة: آخر ما يُرسَل
        $this->at(22, 59);
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support/'.$conversation->id.'/reply', ['body' => 'شكراً'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Tenancy::runFor($this->company, fn () => ConversationMessage::count()));
    }

    public function test_staff_reply_at_any_hour(): void
    {
        $this->at(12);
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support', ['subject' => 'سؤال', 'body' => 'مرحباً']);
        $conversation = Tenancy::runFor($this->company, fn () => Conversation::sole());

        $this->at(2);
        $this->actingAs($this->owner)->post($this->host().'/conversations/'.$conversation->id.'/reply', ['body' => 'أهلاً'])
            ->assertSessionHasNoErrors();

        // ولا بابَ خلفيّاً للتاجر: القيد في الفعل نفسه
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        Tenancy::runFor($this->company, fn () => app(Converse::class)
            ->reply($conversation, 'رسالة', $this->merchantUser, Converse::MERCHANT));
    }

    public function test_the_company_sets_its_own_hours_and_delivery_deadline(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/settings/company')
            ->assertOk()->assertSee('ساعات مراسلة التجّار')->assertSee('آخر موعد للتوصيل');

        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'primary_color' => '#0F766E', 'merchant_from' => 10, 'merchant_to' => 10,
        ])->assertSessionHasErrors('merchant_to');

        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'primary_color' => '#0F766E', 'merchant_from' => 0, 'merchant_to' => 24, 'deadline_hours' => 36,
        ])->assertSessionHasNoErrors();

        $company = $this->company->refresh();
        $this->assertTrue(MerchantHours::allDay($company));
        $this->assertSame('على مدار اليوم', MerchantHours::window($company));
        $this->assertSame(36, DeliveryDeadline::hours($company));

        // على مدار اليوم: الثالثة فجراً تُرسَل
        $this->at(3);
        $this->actingAs($this->merchantUser)->post($this->host().'/portal/support', ['subject' => 'سؤال', 'body' => 'مرحباً'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_defaults_and_the_hour_words(): void
    {
        $this->assertSame([9, 23], [MerchantHours::from($this->company), MerchantHours::to($this->company)]);
        $this->assertSame(24, DeliveryDeadline::hours($this->company));
        $this->assertSame(
            ['12 ليلاً', '9 صباحاً', '12 ظهراً', '4 عصراً', '7 مساءً', '11 ليلاً'],
            array_map(fn ($h) => MerchantHours::label($h), [0, 9, 12, 16, 19, 23]),
        );
    }
}
