<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToCompany;

    /** طرق الدفع بأسمائها — في الفاتورة، وفي إبلاغ الشركة عن دفعتها */
    public const METHODS = [
        'cash'          => 'نقد',
        'zaincash'      => 'زين كاش',
        'asiahawala'    => 'آسيا حوالة',
        'fastpay'       => 'فاست باي',
        'qi'            => 'Qi كارد',
        'fib'           => 'FIB',
        'bank_transfer' => 'حوالة مصرفية',
        'other'         => 'أخرى',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
