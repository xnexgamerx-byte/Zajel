<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مصروفٌ محسوبٌ على وجبة: في وجبةٍ واحدة لا غير */
class CollectionBatchCost extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class, 'collection_batch_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
