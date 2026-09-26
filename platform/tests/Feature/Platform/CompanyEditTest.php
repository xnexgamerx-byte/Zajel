<?php

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعديل بيانات الشركة من لوحة المنصّة: ما تغيّر يُسجَّل بقيمته قبل وبعد،
 * والنطاق الفرعي لا يتحرّك — عليه تتصل تطبيقاتها ويُطبع رابط تتبّعها.
 */
class CompanyEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();

        $this->admin = Tenancy::runAsPlatform(fn () => User::create([
            'name' => 'مدير المنصّة', 'phone' => '07700000000',
            'password' => 'password', 'role' => UserRole::PlatformAdmin, 'is_active' => true,
        ]));

        $this->company = $this->makeCompany('zajel', 'الزاجل');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'الزاجل', 'name_en' => '', 'phone' => '', 'email' => '',
            'governorate_id' => '', 'address' => '', 'primary_color' => '#0d9488',
        ], $overrides);
    }

    private function fresh(): Company
    {
        return Tenancy::runAsPlatform(fn () => $this->company->fresh());
    }

    private function audits(): \Illuminate\Support\Collection
    {
        return Tenancy::runAsPlatform(fn () => AuditLog::where('action', 'company_updated')->get());
    }

    public function test_the_list_and_the_company_page_lead_to_the_edit(): void
    {
        $edit = route('admin.companies.edit', $this->company);

        $this->actingAs($this->admin)->get('/admin/companies')->assertOk()->assertSee($edit, false);
        $this->actingAs($this->admin)->get("/admin/companies/{$this->company->id}")
            ->assertOk()
            ->assertSee($edit, false)
            ->assertSee('تعديل البيانات');
    }

    public function test_the_edit_page_shows_the_details_and_a_subdomain_that_stays(): void
    {
        Tenancy::runAsPlatform(fn () => $this->company->update([
            'phone' => '07801112233', 'address' => 'بغداد، الكرادة',
        ]));

        $this->actingAs($this->admin)->get("/admin/companies/{$this->company->id}/edit")
            ->assertOk()
            ->assertSee('value="الزاجل"', false)
            ->assertSee('07801112233')
            ->assertSee('بغداد، الكرادة')
            ->assertSee('zajel.'.config('zajel.tenant_domain'))
            ->assertDontSee('name="slug"', false);
    }

    public function test_editing_saves_the_details_in_one_form_and_logs_only_what_changed(): void
    {
        $this->actingAs($this->admin)
            ->put("/admin/companies/{$this->company->id}", $this->payload([
                'name'           => 'الزاجل للتوصيل',
                'phone'          => '+964 780 111 2233',
                'governorate_id' => $this->baghdad()->id,
                'address'        => 'بغداد، الكرادة',
                'primary_color'  => '#aa3300',
            ]))
            ->assertRedirect("/admin/companies/{$this->company->id}")
            ->assertSessionHas('success', 'حُفظت بيانات الزاجل للتوصيل.');

        $company = $this->fresh();
        $this->assertSame('الزاجل للتوصيل', $company->name);
        $this->assertSame('07801112233', $company->phone);
        $this->assertSame($this->baghdad()->id, $company->governorate_id);
        $this->assertSame('#AA3300', $company->primary_color);

        $audit = $this->audits()->sole();
        $old = $audit->old_values;
        ksort($old);
        $this->assertSame(['address' => null, 'governorate_id' => null, 'name' => 'الزاجل',
            'phone' => null, 'primary_color' => '#0D9488'], $old);
        $this->assertSame('الزاجل للتوصيل', $audit->new_values['name']);
        // ما لم يتغيّر لا يُذكر: البريد والاسم الإنجليزي فارغان قبل وبعد
        $this->assertArrayNotHasKey('email', $audit->new_values);
        $this->assertArrayNotHasKey('name_en', $audit->new_values);
        $this->assertSame($this->admin->name, $audit->user_name);
    }

    public function test_the_subdomain_does_not_move_even_when_sent(): void
    {
        $this->actingAs($this->admin)
            ->put("/admin/companies/{$this->company->id}", $this->payload(['name' => 'البرق', 'slug' => 'barq']))
            ->assertSessionHasNoErrors();

        $this->assertSame('zajel', $this->fresh()->slug);
        $this->assertSame('البرق', $this->fresh()->name);
    }

    public function test_the_same_details_in_other_letters_are_not_a_change(): void
    {
        // المحفوظ #0D9488 ومنتقي الألوان يرسله بحروفٍ صغيرة
        $this->actingAs($this->admin)
            ->put("/admin/companies/{$this->company->id}", $this->payload())
            ->assertSessionHas('success', 'لم يتغيّر شيء.');

        $this->assertCount(0, $this->audits());
        $this->assertSame('#0D9488', $this->fresh()->primary_color);
    }

    public function test_bad_details_are_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin)
            ->put("/admin/companies/{$this->company->id}", $this->payload([
                'name' => '', 'phone' => '12345', 'email' => 'not-an-email',
                'governorate_id' => 999999, 'primary_color' => 'red',
            ]))
            ->assertSessionHasErrors(['name', 'phone', 'email', 'governorate_id', 'primary_color']);

        $this->assertSame('الزاجل', $this->fresh()->name);
        $this->assertCount(0, $this->audits());
    }

    public function test_a_company_user_cannot_edit_any_company(): void
    {
        $owner = $this->makeUser($this->company);

        $this->actingAs($owner)->get("/admin/companies/{$this->company->id}/edit")->assertForbidden();
        $this->actingAs($owner)
            ->put("/admin/companies/{$this->company->id}", $this->payload(['name' => 'مخترَقة']))
            ->assertForbidden();

        $this->assertSame('الزاجل', $this->fresh()->name);
    }

    public function test_a_guest_is_sent_to_the_platform_login(): void
    {
        $this->get("/admin/companies/{$this->company->id}/edit")->assertRedirect('/admin/login');
    }
}
