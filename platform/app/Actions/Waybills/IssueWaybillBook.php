<?php

namespace App\Actions\Waybills;

use App\Models\Merchant;
use App\Models\User;
use App\Models\WaybillBook;
use App\Services\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دفتر وصولاتٍ جديد: أرقامٌ متتالية تُحجز من تسلسل الشركة، لتاجرٍ أو للمخزن حتى
 * يُسنَد. يُصدره التاجر من بوابته حين يريد أن يطبع، والموظّف من «دفاتر الوصولات».
 */
class IssueWaybillBook
{
    public function __construct(protected SequenceGenerator $sequences) {}

    public function handle(?Merchant $merchant, int $size, User $actor, string $source = 'office', ?string $note = null): WaybillBook
    {
        if ($size < 1 || $size > WaybillBook::MAX_SIZE) {
            throw ValidationException::withMessages([
                'size' => 'الدفتر من وصلٍ واحد إلى '.WaybillBook::MAX_SIZE.' وصل.',
            ]);
        }

        return DB::transaction(function () use ($merchant, $size, $actor, $source, $note) {
            $from = $this->sequences->reserve('waybill', $size);

            if ($from + $size - 1 > WaybillBook::MAX_SERIAL) {
                throw ValidationException::withMessages(['size' => 'نفدت أرقام الوصولات المطبوعة لهذه الشركة.']);
            }

            return WaybillBook::create([
                'branch_id'           => $merchant?->branch_id ?? $actor->branch_id,
                'merchant_id'         => $merchant?->id,
                'from_serial'         => $from,
                'to_serial'           => $from + $size - 1,
                'size'                => $size,
                'source'              => $source,
                'note'                => $note,
                'created_by_user_id'  => $actor->id,
                'assigned_at'         => $merchant ? now() : null,
                'assigned_by_user_id' => $merchant ? $actor->id : null,
            ]);
        });
    }

    /** دفترٌ في المخزن يُسنَد لتاجر — ولا يتغيّر تاجره بعد أن استُعمل منه وصل */
    public function assign(WaybillBook $book, Merchant $merchant, User $actor): WaybillBook
    {
        return DB::transaction(function () use ($book, $merchant, $actor) {
            $book = WaybillBook::query()->lockForUpdate()->findOrFail($book->id);

            if ($book->shipments()->withTrashed()->exists()) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'استُعمل من هذا الدفتر وصلٌ أو أكثر، فلا يتغيّر تاجره.',
                ]);
            }

            $book->forceFill([
                'merchant_id'         => $merchant->id,
                'branch_id'           => $merchant->branch_id ?? $book->branch_id,
                'assigned_at'         => now(),
                'assigned_by_user_id' => $actor->id,
            ])->save();

            return $book;
        });
    }
}
