<?php

namespace Tests\Feature\Support;

use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المحادثات وواتساب الدعم.
 *
 * السؤال الذي يضيع أسوأ من السؤال الذي لا يُسأل: التاجر يظنّ أنه سأل،
 * والشركة لا تعرف أن أحداً ينتظر. فالاختبار على أن «ينتظر ردّنا» صادقة،
 * وأن محادثة تاجرٍ لا تُفتح لغيره.
 */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $owner;

    private User $alphaUser;

    private User $betaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        [$this->alphaUser, $this->betaUser] = Tenancy::runFor($this->company, fn () => [
            User::create(['name' => 'تاجر ألف', 'phone' => '07790000001', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true]),
            User::create(['name' => 'تاجر باء', 'phone' => '07790000002', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $this->beta->id, 'is_active' => true]),
        ]);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function ask(User $merchantUser, string $subject = 'شحنة لم تصل', array $extra = []): Conversation
    {
        $this->actingAs($merchantUser)
            ->post($this->host().'/portal/support', ['subject' => $subject, 'body' => 'أين شحنتي؟'] + $extra)
            ->assertSessionHasNoErrors();

        return Tenancy::runFor($this->company, fn () => Conversation::latest('id')->firstOrFail());
    }

    private function fresh(Conversation $conversation): Conversation
    {
        return Tenancy::runFor($this->company, fn () => $conversation->fresh());
    }

    // ── الدورة ──────────────────────────────────────────────────────

    public function test_a_merchants_question_waits_for_us_until_answered(): void
    {
        $conversation = $this->ask($this->alphaUser);

        $waiting = $this->actingAs($this->owner)->get($this->host().'/conversations')->assertOk();
        $this->assertSame([$conversation->id], $waiting->viewData('conversations')->pluck('id')->all());
        $this->assertTrue($this->fresh($conversation)->staff_unread);

        $this->actingAs($this->owner)->get($this->host().'/conversations/'.$conversation->id)->assertOk();
        $this->assertFalse($this->fresh($conversation)->staff_unread, 'فتحها الموظّف فصارت مقروءة');
        $this->assertTrue($this->fresh($conversation)->awaitsUs(), 'مقروءة لكنها ما زالت بلا جواب');

        $this->actingAs($this->owner)->post($this->host().'/conversations/'.$conversation->id.'/reply', ['body' => 'في الطريق إليك.']);

        $answered = $this->fresh($conversation);
        $this->assertFalse($answered->awaitsUs());
        $this->assertTrue($answered->merchant_unread);
        $this->assertCount(0, $this->actingAs($this->owner)->get($this->host().'/conversations')->viewData('conversations'));
    }

    public function test_the_merchant_reads_the_reply_and_the_badge_clears(): void
    {
        $conversation = $this->ask($this->alphaUser);
        $this->actingAs($this->owner)->post($this->host().'/conversations/'.$conversation->id.'/reply', ['body' => 'وصلت.']);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support/'.$conversation->id)
            ->assertOk()->assertSee('وصلت.');

        $this->assertFalse($this->fresh($conversation)->merchant_unread);
    }

    public function test_a_reply_to_a_closed_conversation_reopens_it(): void
    {
        $conversation = $this->ask($this->alphaUser);
        $this->actingAs($this->owner)->post($this->host().'/conversations/'.$conversation->id.'/close');
        $this->assertFalse($this->fresh($conversation)->isOpen());

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/support/'.$conversation->id.'/reply', ['body' => 'ما زالت لم تصل!']);

        $this->assertTrue($this->fresh($conversation)->awaitsUs());
    }

    public function test_the_company_can_start_a_conversation_too(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/conversations', [
            'merchant_id' => $this->alpha->id, 'subject' => 'عنوان ناقص', 'body' => 'أكمِل عنوان الزبون.',
        ])->assertSessionHasNoErrors();

        $conversation = Tenancy::runFor($this->company, fn () => Conversation::firstOrFail());

        $this->assertTrue($conversation->merchant_unread);
        $this->assertFalse($conversation->awaitsUs());
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/support', ['subject' => 'سؤال', 'body' => '   '])
            ->assertSessionHasErrors('body');
    }

    // ── العزل ───────────────────────────────────────────────────────

    public function test_a_merchant_cannot_open_another_merchants_conversation(): void
    {
        $conversation = $this->ask($this->alphaUser);

        $this->actingAs($this->betaUser)->get($this->host().'/portal/support/'.$conversation->id)->assertNotFound();
        $this->actingAs($this->betaUser)
            ->post($this->host().'/portal/support/'.$conversation->id.'/reply', ['body' => 'تطفّل'])
            ->assertNotFound();
    }

    /** وصل تاجرٍ آخر لا يُعلَّق بمحادثته — ولو عرف رقمه. */
    public function test_a_merchant_cannot_attach_another_merchants_shipment(): void
    {
        $theirs = Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $this->beta->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => $this->baghdad()->id, 'address' => 'بغداد', 'landmark' => 'قرب الجامع', 'cod_amount' => 10_000,
        ], $this->owner));

        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/support', ['subject' => 'سؤال', 'body' => 'عن هذه', 'shipment_number' => $theirs->number])
            ->assertSessionHasErrors('shipment_number');
    }

    public function test_branch_staff_see_only_their_branchs_merchants(): void
    {
        $other = Tenancy::runFor($this->company, function () {
            $basra = Branch::create(['code' => 'B2', 'name' => 'فرع البصرة', 'is_active' => true]);

            return User::create(['name' => 'خدمة البصرة', 'phone' => '07790000009', 'password' => 'password',
                'role' => UserRole::CustomerService, 'branch_id' => $basra->id, 'is_active' => true]);
        });

        $conversation = $this->ask($this->alphaUser);   // تاجرٌ من فرع بغداد

        $this->assertCount(0, $this->actingAs($other)->get($this->host().'/conversations')->viewData('conversations'));
        $this->actingAs($other)->get($this->host().'/conversations/'.$conversation->id)->assertNotFound();
    }

    public function test_customer_service_answers_and_the_accountant_does_not(): void
    {
        $conversation = $this->ask($this->alphaUser);

        $cs = $this->makeUser($this->company, UserRole::CustomerService);
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($cs)->get($this->host().'/conversations/'.$conversation->id)->assertOk();
        $this->actingAs($accountant)->get($this->host().'/conversations/'.$conversation->id)->assertForbidden();
    }

    // ── واتساب الدعم ────────────────────────────────────────────────

    public function test_the_support_number_is_stored_in_one_form_and_offered_as_a_whatsapp_link(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'support_whatsapp' => '+964 770 123 4567', 'primary_color' => '#0f766e',
        ])->assertSessionHasNoErrors();

        $company = $this->company->fresh();
        $this->assertSame('07701234567', $company->setting('support.whatsapp'));
        $this->assertSame('#0F766E', $company->primary_color);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support')
            ->assertOk()->assertSee('https://wa.me/9647701234567', false);
    }

    public function test_a_number_that_is_not_iraqi_is_refused(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'support_whatsapp' => '12345', 'primary_color' => '#0f766e',
        ])->assertSessionHasErrors('support_whatsapp');
    }

    /** الاسم هويّة الاشتراك: يُتجاهَل لو أُرسل. */
    public function test_the_company_name_cannot_be_changed_from_here(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'name' => 'اسمٌ آخر', 'slug' => 'other', 'primary_color' => '#0f766e',
        ]);

        $this->assertSame('الزاجل', $this->company->fresh()->name);
        $this->assertSame('zajel', $this->company->fresh()->slug);
    }

    public function test_a_change_is_audited_with_only_what_changed(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            // اللون كما هو في القاعدة (النموذج المُنشأ لا يحمل قيمتها الافتراضية)
            'support_whatsapp' => '07701234567', 'primary_color' => $this->company->fresh()->primary_color,
        ]);

        $log = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'company_settings_updated')->firstOrFail());

        $this->assertSame(['support_whatsapp' => '07701234567'], $log->new_values);
        $this->assertSame($this->company->id, (int) $log->company_id);
    }

    public function test_customer_service_cannot_edit_the_company(): void
    {
        $cs = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($cs)->get($this->host().'/settings/company')->assertForbidden();
    }
}
