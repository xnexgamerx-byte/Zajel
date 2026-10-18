<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** سجلّ الوجبة: ما كُتب يبقى كما كُتب — التصحيح سطرٌ جديد بسببه */
class CollectionBatchAudit extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created'          => 'أُنشئت الوجبة',
        'items_added'      => 'أُضيفت كشوف',
        'item_removed'     => 'أُخرج كشف',
        'received_changed' => 'تغيّر المستلم فعلاً',
        'cost_added'       => 'حُسب مصروف',
        'cost_removed'     => 'أُخرج مصروف',
        'remainder_added'  => 'حُصِّل عجزٌ سابق',
        'approved'         => 'اعتُمدت',
        'corrected'        => 'تصحيح بعد الاعتماد',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
