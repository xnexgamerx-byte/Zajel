<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentNotice;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * «دفعتُ»: الشركة تُبلغ عن دفعتها من «اشتراك الشركة وفواتيرها»، والمنصّة تؤكّدها فتصير دفعةً
 * على الفاتورة (RecordPayment)، أو ترفضها بسببٍ تقرؤه الشركة (docs/plan/36).
 */
class PaymentNotices
{
    /** صورة الإيصال أو PDF، كمرفقات المحادثات */
    public const PROOF_RULE = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];

    private const PROOF_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** @param array{invoice_id: int, amount: int, method: string, reference?: ?string, paid_on: string, note?: ?string} $data */
    public function report(Company $company, array $data, User $by, ?UploadedFile $proof = null): PaymentNotice
    {
        return Tenancy::runFor($company, function () use ($company, $data, $by, $proof) {
            // فاتورتها هي، صادرةٌ لم تُسدَّد كاملةً، والمبلغ لا يزيد على باقيها
            $invoice = Invoice::whereKey($data['invoice_id'])->whereIn('status', ['issued', 'overdue'])->first();

            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'اختر فاتورةً عليها باقٍ.']);
            }

            $pending = (int) PaymentNotice::where('invoice_id', $invoice->id)->where('status', 'pending')->sum('amount');

            if ((int) $data['amount'] > $invoice->balanceDue() - $pending) {
                throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من الباقي على الفاتورة ('
                    .number_format(max(0, $invoice->balanceDue() - $pending)).' د.ع'.($pending ? ' بعد ما أبلغتم عنه ولم يُؤكَّد بعد' : '').').']);
            }

            // النوع من محتوى الملف لا من اسمه ولا مما قاله المتصفّح
            $mime = $proof?->getMimeType();

            if ($proof && ! in_array($mime, self::PROOF_MIMES, true)) {
                throw ValidationException::withMessages(['proof' => 'الإيصال صورةٌ (JPG أو PNG أو WEBP) أو PDF.']);
            }

            $path = $proof?->store('attachments/'.$company->id.'/payments', 'local');

            try {
                $notice = PaymentNotice::create([
                    'company_id' => $company->id,
                    'invoice_id' => $invoice->id,
                    'amount'     => (int) $data['amount'],
                    'method'     => $data['method'],
                    'reference'  => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'paid_on'    => $data['paid_on'],
                    'note'       => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'proof_path' => $path ?: null,
                    'proof_name' => $proof ? mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $proof->getClientOriginalName()) ?: 'إيصال', 0, 120) : null,
                    'proof_mime' => $proof ? $mime : null,
                    'proof_size' => $proof?->getSize(),
                    'user_id'    => $by->id,
                    'user_name'  => $by->name,
                ]);
            } catch (\Throwable $e) {
                // لا ملفّ يتيم لإبلاغٍ لم يُكتب
                if ($path) {
                    Storage::disk('local')->delete($path);
                }

                throw $e;
            }

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => $by->id,
                'user_name'      => $by->name,
                'action'         => 'payment_notice_reported',
                'auditable_type' => PaymentNotice::class,
                'auditable_id'   => $notice->id,
                'new_values'     => ['amount' => $notice->amount, 'method' => $notice->method, 'invoice' => $invoice->number],
            ]);

            return $notice;
        });
    }

    /** أكّدتها المنصّة: دفعةٌ على الفاتورة بمبلغها وطريقتها ورقمها، والإبلاغ يشير إليها */
    public function confirm(PaymentNotice $notice, User $by): Payment
    {
        return DB::transaction(function () use ($notice, $by) {
            // ضغطتان معاً لا تُسجّلان الدفعة مرّتين
            $notice = $this->pending($notice);
            $invoice = Invoice::acrossCompanies()->whereKey($notice->invoice_id)->lockForUpdate()->first();

            if (! $invoice) {
                throw ValidationException::withMessages(['notice' => 'فاتورة الإبلاغ لم تعد موجودة: ارفضه واكتب السبب.']);
            }

            $payment = app(RecordPayment::class)->handle($invoice, [
                'amount'    => $notice->amount,
                'method'    => $notice->method,
                'reference' => $notice->reference,
                'paid_at'   => $notice->paid_on->setTime(12, 0),
                'notes'     => 'من إبلاغ الشركة'.($notice->note ? ': '.$notice->note : ''),
            ], $by);

            $notice->forceFill([
                'status'     => 'confirmed',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'payment_id' => $payment->id,
            ])->save();

            return $payment;
        });
    }

    public function reject(PaymentNotice $notice, string $reason, User $by): void
    {
        DB::transaction(fn () => $this->decline($this->pending($notice), $reason, $by));
    }

    private function decline(PaymentNotice $notice, string $reason, User $by): void
    {
        $notice->forceFill([
            'status'        => 'rejected',
            'decided_by'    => $by->id,
            'decided_at'    => now(),
            'reject_reason' => $reason,
        ])->save();

        AuditLog::create([
            'company_id'     => $notice->company_id,
            'user_id'        => $by->id,
            'user_name'      => $by->name,
            'action'         => 'payment_notice_rejected',
            'auditable_type' => PaymentNotice::class,
            'auditable_id'   => $notice->id,
            'new_values'     => ['amount' => $notice->amount, 'reason' => $reason],
        ]);
    }

    /** الإبلاغ مقفلاً للقرار، وما زال ينتظر */
    private function pending(PaymentNotice $notice): PaymentNotice
    {
        $locked = PaymentNotice::acrossCompanies()->whereKey($notice->id)->lockForUpdate()->first();

        if (! $locked || $locked->status !== 'pending') {
            throw ValidationException::withMessages(['notice' => 'قُرّر في هذا الإبلاغ من قبل.']);
        }

        return $locked;
    }
}
