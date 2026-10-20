<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\Courier;
use App\Models\ExpenseCategory;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بيانات قياسٍ مصطنعة (docs/plan/68، المرحلة السابعة): شركةٌ بأربعة فروع، ٥٠٠ شحنةٍ يومياً
 * أحد عشر شهراً (~١٦٥ ألفاً) بمصائرها كما في الواقع — ٨٠٪ مسلَّمة و١٥٪ راجعة و٥٪ ملغاة، والأسبوع
 * الأخير على الحالات المفتوحة — ومعها كشوف المناديب أسبوعياً ودفعات التجّار ووجبات التحصيل
 * والمصروفات، كلٌّ بحركاته في الدفترين الفرعيين كما يكتبها النظام. ثم يُبنى الدفتر من التاريخ
 * بالطريق نفسه الذي يُبنى به في الإنتاج (finance:journal-backfill)، فيُقاس البناء والشاشات معاً.
 *
 * لا يعمل في الإنتاج. وأسماؤه وأرقامه مولَّدة: لا بيانات عميلٍ حقيقي.
 */
class FinanceLoadTestCommand extends Command
{
    protected $signature = 'finance:load-test
        {--slug=perf : الشركة (تُنشأ إن لم تكن)}
        {--days=330 : أيام التاريخ}
        {--per-day=500 : شحناتٌ يومياً}
        {--seed=68 : بذرة العشوائية، فتتكرّر البيانات نفسها}';

    protected $description = 'يملأ شركة قياسٍ بتاريخٍ مصطنع واسع لقياس الدفتر والشاشات (لا يعمل في الإنتاج)';

    private const CHUNK = 2000;

    /** @var array<string, int> المعرّف التالي لكل جدول: تُكتب المعرّفات صراحةً فتبقى بترتيب الزمن */
    private array $next = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffer = [];

    private int $companyId;

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('لا يعمل في الإنتاج.');

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));

        if (Governorate::query()->count() === 0) {
            $this->call('db:seed', ['--class' => 'Database\\Seeders\\GovernorateSeeder', '--force' => true]);
            $this->call('db:seed', ['--class' => 'Database\\Seeders\\FailureReasonSeeder', '--force' => true]);
        }

        $slug = (string) $this->option('slug');
        if (Tenancy::runAsPlatform(fn () => Company::query()->where('slug', $slug)->exists())) {
            $this->error("الشركة {$slug} موجودة: احذفها أو اختر غيرها.");

            return self::FAILURE;
        }

        $company = Tenancy::runAsPlatform(fn () => Company::create(['slug' => $slug, 'name' => 'شركة القياس', 'status' => 'active']));
        $this->companyId = $company->id;
        // تاريخٌ يُبنى: لا قيدٌ حيّ حتى يكتمل البناء
        DB::table('journal_heads')->insert(['company_id' => $company->id, 'updated_at' => now()]);

        $world = Tenancy::runFor($company, fn () => $this->world());
        foreach (['shipments', 'transactions', 'cash_movements', 'courier_settlements', 'merchant_settlements', 'collection_batches', 'collection_batch_items', 'expenses'] as $table) {
            $this->next[$table] = (int) DB::table($table)->max('id') + 1;
        }

        $started = microtime(true);
        $this->history($world, (int) $this->option('days'), (int) $this->option('per-day'));
        $this->info(sprintf('التاريخ: %s شحنة، %s حركة حساب، %s حركة صندوق — %.0f ث',
            number_format($this->next['shipments'] - 1), number_format($this->next['transactions'] - 1),
            number_format($this->next['cash_movements'] - 1), microtime(true) - $started));

        $started = microtime(true);
        $this->call('finance:journal-backfill', ['--company' => $slug]);
        $this->info(sprintf('بناء الدفتر: %.0f ث، %s سطر قيد', microtime(true) - $started,
            number_format(DB::table('journal_lines')->where('company_id', $company->id)->count())));

        $this->line("الدخول: {$world['owner']->phone} / password");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> الفروع والتجّار والمناديب والصناديق */
    private function world(): array
    {
        $list = PriceList::create(['name' => 'العامة', 'is_default' => true, 'is_active' => true]);
        $governorates = Governorate::query()->whereIn('code', ['BGD', 'BSR', 'ERB', 'NJF'])->get()->keyBy('code');
        $world = ['branches' => [], 'merchants' => [], 'couriers' => [], 'boxes' => []];

        foreach (['BGD' => 'بغداد', 'BSR' => 'البصرة', 'ERB' => 'أربيل', 'NJF' => 'النجف'] as $code => $name) {
            $gov = $governorates[$code] ?? Governorate::query()->first();
            $branch = Branch::create(['code' => $code, 'name' => "فرع {$name}", 'is_main' => $code === 'BGD',
                'governorate_id' => $gov->id, 'price_list_id' => $list->id, 'commission_per_delivery' => 2000, 'is_active' => true]);
            $world['branches'][$branch->id] = ['governorate' => $gov->id, 'main' => $code === 'BGD'];
            $world['boxes'][$branch->id] = CashBox::create(['code' => "BOX-{$code}", 'name' => "قاصة {$name}", 'type' => 'main',
                'branch_id' => $branch->id, 'balance' => 0, 'is_active' => true])->id;

            $count = $code === 'BGD' ? 150 : 50;
            for ($i = 1; $i <= $count; $i++) {
                $merchant = Merchant::create(['code' => sprintf('%s-M%03d', $code, $i), 'business_name' => "متجر قياس {$code} {$i}",
                    'phone' => sprintf('0771%07d', crc32("{$code}m{$i}") % 10_000_000), 'branch_id' => $branch->id,
                    'price_list_id' => $list->id, 'status' => 'active']);
                $world['merchants'][$merchant->id] = $branch->id;
            }

            $count = $code === 'BGD' ? 35 : 15;
            for ($i = 1; $i <= $count; $i++) {
                $courier = Courier::create(['code' => sprintf('%s-C%02d', $code, $i), 'name' => "مندوب قياس {$code} {$i}",
                    'phone' => sprintf('0772%07d', crc32("{$code}c{$i}") % 10_000_000), 'type' => 'delivery', 'status' => 'active',
                    'branch_id' => $branch->id, 'commission_per_delivery' => 1500]);
                $world['couriers'][$branch->id][] = $courier->id;
            }
        }

        // باب مصروفٍ خاصٌّ بالشركة (لا عامّ): يُنشأ بنسخته لا بالقراءة العامّة (TenantIsolationTest)
        $category = new ExpenseCategory(['code' => 'ops', 'name_ar' => 'تشغيلية', 'group' => 'operating', 'is_active' => true]);
        $category->save();
        $world['category'] = $category->id;
        $world['owner'] = User::create(['name' => 'صاحب شركة القياس', 'phone' => '07700000068', 'password' => 'password',
            'role' => UserRole::CompanyOwner, 'is_active' => true, 'branch_id' => array_key_first($world['branches'])]);

        return $world;
    }

    /** يومٌ بيوم: شحنات اليوم، ثم ما يقع فيه من تسليمٍ ورجوع، ثم الكشوف والدفعات والوجبات */
    private function history(array $world, int $days, int $perDay): void
    {
        $start = now()->startOfDay()->subDays($days);
        $schedule = [];
        $unsettled = [];   // courier => list of [shipment, cod, commission, fees, at]
        $payable = [];     // merchant => list of [shipment, due]
        $balances = ['merchant' => [], 'courier' => [], 'commission' => [], 'box' => []];
        $merchantIds = array_keys($world['merchants']);
        $bar = $this->output->createProgressBar($days);

        for ($d = 0; $d <= $days; $d++) {
            $day = $start->copy()->addDays($d);
            $recent = $days - $d < 7;
            $events = [];

            foreach (range(1, $perDay) as $n) {
                $id = $this->next['shipments']++;
                $merchant = $merchantIds[mt_rand(0, count($merchantIds) - 1)];
                $branch = $world['merchants'][$merchant];
                $foreign = mt_rand(1, 100) <= 5;
                $courierBranch = $foreign ? array_rand($world['branches']) : $branch;
                $courier = $world['couriers'][$courierBranch][mt_rand(0, count($world['couriers'][$courierBranch]) - 1)];
                $created = $day->copy()->setTime(mt_rand(8, 17), mt_rand(0, 59), mt_rand(0, 59));
                $cod = mt_rand(60, 600) * 250;
                $fee = $world['branches'][$branch]['main'] ? 5000 : 6000;

                $roll = mt_rand(1, 100);
                $fate = $recent
                    ? match (true) { $roll <= 45 => 'delivered', $roll <= 70 => 'out_for_delivery', $roll <= 88 => 'picked_up', $roll <= 95 => 'returned', default => 'cancelled' }
                    : match (true) { $roll <= 80 => 'delivered', $roll <= 95 => 'returned', default => 'cancelled' };
                $closedAt = $created->copy()->addDays(mt_rand(1, 3))->setTime(mt_rand(10, 20), mt_rand(0, 59));
                if ($closedAt->isFuture()) {
                    $fate = in_array($fate, ['delivered', 'returned'], true) ? 'out_for_delivery' : $fate;
                }

                $delivered = $fate === 'delivered';
                $this->push('shipments', [
                    'id' => $id, 'company_id' => $this->companyId, 'branch_id' => $branch, 'merchant_id' => $merchant,
                    'number' => (string) (1_000_000 + $id), 'recipient_name' => 'زبون '.$id, 'recipient_phone' => sprintf('0780%07d', $id % 10_000_000),
                    'governorate_id' => $world['branches'][$branch]['governorate'], 'address' => 'عنوان مصطنع', 'landmark' => 'قرب الساحة',
                    'cod_amount' => $cod, 'collected_amount' => $delivered ? $cod : 0, 'delivery_fee' => $fee, 'return_fee' => $fate === 'returned' ? 2500 : 0,
                    'total_fees' => $fate === 'returned' ? 2500 : $fee, 'merchant_due' => $delivered ? $cod - $fee : ($fate === 'returned' ? -2500 : 0),
                    'courier_commission' => 1500, 'branch_commission' => $foreign ? 2000 : 0,
                    'status' => $fate, 'status_changed_at' => in_array($fate, ['delivered', 'returned'], true) ? $closedAt : $created,
                    'delivery_courier_id' => $fate === 'cancelled' || $fate === 'picked_up' ? null : $courier,
                    'delivery_branch_id' => $fate === 'cancelled' || $fate === 'picked_up' ? null : $courierBranch,
                    'picked_up_at' => $fate === 'cancelled' ? null : $created->copy()->addHours(3),
                    'delivered_at' => $delivered ? $closedAt : null, 'returned_at' => $fate === 'returned' ? $closedAt : null,
                    'cancelled_at' => $fate === 'cancelled' ? $created->copy()->addHours(2) : null,
                    'created_by_user_id' => $world['owner']->id, 'source' => 'web', 'created_at' => $created, 'updated_at' => $closedAt->isFuture() ? $created : $closedAt,
                ]);

                if (in_array($fate, ['delivered', 'returned'], true)) {
                    $schedule[$closedAt->toDateString()][] = [$fate, $id, $merchant, $courier, $cod, $fee, $closedAt, $courierBranch];
                }
            }
            $this->flush('shipments');

            // ما يقع اليوم: التسليم يكتب للتاجر مستحقّه، وعلى المندوب نقده، وله عمولته — والرجوع أجرته على التاجر
            foreach ($schedule[$day->toDateString()] ?? [] as [$fate, $id, $merchant, $courier, $cod, $fee, $at, $courierBranch]) {
                if ($fate === 'delivered') {
                    $events[] = [$at, 'merchant', $merchant, 'credit', 'shipment_due', $cod - $fee, $id, null, null];
                    $events[] = [$at, 'courier', $courier, 'debit', 'cod_collected', $cod, $id, null, null];
                    $events[] = [$at, 'courier', $courier, 'credit', 'commission', 1500, $id, null, null];
                    $unsettled[$courier][] = [$id, $cod, 1500, $fee, $at, $courierBranch];
                    $payable[$merchant][] = [$id, $cod - $fee];
                } else {
                    $events[] = [$at, 'merchant', $merchant, 'debit', 'return_fee', 2500, $id, null, null];
                    $payable[$merchant][] = [$id, -2500];
                }
            }
            unset($schedule[$day->toDateString()]);

            // الأحد والأربعاء: كشوف المناديب على ما سُلِّم قبل يومين، وفي يومها وجبة تحصيلٍ لكل فرع
            if (in_array($day->dayOfWeek, [Carbon::SUNDAY, Carbon::WEDNESDAY], true)) {
                $batchItems = [];
                $links = [];
                foreach ($unsettled as $courier => $lines) {
                    $due = array_values(array_filter($lines, fn ($l) => $l[4]->lt($day->copy()->subDays(1))));
                    if ($due === []) {
                        continue;
                    }
                    $unsettled[$courier] = array_values(array_filter($lines, fn ($l) => ! $l[4]->lt($day->copy()->subDays(1))));
                    $at = $day->copy()->setTime(21, mt_rand(0, 59));
                    $cod = array_sum(array_column($due, 1));
                    $commission = array_sum(array_column($due, 2));
                    $branch = $due[0][5];
                    $sid = $this->next['courier_settlements']++;

                    $this->push('courier_settlements', ['id' => $sid, 'company_id' => $this->companyId, 'branch_id' => $branch, 'courier_id' => $courier,
                        'code' => 'CS-'.$sid, 'shipments_count' => count($due), 'cod_total' => $cod, 'commission_total' => $commission,
                        'net_amount' => $cod - $commission, 'status' => 'confirmed', 'confirmed_at' => $at, 'confirmed_by_user_id' => $world['owner']->id,
                        'created_by_user_id' => $world['owner']->id, 'created_at' => $at, 'updated_at' => $at]);
                    $events[] = [$at, 'courier', $courier, 'credit', 'cash_handover', $cod, null, 'courier_settlement', $sid];
                    $events[] = [$at, 'courier', $courier, 'debit', 'commission_paid', $commission, null, 'courier_settlement', $sid];
                    $events[] = [$at, 'box', $world['boxes'][$branch], 'in', 'courier_handover', $cod, null, 'courier_settlement', $sid];
                    $events[] = [$at, 'box', $world['boxes'][$branch], 'out', 'commission_paid', $commission, null, 'courier_settlement', $sid];
                    $links[] = [array_column($due, 0), ['courier_settlement_id' => $sid, 'courier_settled_at' => $at]];
                    $batchItems[$branch][] = [$sid, $courier, count($due), $cod, $commission, array_sum(array_column($due, 3)), $at];
                }
                $this->flush('courier_settlements');
                $this->link($links);

                foreach ($batchItems as $branch => $items) {
                    $this->batch($branch, $items, $world, $day);
                }
            }

            // الثلاثاء: دفعات التجّار لما وصل الشركة
            if ($day->dayOfWeek === Carbon::TUESDAY) {
                $links = [];
                foreach ($payable as $merchant => $lines) {
                    $net = array_sum(array_column($lines, 1));
                    if ($net <= 0) {
                        continue;
                    }
                    $at = $day->copy()->setTime(14, mt_rand(0, 59));
                    $mid = $this->next['merchant_settlements']++;
                    $branch = $world['merchants'][$merchant];
                    $this->push('merchant_settlements', ['id' => $mid, 'company_id' => $this->companyId, 'branch_id' => $branch, 'merchant_id' => $merchant,
                        'code' => 'MS-'.$mid, 'shipments_count' => count($lines), 'net_amount' => $net, 'status' => 'paid',
                        'confirmed_at' => $at, 'paid_at' => $at, 'paid_by_user_id' => $world['owner']->id, 'created_by_user_id' => $world['owner']->id,
                        'created_at' => $at, 'updated_at' => $at]);
                    $events[] = [$at, 'merchant', $merchant, 'debit', 'payout', $net, null, 'merchant_settlement', $mid];
                    $events[] = [$at, 'box', $world['boxes'][$branch], 'out', 'merchant_payout', $net, null, 'merchant_settlement', $mid];
                    $links[] = [array_column($lines, 0), ['merchant_settlement_id' => $mid, 'merchant_settled_at' => $at]];
                    unset($payable[$merchant]);
                }
                $this->flush('merchant_settlements');
                $this->link($links);
            }

            // مصروفات: يومياً لكل فرعٍ واحدٌ أو اثنان، مدفوعةٌ من قاصته
            foreach ($world['boxes'] as $branch => $box) {
                foreach (range(1, mt_rand(1, 2)) as $k) {
                    $at = $day->copy()->setTime(12, mt_rand(0, 59));
                    $eid = $this->next['expenses']++;
                    $amount = mt_rand(10, 200) * 1000;
                    $this->push('expenses', ['id' => $eid, 'company_id' => $this->companyId, 'branch_id' => $branch, 'expense_category_id' => $world['category'],
                        'number' => 'EX-'.$eid, 'amount' => $amount, 'spent_on' => $day->toDateString(), 'description' => 'مصروف تشغيل',
                        'status' => 'paid', 'cash_box_id' => $box, 'paid_at' => $at, 'paid_by_user_id' => $world['owner']->id,
                        'created_by_user_id' => $world['owner']->id, 'created_at' => $at, 'updated_at' => $at]);
                    $events[] = [$at, 'box', $box, 'out', 'expense', $amount, null, 'expense', $eid];
                }
            }
            $this->flush('expenses');

            usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($events as [$at, $kind, $owner, $direction, $category, $amount, $shipment, $refType, $refId]) {
                if ($kind === 'box') {
                    $balance = ($balances['box'][$owner] ?? 0) + ($direction === 'in' ? $amount : -$amount);
                    $balances['box'][$owner] = $balance;
                    $this->push('cash_movements', ['id' => $this->next['cash_movements']++, 'company_id' => $this->companyId, 'cash_box_id' => $owner,
                        'branch_id' => array_search($owner, $world['boxes'], true) ?: null, 'direction' => $direction, 'category' => $category,
                        'amount' => $amount, 'balance_after' => $balance, 'reference_type' => $refType, 'reference_id' => $refId,
                        'description' => $category, 'created_by_user_id' => $world['owner']->id, 'created_at' => $at]);

                    continue;
                }

                $signed = $direction === 'credit' ? $amount : -$amount;
                $key = $kind === 'courier' && in_array($category, ['commission', 'commission_paid'], true) ? 'commission' : $kind;
                $balances[$key][$owner] = ($balances[$key][$owner] ?? 0) + $signed;
                $this->push('transactions', ['id' => $this->next['transactions']++, 'company_id' => $this->companyId, 'account_type' => $kind,
                    'account_id' => $owner, 'direction' => $direction, 'category' => $category, 'amount' => $amount,
                    'balance_after' => $balances[$key][$owner], 'shipment_id' => $shipment, 'reference_type' => $refType, 'reference_id' => $refId,
                    'description' => $category, 'created_by_user_id' => $world['owner']->id, 'created_at' => $at]);
            }
            $this->flush('transactions');
            $this->flush('cash_movements');
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // الأرصدة المخزَّنة كما تكتبها الشاشات: المندوب بيده ما عليه، والتاجر له ما له
        foreach ($balances['merchant'] as $id => $v) {
            DB::table('merchants')->where('id', $id)->update(['balance' => $v]);
        }
        foreach ($balances['courier'] as $id => $v) {
            DB::table('couriers')->where('id', $id)->update(['cash_in_hand' => -$v]);
        }
        foreach ($balances['commission'] as $id => $v) {
            DB::table('couriers')->where('id', $id)->update(['commission_balance' => $v]);
        }
        foreach ($balances['box'] as $id => $v) {
            DB::table('cash_boxes')->where('id', $id)->update(['balance' => $v]);
        }
    }

    /** وجبةٌ معتمدة لكشوف الفرع في يومها: النقد المعدود كالمتوقَّع */
    private function batch(int $branch, array $items, array $world, Carbon $day): void
    {
        $bid = $this->next['collection_batches']++;
        $at = $day->copy()->setTime(22, 30);
        $cod = array_sum(array_column($items, 3));
        $commission = array_sum(array_column($items, 4));
        $fees = array_sum(array_column($items, 5));

        foreach ($items as [$sid, $courier, $count, $itemCod, $itemCommission, $itemFees]) {
            $this->push('collection_batch_items', ['id' => $this->next['collection_batch_items']++, 'company_id' => $this->companyId,
                'collection_batch_id' => $bid, 'kind' => 'settlement', 'status' => 'received', 'courier_id' => $courier, 'courier_settlement_id' => $sid,
                'shipments_count' => $count, 'delivered_count' => $count, 'cod_collected' => $itemCod, 'merchant_money' => $itemCod - $itemFees,
                'delivery_fees' => $itemFees, 'courier_commissions' => $itemCommission, 'expected_cash' => $itemCod - $itemCommission,
                'received_cash' => $itemCod - $itemCommission, 'realized_fees' => $itemFees, 'counted_at' => $at, 'posted_at' => $at,
                'counted_by_user_id' => $world['owner']->id, 'created_at' => $at, 'updated_at' => $at]);
        }

        $this->push('collection_batches', ['id' => $bid, 'company_id' => $this->companyId, 'number' => 'CB-'.$bid, 'branch_id' => $branch,
            'cash_box_id' => $world['boxes'][$branch], 'period' => 'custom', 'from_date' => $day->copy()->subDays(4)->toDateString(), 'to_date' => $day->toDateString(),
            'status' => 'approved', 'couriers_count' => count($items), 'settlements_count' => count($items), 'shipments_count' => array_sum(array_column($items, 2)),
            'delivered_count' => array_sum(array_column($items, 2)), 'cod_collected' => $cod, 'merchant_money' => $cod - $fees, 'delivery_fees' => $fees,
            'fees_in_cash' => $fees, 'courier_commissions' => $commission, 'expected_cash' => $cod - $commission, 'received_cash' => $cod - $commission,
            'net_profit' => $fees - $commission, 'realized_profit' => $fees - $commission, 'realized_fees' => $fees,
            'approved_at' => $at, 'approved_by_user_id' => $world['owner']->id, 'created_by_user_id' => $world['owner']->id, 'created_at' => $at, 'updated_at' => $at]);
        $this->flush('collection_batches');
        $this->flush('collection_batch_items');
    }

    /** @param  list<array{list<int>, array<string, mixed>}>  $links  الشحنات إلى كشفها بعد كتابته */
    private function link(array $links): void
    {
        foreach ($links as [$ids, $values]) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('shipments')->whereIn('id', $chunk)->update($values);
            }
        }
    }

    private function push(string $table, array $row): void
    {
        $this->buffer[$table][] = $row;

        if (count($this->buffer[$table]) >= self::CHUNK) {
            $this->flush($table);
        }
    }

    private function flush(string $table): void
    {
        if (empty($this->buffer[$table])) {
            return;
        }

        // صفوفٌ بمفاتيح مختلفة لا تُدرج معاً: يُوحَّد شكلها
        $keys = array_keys(array_merge(...array_map(fn ($r) => array_fill_keys(array_keys($r), true), $this->buffer[$table])));
        $rows = array_map(fn ($r) => array_merge(array_fill_keys($keys, null), $r), $this->buffer[$table]);

        DB::table($table)->insert($rows);
        $this->buffer[$table] = [];
    }
}
