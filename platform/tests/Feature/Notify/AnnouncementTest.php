<?php

namespace Tests\Feature\Notify;

use App\Actions\Notify\Announce;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الإشعارات الجماعية.
 *
 * إعلانٌ يصل مَن لا يعنيه ضجيج، وإعلانٌ لا يصل مَن يعنيه أسوأ منه:
 * المرسِل يظنّ أنه أبلغ. فالاختبار على مَن يرى ماذا، وعلى أن «قرأه»
 * صادقة.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $deliveryUser;

    private User $pickupUser;

    private User $bothUser;

    private User $merchantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $merchant = $this->makeMerchant($this->company);
        $this->owner = $this->makeUser($this->company);

        Tenancy::runFor($this->company, function () use ($merchant) {
            $login = function (string $type, string $phone) {
                $courier = Courier::create([
                    'code' => 'C'.$phone, 'name' => 'مندوب '.$type, 'phone' => $phone,
                    'type' => $type, 'status' => 'active',
                ]);

                return User::create([
                    'name' => $courier->name, 'phone' => $phone, 'password' => 'password',
                    'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true,
                ]);
            };

            $this->deliveryUser = $login('delivery', '07720000001');
            $this->pickupUser = $login('pickup', '07720000002');
            $this->bothUser = $login('both', '07720000003');

            $this->merchantUser = User::create([
                'name' => 'تاجر', 'phone' => '07790000001', 'password' => 'password',
                'role' => UserRole::Merchant, 'merchant_id' => $merchant->id, 'is_active' => true,
            ]);
        });
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    private function announce(string $audience, string $title = 'الدوام غداً يبدأ السابعة', ?\DateTimeInterface $expires = null): Announcement
    {
        return Tenancy::runFor($this->company, fn () => app(Announce::class)
            ->publish($audience, $title, 'نصّ الإعلان', $this->owner, $expires));
    }

    private function inbox(User $user): \Illuminate\Support\Collection
    {
        $path = $user->role === UserRole::Merchant ? '/portal/inbox' : '/courier/inbox';

        return $this->actingAs($user)->get($this->host().$path)->assertOk()->viewData('announcements');
    }

    // ── مَن يرى ماذا ────────────────────────────────────────────────

    public function test_each_audience_sees_only_what_was_addressed_to_it(): void
    {
        $delivery = $this->announce('delivery_couriers');
        $pickup = $this->announce('pickup_couriers');
        $merchants = $this->announce('merchants');

        $this->assertSame([$delivery->id], $this->inbox($this->deliveryUser)->pluck('id')->all());
        $this->assertSame([$pickup->id], $this->inbox($this->pickupUser)->pluck('id')->all());
        $this->assertSame([$merchants->id], $this->inbox($this->merchantUser)->pluck('id')->all());
    }

    /** مندوبٌ يستلم ويوصّل يعنيه الإعلانان. */
    public function test_a_courier_who_does_both_sees_both(): void
    {
        $this->announce('delivery_couriers');
        $this->announce('pickup_couriers');

        $this->assertCount(2, $this->inbox($this->bothUser));
    }

    /** الجمهور يُقرأ عند العرض: مَن عُيّن بعد الإعلان يراه. */
    public function test_a_courier_hired_after_the_announcement_still_sees_it(): void
    {
        $announcement = $this->announce('delivery_couriers', 'العمولة تتغيّر من الشهر القادم');

        $late = Tenancy::runFor($this->company, function () {
            $courier = Courier::create(['code' => 'C9', 'name' => 'جديد', 'phone' => '07720000009',
                'type' => 'delivery', 'status' => 'active']);

            return User::create(['name' => 'جديد', 'phone' => '07720000009', 'password' => 'password',
                'role' => UserRole::Courier, 'courier_id' => $courier->id, 'is_active' => true]);
        });

        $this->assertContains($announcement->id, $this->inbox($late)->pluck('id'));
    }

    public function test_an_expired_announcement_disappears(): void
    {
        $gone = $this->announce('delivery_couriers', 'قديم', now()->addHour());
        $this->travel(2)->hours();

        $this->assertNotContains($gone->id, $this->inbox($this->deliveryUser)->pluck('id'));
    }

    public function test_an_announcement_that_expires_before_it_is_sent_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->announce('merchants', 'متأخّر', now()->subMinute());
    }

    // ── القراءة ─────────────────────────────────────────────────────

    public function test_opening_the_inbox_is_reading_it_and_counts_once(): void
    {
        $announcement = $this->announce('delivery_couriers');

        $first = $this->actingAs($this->deliveryUser)->get($this->host().'/courier/inbox');
        $this->assertSame([$announcement->id], $first->viewData('fresh'), 'الجديد جديدٌ في الفتح الأول');

        $second = $this->actingAs($this->deliveryUser)->get($this->host().'/courier/inbox');
        $this->assertSame([], $second->viewData('fresh'), 'وليس جديداً في الثاني');

        Tenancy::runFor($this->company, fn () => $this->assertSame(
            1, AnnouncementRead::where('announcement_id', $announcement->id)->count(),
        ));
    }

    public function test_the_unread_bell_counts_and_then_clears(): void
    {
        $this->announce('merchants', 'أول');
        $this->announce('merchants', 'ثانٍ');

        $this->actingAs($this->merchantUser)->get($this->host().'/portal')
            ->assertOk()->assertSee('غير المقروء 2');

        $this->inbox($this->merchantUser);

        $this->actingAs($this->merchantUser)->get($this->host().'/portal')
            ->assertOk()->assertDontSee('غير المقروء');
    }

    /** «قرأه ٢ من ٣»: البلوغ مَن يحمل الدور ويعمل، لا كل مَن في الجدول. */
    public function test_the_sender_sees_how_many_of_the_audience_read_it(): void
    {
        $announcement = $this->announce('delivery_couriers');

        $this->inbox($this->deliveryUser);
        $this->inbox($this->bothUser);

        $response = $this->actingAs($this->owner)
            ->get($this->host().'/announcements/'.$announcement->id)
            ->assertOk();

        // مندوب التوصيل و«كلاهما» — مندوب الاستلام ليس من الجمهور
        $this->assertSame(2, $response->viewData('reach'));
        $this->assertCount(2, $response->viewData('announcement')->reads);
    }

    // ── الحراسة ─────────────────────────────────────────────────────

    public function test_staff_publish_from_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->post($this->host().'/announcements', [
                'audience' => 'merchants', 'title' => 'عطلة العيد', 'body' => 'لا استلام يومَي العيد.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->inbox($this->merchantUser));
    }

    public function test_customer_service_cannot_broadcast_by_default(): void
    {
        $cs = $this->makeUser($this->company, UserRole::CustomerService);

        $this->actingAs($cs)->post($this->host().'/announcements', [
            'audience' => 'merchants', 'title' => 'تجربة', 'body' => 'تجربة',
        ])->assertForbidden();
    }

    public function test_a_courier_cannot_reach_the_compose_screen(): void
    {
        $this->actingAs($this->deliveryUser)->get($this->host().'/announcements')->assertForbidden();
    }

    public function test_one_companys_announcement_never_reaches_anothers_merchants(): void
    {
        $this->announce('merchants');

        $other = $this->makeCompany('barq', 'البرق');
        $otherMerchant = $this->makeMerchant($other, 'M0009');
        $otherUser = Tenancy::runFor($other, fn () => User::create([
            'name' => 'تاجر البرق', 'phone' => '07790000099', 'password' => 'password',
            'role' => UserRole::Merchant, 'merchant_id' => $otherMerchant->id, 'is_active' => true,
        ]));

        $this->assertCount(0, $this->actingAs($otherUser)
            ->get('http://barq.'.config('zajel.tenant_domain').'/portal/inbox')
            ->viewData('announcements'));
    }
}
