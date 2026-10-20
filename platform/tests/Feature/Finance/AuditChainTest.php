<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Audit\AuditChain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سلسلة بصمات سجلّ التدقيق (docs/plan/68 §١٣): لكل شركةٍ سلسلتها، وأيّ تعديلٍ أو حذفٍ مباشرٍ
 * في القاعدة يكسرها — ولو كان آخر سطر. وإعادة ترتيب مفاتيح JSON (كما يفعل MySQL) لا تكسرها.
 */
class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReference();
        $this->a = $this->makeCompany('alpha', 'ألفا');
        $this->b = $this->makeCompany('beta', 'بيتا');
    }

    private function log(Company $company, string $action, array $values = [], ?string $reason = null): AuditLog
    {
        return Tenancy::runFor($company, fn () => AuditLog::create([
            'user_name' => 'محاسب', 'action' => $action, 'new_values' => $values, 'reason' => $reason,
        ]));
    }

    public function test_each_company_has_its_own_intact_chain(): void
    {
        $first = $this->log($this->a, 'period_closed', ['number' => 'FP-0001']);
        $this->log($this->b, 'period_closed', ['number' => 'FP-0001']);
        $second = $this->log($this->a, 'profit_withdrawal_paid', ['amount' => 50_000, 'number' => 'W-1'], 'سحب الشركاء');

        $this->assertNull($first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertNull(AuditChain::brokenAt($this->a->id));
        $this->assertNull(AuditChain::brokenAt($this->b->id));
        $this->assertSame($second->id, (int) DB::table('audit_heads')->where('chain', $this->a->id)->value('last_id'));
    }

    public function test_reordered_json_keys_keep_the_chain(): void
    {
        $log = $this->log($this->a, 'period_closed', ['number' => 'FP-0001', 'amount' => 10, 'nested' => ['z' => 1, 'a' => 2]]);
        DB::table('audit_logs')->where('id', $log->id)->update(['new_values' => '{"nested": {"a": 2, "z": 1}, "amount": 10, "number": "FP-0001"}']);

        $this->assertNull(AuditChain::brokenAt($this->a->id));
    }

    public function test_an_edited_or_deleted_row_breaks_the_chain_and_the_checks_show_it(): void
    {
        $first = $this->log($this->a, 'period_closed', ['number' => 'FP-0001']);
        $middle = $this->log($this->a, 'profit_withdrawal_paid', ['amount' => 50_000]);
        $last = $this->log($this->a, 'profit_withdrawal_paid', ['amount' => 20_000]);

        DB::table('audit_logs')->where('id', $middle->id)->update(['new_values' => json_encode(['amount' => 5_000])]);
        $this->assertSame($middle->id, AuditChain::brokenAt($this->a->id));
        $this->assertNull(AuditChain::brokenAt($this->b->id));

        $check = Tenancy::runFor($this->a, fn () => collect(app(\App\Services\Finance\JournalCheck::class)->run())->keyBy('key'));
        $this->assertSame('fail', $check['audit_chain']['status'] ?? null);

        // إرجاعه كما كان يُصلحها، وحذف آخر سطرٍ يكسرها من الرأس
        DB::table('audit_logs')->where('id', $middle->id)->update(['new_values' => json_encode(['amount' => 50_000])]);
        $this->assertNull(AuditChain::brokenAt($this->a->id));

        DB::table('audit_logs')->where('id', $last->id)->delete();
        $this->assertSame($last->id, AuditChain::brokenAt($this->a->id));

        DB::table('audit_logs')->where('id', $first->id)->delete();
        $this->assertNotNull(AuditChain::brokenAt($this->a->id));
    }

    /** الشاشات تفحص ما بعد آخر سطرٍ سلم، والعبث بما قبله يكشفه الفحص الكامل ثم يبقى ظاهراً لها */
    public function test_screens_check_only_rows_after_the_last_sound_check(): void
    {
        $old = $this->log($this->a, 'period_closed', ['number' => 'FP-0001']);
        $this->log($this->a, 'profit_withdrawal_paid', ['amount' => 50_000]);
        $this->assertNull(AuditChain::brokenAt($this->a->id));
        $head = DB::table('audit_heads')->where('chain', $this->a->id)->first();
        $this->assertNotNull($head->verified_at);

        // سطرٌ جديد بعد نقطة التحقّق: يُفحص وحده وتتقدّم النقطة إليه، ووقت الفحص الكامل لا يتغيّر
        $new = $this->log($this->a, 'profit_withdrawal_paid', ['amount' => 20_000]);
        $this->assertNull(AuditChain::brokenAt($this->a->id, full: false));
        $this->assertSame($new->id, (int) DB::table('audit_heads')->where('chain', $this->a->id)->value('verified_id'));
        $this->assertSame($head->verified_at, DB::table('audit_heads')->where('chain', $this->a->id)->value('verified_at'));

        // تعديل سطرٍ قبل النقطة لا تراه الشاشة، والفحص الليلي يراه، ثم تبقى الشاشة تقوله
        DB::table('audit_logs')->where('id', $old->id)->update(['reason' => 'عبث']);
        $this->assertNull(AuditChain::brokenAt($this->a->id, full: false));
        $this->assertSame($old->id, AuditChain::brokenAt($this->a->id));
        $this->assertSame($old->id, AuditChain::brokenAt($this->a->id, full: false));

        // يُصلح ويسلم فحصٌ كامل: تعود الشاشة سليمة
        DB::table('audit_logs')->where('id', $old->id)->update(['reason' => null]);
        $this->assertSame($old->id, AuditChain::brokenAt($this->a->id, full: false));
        $this->assertNull(AuditChain::brokenAt($this->a->id));
        $this->assertNull(AuditChain::brokenAt($this->a->id, full: false));

        // تعديل سطر النقطة نفسه: يُفحص من أوّله فيُرى
        DB::table('audit_logs')->where('id', $new->id)->update(['hash' => str_repeat('0', 64)]);
        $this->assertSame($new->id, AuditChain::brokenAt($this->a->id, full: false));
    }
}
