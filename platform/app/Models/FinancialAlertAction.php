<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** سجلّ ما جرى على تنبيهٍ مالي: يُضاف إليه ولا يُعدَّل */
class FinancialAlertAction extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function label(): string
    {
        return FinancialAlert::ACTIONS[$this->action] ?? $this->action;
    }
}
