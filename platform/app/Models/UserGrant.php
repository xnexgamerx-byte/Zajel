<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صلاحيةٌ استثنائية: لموظّفٍ بعينه فوق مرتبته، بمن منحها ومتى.
 * كـ«صلاحية تعديل وحذف الشحنات» في المعتاد — تُمنح لشخصٍ لا لمرتبةٍ كاملة.
 */
class UserGrant extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
