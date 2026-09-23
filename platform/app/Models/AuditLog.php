<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * سجلّ التدقيق — لكل شركة سجلّها، وللنواة كلّها.
 *
 * كان بلا نطاق الشركة: قراءاته اليوم كلّها من النواة (حيث لا فلترة
 * أصلاً)، لكن أوّل قراءةٍ من داخل شركة كانت ستقرأ سجلّات الشركات كلّها
 * — مَن دخل بياناتها ومتى، وفواتيرها. والنطاق يُسقَط في وضع النواة،
 * فلوحة المنصّة ترى الكلّ كما كانت. وهو إضافةٌ فقط كالدفتر.
 */
class AuditLog extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }
}
