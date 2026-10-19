<?php

namespace App\Services\Finance;

use App\Support\Finance\Accounts;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * دفتر القيود: المكان الوحيد الذي يُكتب فيه قيد (docs/plan/68 §٣–٤).
 *
 * ثلاث ضمانات:
 *  1) القيد متوازن — مجموع المدين = مجموع الدائن — وإلّا لا يُكتب.
 *  2) مفتاحٌ من مصدره (idempotency_key): كتابة الحدث مرّتين قيدٌ واحد.
 *  3) سلسلة بصمات: كل قيدٍ يحمل بصمة سابقه، فحذف قيدٍ أو تعديله مباشرةً في القاعدة
 *     يكسر السلسلة ويظهر في الفحص.
 *
 * والقفل على رأس دفتر الشركة يرتّب القيود ويمنع تزاحم اثنين على الرقم نفسه.
 */
class Journal
{
    /**
     * @param  list<array{account: string, debit?: int, credit?: int, branch_id?: ?int, courier_id?: ?int,
     *                     merchant_id?: ?int, shipment_id?: ?int, cash_box_id?: ?int, expense_category_id?: ?int}>  $lines
     * @param  bool  $force  للبناء من التاريخ: يكتب ولو لم تُهيَّأ الشركة بعد
     * @return int|null رقم القيد (id)، أو null إن لم يُكتب (الشركة لم تُهيَّأ، أو لا مبلغ)
     */
    public function post(
        int $companyId,
        string $key,
        string $event,
        CarbonInterface $date,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $description = null,
        ?int $actorId = null,
        bool $force = false,
    ): ?int {
        $lines = array_values(array_filter(array_map(fn (array $line) => $line + ['debit' => 0, 'credit' => 0], $lines),
            fn (array $line) => (int) $line['debit'] !== 0 || (int) $line['credit'] !== 0));

        if ($lines === []) {
            return null;
        }

        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            if ($line['debit'] < 0 || $line['credit'] < 0 || ($line['debit'] > 0 && $line['credit'] > 0)) {
                throw new LogicException("سطر قيد {$key} غير صالح: المدين أو الدائن موجبٌ وحده.");
            }

            if (! isset(Accounts::ALL[$line['account']])) {
                throw new LogicException("حسابٌ مجهول {$line['account']} في القيد {$key}.");
            }

            $debit += (int) $line['debit'];
            $credit += (int) $line['credit'];
        }

        if ($debit !== $credit) {
            throw new LogicException("القيد {$key} غير متوازن: مدين {$debit} ودائن {$credit}.");
        }

        return DB::transaction(function () use ($companyId, $key, $event, $date, $lines, $sourceType, $sourceId, $description, $actorId, $force, $debit) {
            $head = $this->lockHead($companyId);

            if (! $force && $head->initialized_at === null) {
                return null; // تاريخ الشركة لم يُبنَ بعد: يكتبه البناء كاملاً بترتيبه
            }

            $existing = DB::table('journal_entries')->where('company_id', $companyId)->where('idempotency_key', $key)->value('id');

            if ($existing) {
                return (int) $existing;
            }

            $number = (int) $head->last_number + 1;
            $day = $date->copy()->timezone(config('app.timezone'))->toDateString();
            $hash = hash('sha256', implode('|', [
                $head->last_hash, $number, $key, $day,
                json_encode(array_map(fn ($l) => [$l['account'], (int) $l['debit'], (int) $l['credit']], $lines)),
            ]));

            $entryId = DB::table('journal_entries')->insertGetId([
                'company_id'         => $companyId,
                'number'             => $number,
                'entry_date'         => $day,
                'event'              => mb_substr($event, 0, 40),
                'source_type'        => $sourceType,
                'source_id'          => $sourceId,
                'idempotency_key'    => $key,
                'description'        => $description === null ? null : mb_substr($description, 0, 255),
                'amount'             => $debit,
                'created_by_user_id' => $actorId,
                'prev_hash'          => $head->last_hash,
                'hash'               => $hash,
                'created_at'         => now(),
            ]);

            DB::table('journal_lines')->insert(array_map(fn (array $line) => [
                'company_id'          => $companyId,
                'journal_entry_id'    => $entryId,
                'account'             => $line['account'],
                'debit'               => (int) $line['debit'],
                'credit'              => (int) $line['credit'],
                'entry_date'          => $day,
                'branch_id'           => $line['branch_id'] ?? null,
                'courier_id'          => $line['courier_id'] ?? null,
                'merchant_id'         => $line['merchant_id'] ?? null,
                'shipment_id'         => $line['shipment_id'] ?? null,
                'cash_box_id'         => $line['cash_box_id'] ?? null,
                'expense_category_id' => $line['expense_category_id'] ?? null,
            ], $lines));

            DB::table('journal_heads')->where('company_id', $companyId)
                ->update(['last_number' => $number, 'last_hash' => $hash, 'updated_at' => now()]);

            return (int) $entryId;
        });
    }

    public function isInitialized(int $companyId): bool
    {
        $head = DB::table('journal_heads')->where('company_id', $companyId)->first();

        // شركةٌ لا رأس لدفترها أُنشئت بعده: لا تاريخ قبلها، فهي مهيّأة من أوّل حركة
        return $head === null || $head->initialized_at !== null;
    }

    public function markInitialized(int $companyId): void
    {
        $this->lockHead($companyId);
        DB::table('journal_heads')->where('company_id', $companyId)->whereNull('initialized_at')
            ->update(['initialized_at' => now(), 'updated_at' => now()]);
    }

    /**
     * سلامة السلسلة: كل قيدٍ يحمل بصمة سابقه ويعيد حسابها. يُرجع أوّل رقمٍ انكسرت عنده، أو null.
     */
    public function brokenAt(int $companyId): ?int
    {
        $previous = null;
        $broken = null;

        DB::table('journal_entries')->where('company_id', $companyId)->orderBy('number')
            ->select(['id', 'number', 'idempotency_key', 'entry_date', 'prev_hash', 'hash'])
            ->chunk(2000, function ($entries) use (&$previous, &$broken) {
                $ids = $entries->pluck('id')->all();
                $lines = DB::table('journal_lines')->whereIn('journal_entry_id', $ids)->orderBy('id')
                    ->get(['journal_entry_id', 'account', 'debit', 'credit'])->groupBy('journal_entry_id');

                foreach ($entries as $entry) {
                    $expected = hash('sha256', implode('|', [
                        $previous, $entry->number, $entry->idempotency_key, substr((string) $entry->entry_date, 0, 10),
                        json_encode(($lines[$entry->id] ?? collect())->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->values()->all()),
                    ]));

                    if ($entry->prev_hash !== $previous || $entry->hash !== $expected) {
                        $broken = (int) $entry->number;

                        return false;
                    }

                    $previous = $entry->hash;
                }
            });

        return $broken;
    }

    protected function lockHead(int $companyId): object
    {
        $head = DB::table('journal_heads')->where('company_id', $companyId)->lockForUpdate()->first();

        if ($head === null) {
            DB::table('journal_heads')->insertOrIgnore([
                'company_id' => $companyId, 'initialized_at' => now(), 'updated_at' => now(),
            ]);
            $head = DB::table('journal_heads')->where('company_id', $companyId)->lockForUpdate()->first();
        }

        return $head;
    }
}
