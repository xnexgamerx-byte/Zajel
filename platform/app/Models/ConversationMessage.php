<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationMessage extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'attachment_size' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /** الملفّ ما زال في التخزين — لا رابطَ مكسوراً لملفٍّ ضاع مع قرصٍ استُبدل */
    public function attachmentAvailable(): bool
    {
        return $this->hasAttachment() && Storage::disk('local')->exists($this->attachment_path);
    }

    /** صورةٌ تُعرض مصغّرةً في المحادثة؛ وغيرها رابطٌ باسمه */
    public function attachmentIsImage(): bool
    {
        return str_starts_with((string) $this->attachment_mime, 'image/');
    }

    /**
     * الملف نفسه لمن أجازه المتحكّم: الصورة تُعرض، والـPDF يُنزَّل فلا يُفتح
     * في صفحة النظام، والنوع المخزَّن هو ما يُعلَن لا ما يُخمّنه المتصفّح.
     */
    public function attachmentResponse(): StreamedResponse
    {
        abort_unless($this->hasAttachment() && Storage::disk('local')->exists($this->attachment_path), 404);

        return Storage::disk('local')->response($this->attachment_path, $this->attachment_name, [
            'Content-Type'           => $this->attachment_mime,
            'Cache-Control'          => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], $this->attachmentIsImage() ? 'inline' : 'attachment');
    }

    public function attachmentSizeLabel(): string
    {
        $bytes = (int) $this->attachment_size;

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
