<?php

namespace Tests\Feature\Support;

use App\Actions\Notify\Announce;
use App\Actions\Support\Converse;
use App\Actions\Shipments\CreateShipment;
use App\Enums\UserRole;
use App\Models\AppAd;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Courier;
use App\Models\Governorate;
use App\Models\GovernorateSetting;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * إعدادات المعتاد الصغيرة: واتساب لكل محافظة وهاتف الشكاوى، وإعلانات
 * الصفحة الرئيسية بالتطبيق، وسجلّ الإشعارات، وملفّات محادثة الشحنة.
 *
 * صغيرةٌ في الشاشة لا في الأثر: رقم البصرة الذي يظهر لزبون بغداد يضيّع
 * اتصالاً، وصورةٌ مرفوعة تُفتح لغير صاحبها تسريبٌ — فالاختبار على مَن
 * يرى ماذا.
 */
class SmallReferenceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Merchant $alpha;

    private Merchant $beta;

    private User $owner;

    private User $alphaUser;

    private User $betaUser;

    private User $courierUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->alpha = $this->makeMerchant($this->company, 'M0001');
        $this->beta = $this->makeMerchant($this->company, 'M0002');
        $this->owner = $this->makeUser($this->company);

        [$this->alphaUser, $this->betaUser, $this->courierUser] = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C1', 'name' => 'مندوب التوصيل', 'phone' => '07720000001',
                'type' => 'delivery', 'status' => 'active']);

            return [
                User::create(['name' => 'تاجر ألف', 'phone' => '07790000001', 'password' => 'password',
                    'role' => UserRole::Merchant, 'merchant_id' => $this->alpha->id, 'is_active' => true]),
                User::create(['name' => 'تاجر باء', 'phone' => '07790000002', 'password' => 'password',
                    'role' => UserRole::Merchant, 'merchant_id' => $this->beta->id, 'is_active' => true]),
                User::create(['name' => 'مندوب التوصيل', 'phone' => '07720000001', 'password' => 'password',
                    'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true]),
            ];
        });
    }

    private function host(?Company $company = null): string
    {
        return 'http://'.($company ?? $this->company)->slug.'.'.config('zajel.tenant_domain');
    }

    private function basra(): Governorate
    {
        return Governorate::where('code', 'BSR')->firstOrFail();
    }

    private function shipment(Merchant $merchant, ?Governorate $to = null): Shipment
    {
        return Tenancy::runFor($this->company, fn () => app(CreateShipment::class)->handle([
            'merchant_id' => $merchant->id, 'recipient_name' => 'علي', 'recipient_phone' => '07801234567',
            'governorate_id' => ($to ?? $this->baghdad())->id, 'address' => 'حيّ الجامعة', 'landmark' => 'قرب الجامع',
            'cod_amount' => 25_000,
        ], $this->owner));
    }

    /**
     * ملفٌّ مرفوعٌ حقيقيّ لا المزيَّف: المزيَّف يُعلن نوعه من اسمه، والحقيقيّ
     * يُفحص محتواه — وهو ما يواجهه النظام من صفحةٍ HTML سُمّيت «صورة.jpg».
     */
    private function disguised(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zajel-upload-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'كشف.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n");
    }

    // ── واتساب لكل محافظة، وهاتف الشكاوى ─────────────────────────────

    public function test_a_governorate_number_reaches_its_own_customers_and_the_rest_get_the_company_number(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'support_whatsapp' => '07701234567', 'primary_color' => '#0f766e',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post($this->host().'/governorate-settings', ['rows' => [
            $this->basra()->id => ['is_active' => '1', 'whatsapp' => '+964 780 111 2222'],
        ]])->assertSessionHasNoErrors();

        // صفٌّ لا يحمل غير الرقم يبقى — لم يُعامَل كالافتراض ويُحذف
        $this->assertSame('07801112222', Tenancy::runFor($this->company,
            fn () => GovernorateSetting::where('governorate_id', $this->basra()->id)->value('whatsapp')));

        $toBasra = $this->shipment($this->alpha, $this->basra());
        $toBaghdad = $this->shipment($this->alpha);

        $this->get($this->host().'/t/'.$toBasra->number.'/'.Tracking::token($toBasra))
            ->assertOk()->assertSee('https://wa.me/9647801112222', false)->assertDontSee('wa.me/9647701234567', false);
        $this->get($this->host().'/t/'.$toBaghdad->number.'/'.Tracking::token($toBaghdad))
            ->assertOk()->assertSee('https://wa.me/9647701234567', false);
    }

    public function test_a_merchant_in_a_governorate_with_its_own_number_is_sent_there(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/governorate-settings', ['rows' => [
            $this->basra()->id => ['is_active' => '1', 'whatsapp' => '07801112222'],
        ]])->assertSessionHasNoErrors();

        Tenancy::runFor($this->company, fn () => $this->alpha->forceFill(['governorate_id' => $this->basra()->id])->save());

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support')
            ->assertOk()->assertSee('https://wa.me/9647801112222', false);
    }

    public function test_a_governorate_number_that_is_not_iraqi_is_refused(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/governorate-settings', ['rows' => [
            $this->basra()->id => ['is_active' => '1', 'whatsapp' => '12345'],
        ]])->assertSessionHasErrors('rows.'.$this->basra()->id.'.whatsapp');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => GovernorateSetting::count()));
    }

    public function test_the_complaints_number_is_normalised_audited_and_shown_to_merchants_and_customers(): void
    {
        $this->actingAs($this->owner)->put($this->host().'/settings/company', [
            'support_complaints' => '+964 770 555 6666', 'primary_color' => $this->company->fresh()->primary_color,
        ])->assertSessionHasNoErrors();

        $this->assertSame('07705556666', $this->company->fresh()->setting('support.complaints'));

        $log = Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'company_settings_updated')->firstOrFail());
        $this->assertSame(['support_complaints' => '07705556666'], $log->new_values);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support')
            ->assertOk()->assertSee('tel:07705556666', false);

        $shipment = $this->shipment($this->alpha);
        auth()->logout();
        $this->get($this->host().'/t/'.$shipment->number.'/'.Tracking::token($shipment))
            ->assertOk()->assertSee('tel:07705556666', false);
    }

    // ── إعلانات الصفحة الرئيسية بالتطبيق ─────────────────────────────

    private function upload(string $audience = 'merchants', string $title = 'أسعار الشتاء'): AppAd
    {
        $this->actingAs($this->owner)->post($this->host().'/app-ads', [
            'title' => $title, 'audience' => $audience, 'sort_order' => 1,
            'image' => UploadedFile::fake()->image('ad.jpg', 800, 400),
        ])->assertSessionHasNoErrors();

        return Tenancy::runFor($this->company, fn () => AppAd::latest('id')->firstOrFail());
    }

    public function test_an_ad_shows_to_its_audience_only(): void
    {
        $ad = $this->upload('merchants');

        Storage::disk('local')->assertExists($ad->image_path);
        $this->assertStringStartsWith('ads/'.$this->company->id.'/', $ad->image_path);

        $image = route('app-ads.image', $ad, false);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal')->assertOk()->assertSee($image, false);
        $this->actingAs($this->courierUser)->get($this->host().'/courier')->assertOk()->assertDontSee($image, false);

        $this->actingAs($this->alphaUser)->get($this->host().$image)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->courierUser)->get($this->host().$image)->assertNotFound();
    }

    /** صورةٌ ضاعت من التخزين لا تظهر مكسورةً أعلى البوابة */
    public function test_an_ad_whose_image_is_gone_is_skipped(): void
    {
        $ad = $this->upload('merchants');
        Storage::disk('local')->delete($ad->image_path);

        $this->actingAs($this->alphaUser)->get($this->host().'/portal')
            ->assertOk()->assertDontSee(route('app-ads.image', $ad, false), false);
    }

    public function test_an_ad_for_everyone_shows_in_the_courier_app_too(): void
    {
        $ad = $this->upload('all');

        $this->actingAs($this->courierUser)->get($this->host().'/courier')
            ->assertOk()->assertSee(route('app-ads.image', $ad, false), false);
    }

    public function test_a_stopped_ad_disappears_and_its_image_is_closed_to_merchants(): void
    {
        $ad = $this->upload('merchants');
        $image = route('app-ads.image', $ad, false);

        $this->actingAs($this->owner)->put($this->host().'/app-ads/'.$ad->id, ['sort_order' => 1])->assertSessionHasNoErrors();

        $this->actingAs($this->alphaUser)->get($this->host().'/portal')->assertOk()->assertDontSee($image, false);
        $this->actingAs($this->alphaUser)->get($this->host().$image)->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().$image)->assertOk();
    }

    public function test_an_svg_is_not_accepted_as_an_ad(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/app-ads', [
            'title' => 'إعلان', 'audience' => 'merchants',
            'image' => UploadedFile::fake()->createWithContent('ad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => AppAd::count()));
    }

    public function test_deleting_an_ad_removes_its_image(): void
    {
        $ad = $this->upload();

        $this->actingAs($this->owner)->delete($this->host().'/app-ads/'.$ad->id)->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($ad->image_path);
        $this->assertSame(0, Tenancy::runFor($this->company, fn () => AppAd::count()));
    }

    public function test_another_companys_ad_image_is_not_served(): void
    {
        $ad = $this->upload();
        $other = $this->makeCompany('other', 'شركة أخرى');
        $stranger = $this->makeUser($other);

        $this->actingAs($stranger)->get($this->host($other).'/app-ads/'.$ad->id.'/image')->assertNotFound();
    }

    public function test_only_those_who_send_notifications_manage_ads(): void
    {
        $accountant = $this->makeUser($this->company, UserRole::Accountant);

        $this->actingAs($accountant)->get($this->host().'/app-ads')->assertForbidden();
        $this->actingAs($accountant)->post($this->host().'/app-ads', [
            'title' => 'إعلان', 'audience' => 'merchants', 'image' => UploadedFile::fake()->image('ad.jpg'),
        ])->assertForbidden();
    }

    // ── سجلّ الإشعارات ──────────────────────────────────────────────

    public function test_the_log_counts_by_app_and_shows_what_reached_one_user_and_whether_they_read_it(): void
    {
        $announce = app(Announce::class);
        $publish = fn (string $audience, string $title) => Tenancy::runFor($this->company,
            fn () => $announce->publish($audience, $title, 'التفاصيل', $this->owner));

        $forMerchants = $publish('merchants', 'تغيّر موعد الاستلام');
        // التاجر يفتح صندوقه فيقرأ الأوّل، وما يأتي بعده لم يره
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/inbox')->assertOk();
        $unread = $publish('merchants', 'عطلة الجمعة');
        $forCouriers = $publish('delivery_couriers', 'ابدأوا السابعة');

        $cs = $this->makeUser($this->company, UserRole::CustomerService);
        $all = $this->actingAs($cs)->get($this->host().'/reports/notifications')->assertOk();

        $this->assertSame(2, (int) $all->viewData('sent')['merchants']);
        $this->assertSame(1, (int) $all->viewData('sent')['delivery_couriers']);
        $this->assertSame(1, (int) $all->viewData('reads')['merchants']);
        $this->assertCount(3, $all->viewData('announcements'));

        $one = $this->actingAs($cs)->get($this->host().'/reports/notifications?user_id='.$this->alphaUser->id)->assertOk();
        $rows = collect($one->viewData('announcements')->items())->keyBy('id');

        $this->assertSame([$unread->id, $forMerchants->id], $rows->keys()->all(), 'للتاجر ما وُجِّه إلى التجّار وحده');
        $this->assertFalse($rows->has($forCouriers->id));
        $this->assertCount(1, $rows[$forMerchants->id]->reads, 'قرأه');
        $this->assertCount(0, $rows[$unread->id]->reads, 'لم يفتحه');
        $this->assertSame(1, $one->viewData('userRead'));

        $couriersOnly = $this->actingAs($cs)->get($this->host().'/reports/notifications?audience=delivery_couriers')->assertOk();
        $this->assertSame([$forCouriers->id], collect($couriersOnly->viewData('announcements')->items())->pluck('id')->all());
    }

    public function test_a_staff_member_cannot_be_picked_as_a_recipient(): void
    {
        $cs = $this->makeUser($this->company, UserRole::CustomerService);

        $page = $this->actingAs($cs)->get($this->host().'/reports/notifications?user_id='.$this->owner->id)->assertOk();

        $this->assertNull($page->viewData('user'));
    }

    // ── ملفّات المحادثة، ومحادثة الشحنة ─────────────────────────────

    private function ask(User $merchantUser, array $fields): Conversation
    {
        $this->actingAs($merchantUser)->post($this->host().'/portal/support', ['subject' => 'شحنة تالفة'] + $fields)
            ->assertSessionHasNoErrors();

        return Tenancy::runFor($this->company, fn () => Conversation::latest('id')->firstOrFail());
    }

    private function lastMessage(): ConversationMessage
    {
        return Tenancy::runFor($this->company, fn () => ConversationMessage::latest('id')->firstOrFail());
    }

    public function test_a_photo_alone_is_a_message_and_only_the_two_sides_open_it(): void
    {
        $conversation = $this->ask($this->alphaUser, ['attachment' => UploadedFile::fake()->image('تلف.jpg', 600, 600)]);
        $message = $this->lastMessage();

        $this->assertSame('', $message->body);
        $this->assertSame('image/jpeg', $message->attachment_mime);
        $this->assertSame('تلف.jpg', $message->attachment_name);
        $this->assertStringStartsWith('attachments/'.$this->company->id.'/', $message->attachment_path);
        Storage::disk('local')->assertExists($message->attachment_path);

        $staffFile = '/conversations/'.$conversation->id.'/files/'.$message->id;
        $portalFile = '/portal/support/'.$conversation->id.'/files/'.$message->id;

        $this->actingAs($this->owner)->get($this->host().$staffFile)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->alphaUser)->get($this->host().$portalFile)->assertOk();

        $this->actingAs($this->betaUser)->get($this->host().$portalFile)->assertNotFound();
        $this->actingAs($this->makeUser($this->company, UserRole::Accountant))->get($this->host().$staffFile)->assertForbidden();

        // الصورة في المحادثة نفسها للطرفين
        $this->actingAs($this->owner)->get($this->host().'/conversations/'.$conversation->id)->assertSee($staffFile, false);
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support/'.$conversation->id)->assertSee($portalFile, false);
    }

    public function test_a_lost_attachment_is_named_not_linked(): void
    {
        $conversation = $this->ask($this->alphaUser, ['body' => 'انظر', 'attachment' => UploadedFile::fake()->image('تلف.jpg')]);
        $message = $this->lastMessage();
        Storage::disk('local')->delete($message->attachment_path);

        $this->actingAs($this->owner)->get($this->host().'/conversations/'.$conversation->id)->assertOk()
            ->assertSee('أُرفق «تلف.jpg» ولم يعد متوفّراً.')
            ->assertDontSee('/files/'.$message->id, false);
    }

    public function test_a_pdf_reply_is_downloaded_not_opened_in_the_page(): void
    {
        $conversation = $this->ask($this->alphaUser, ['body' => 'أين كشفي؟']);

        $this->actingAs($this->owner)->post($this->host().'/conversations/'.$conversation->id.'/reply', [
            'body' => 'هذا كشفك', 'attachment' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $message = $this->lastMessage();
        $this->assertSame('application/pdf', $message->attachment_mime);

        $response = $this->actingAs($this->alphaUser)->get($this->host().'/portal/support/'.$conversation->id.'/files/'.$message->id)->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_file_that_is_not_an_image_or_pdf_is_refused(): void
    {
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/support', [
            'subject' => 'سؤال', 'body' => 'انظر',
            'attachment' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
        ])->assertSessionHasErrors('attachment');

        $this->actingAs($this->alphaUser)->post($this->host().'/portal/support', [
            'subject' => 'سؤال', 'body' => 'انظر',
            'attachment' => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertSessionHasErrors('attachment');

        // صفحةٌ باسم صورة: النوع من المحتوى لا من الاسم
        $this->actingAs($this->alphaUser)->post($this->host().'/portal/support', [
            'subject' => 'سؤال', 'body' => 'انظر',
            'attachment' => $this->disguised('<html><body><script>alert(1)</script></body></html>', 'photo.jpg'),
        ])->assertSessionHasErrors('attachment');

        $this->assertSame(0, Tenancy::runFor($this->company, fn () => Conversation::count()));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** والإجراء نفسه يفحص المحتوى، لا يتّكل على نموذجٍ يتحقّق قبله */
    public function test_the_action_itself_refuses_a_file_that_is_not_an_image_or_pdf(): void
    {
        $conversation = $this->ask($this->alphaUser, ['body' => 'سؤال']);

        try {
            Tenancy::runFor($this->company, fn () => app(Converse::class)->reply(
                $conversation, 'انظر', $this->owner, Converse::STAFF,
                $this->disguised('<html><body><script>alert(1)</script></body></html>', 'photo.jpg'),
            ));
            $this->fail('قُبل ملفٌّ ليس صورةً ولا PDF');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('attachment', $e->errors());
        }

        $this->assertSame(1, Tenancy::runFor($this->company, fn () => ConversationMessage::count()));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_neither_text_nor_file_is_still_an_empty_message(): void
    {
        $this->actingAs($this->alphaUser)
            ->post($this->host().'/portal/support', ['subject' => 'سؤال', 'body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_a_message_from_another_conversation_is_not_served_through_this_one(): void
    {
        $mine = $this->ask($this->alphaUser, ['body' => 'سؤالي']);
        $theirs = $this->ask($this->betaUser, ['attachment' => UploadedFile::fake()->image('x.png')]);
        $message = $this->lastMessage();

        $this->assertSame($theirs->id, $message->conversation_id);
        $this->actingAs($this->alphaUser)->get($this->host().'/portal/support/'.$mine->id.'/files/'.$message->id)->assertNotFound();
        $this->actingAs($this->owner)->get($this->host().'/conversations/'.$mine->id.'/files/'.$message->id)->assertNotFound();
    }

    public function test_both_shipment_pages_list_the_conversations_about_that_shipment(): void
    {
        $shipment = $this->shipment($this->alpha);
        $other = $this->shipment($this->alpha);
        $about = $this->ask($this->alphaUser, ['body' => 'متى تصل؟', 'shipment_number' => $shipment->number]);
        $this->ask($this->alphaUser, ['body' => 'وهذه؟', 'shipment_number' => $other->number]);

        $staff = $this->actingAs($this->owner)->get($this->host().'/shipments/'.$shipment->id)->assertOk();
        $this->assertSame([$about->id], $staff->viewData('conversations')->pluck('id')->all());

        $portal = $this->actingAs($this->alphaUser)->get($this->host().'/portal/shipments/'.$shipment->id)->assertOk();
        $this->assertSame([$about->id], $portal->viewData('conversations')->pluck('id')->all());
        $portal->assertSee('/portal/support?shipment_number='.$shipment->number, false);

        // المحاسب لا يردّ على المحادثات، فلا يراها على الشحنة
        $accountant = $this->makeUser($this->company, UserRole::Accountant);
        $this->assertCount(0, $this->actingAs($accountant)->get($this->host().'/shipments/'.$shipment->id)->assertOk()->viewData('conversations'));
    }
}
