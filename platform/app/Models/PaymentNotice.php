<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * دفعةٌ أبلغت عنها الشركة (docs/plan/36): حوّلت المبلغ وكتبت رقم حوالته، وتنتظر أن تؤكّدها
 * المنصّة فتصير دفعةً على الفاتورة — أو ترفضها بسببٍ تقرؤه الشركة.
 */
class PaymentNotice extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'pending'   => ['بانتظار التأكيد', 'chip-warn'],
        'confirmed' => ['أُكّدت', 'chip-ok'],
        'rejected'  => ['رُفضت', 'chip-bad'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'decided_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusChip(): string
    {
        return self::STATUSES[$this->status][1] ?? 'chip-mute';
    }

    public function methodLabel(): string
    {
        return Payment::METHODS[$this->method] ?? $this->method;
    }

    public function hasProof(): bool
    {
        return filled($this->proof_path);
    }

    /** الإيصال لمن يحقّ له: صورةٌ تُعرض، وPDF يُنزَّل */
    public function proofResponse(): StreamedResponse
    {
        abort_unless($this->hasProof() && Storage::disk('local')->exists($this->proof_path), 404);

        return Storage::disk('local')->response($this->proof_path, $this->proof_name, [
            'Content-Type'           => $this->proof_mime,
            'Cache-Control'          => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], str_starts_with((string) $this->proof_mime, 'image/') ? 'inline' : 'attachment');
    }
}
