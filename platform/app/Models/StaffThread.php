<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** محادثةٌ بين موظّفَين، أو محادثة قسمٍ كلّه (docs/plan/41). */
class StaffThread extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StaffMessage::class)->orderBy('id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_thread_members')->withPivot('last_read_id');
    }

    public function isTeam(): bool
    {
        return $this->team !== null;
    }
}
