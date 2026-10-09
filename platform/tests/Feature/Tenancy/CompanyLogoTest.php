<?php

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * لوغو الشركة (docs/plan/45): يُرفع من الإعدادات صورةً من الهاتف أو الحاسوب، ويحلّ محلّ الحرف في
 * الرأس؛ وقبل رفعه يرى صاحب الإعدادات «ارفع اللوغو هنا» وغيره الحرف.
 */
class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedReference();
        $this->company = $this->makeCompany('zajel', 'الزاجل');
        $this->owner = $this->makeUser($this->company);
    }

    private function host(): string
    {
        return 'http://'.$this->company->slug.'.'.config('zajel.tenant_domain');
    }

    public function test_the_owner_is_invited_to_upload_and_others_see_the_initial(): void
    {
        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee('ارفع اللوغو هنا')->assertSee('/settings/company#logo', false);

        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->get($this->host().'/')->assertOk()->assertDontSee('ارفع اللوغو هنا');
    }

    public function test_a_photo_becomes_the_logo_everywhere(): void
    {
        // صورة كاميرا مستطيلة: تُصغَّر داخل مربّعٍ ٥١٢ بلا قصّ
        $photo = UploadedFile::fake()->image('IMG_2041.jpg', 1600, 900);

        $this->actingAs($this->owner)->post($this->host().'/settings/company/logo', ['logo' => $photo])
            ->assertRedirect(route('settings.company'))->assertSessionHas('success');

        $path = $this->company->refresh()->logo_path;
        Storage::disk('local')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([512, 512], [$w, $h]);

        $this->actingAs($this->owner)->get($this->host().'/')->assertOk()
            ->assertSee($this->company->logoUrl(), false)->assertDontSee('ارفع اللوغو هنا');

        // يُرى قبل الدخول أيضاً (صفحة الدخول والتتبّع)
        auth()->logout();
        $this->get($this->host().'/logo')->assertOk()->assertHeader('Content-Type', 'image/png');

        // وإزالته تعيد الدعوة
        $this->actingAs($this->owner)->delete($this->host().'/settings/company/logo')->assertRedirect();
        $this->assertNull($this->company->refresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
        $this->get($this->host().'/logo')->assertNotFound();
    }

    public function test_only_images_are_taken_and_only_by_whoever_holds_the_settings(): void
    {
        $this->actingAs($this->owner)->post($this->host().'/settings/company/logo', [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('logo');

        $agent = $this->makeUser($this->company, UserRole::CustomerService);
        $this->actingAs($agent)->post($this->host().'/settings/company/logo', ['logo' => UploadedFile::fake()->image('x.png')])
            ->assertForbidden();
        $this->assertNull($this->company->refresh()->logo_path);
    }
}
