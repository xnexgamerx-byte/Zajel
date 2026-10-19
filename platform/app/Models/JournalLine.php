<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Finance\Accounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطرٌ في قيد: حسابٌ، ومدينٌ أو دائن، وأبعاده */
class JournalLine extends Model
{
    use AppendOnly, BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class)->withTrashed();
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class)->withTrashed();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function accountName(): string
    {
        return Accounts::name($this->account);
    }
}
