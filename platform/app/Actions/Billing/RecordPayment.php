<?php

namespace App\Actions\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل دفعة شركة على فاتورتها.
 *
 * الدفعات الجزئية مسموحة — شركة تدفع على دفعتين أمر عادي —
 * لكن مجموع المدفوع لا يتجاوز الفاتورة أبداً، فرقم زائد هنا يعني
 * رصيداً وهمياً يظهر في كل تقرير بعده.
 */
class RecordPayment
{
    public function handle(Invoice $invoice, array $data, ?User $actor = null): Payment
    {
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['amount' => 'الفاتورة ملغاة.']);
        }

        $amount = (int) $data['amount'];
        $due = $invoice->balanceDue();

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }

        if ($amount > $due) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ أكبر من المتبقّي ('.number_format($due).' د.ع).',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $actor) {
            $company = $invoice->company;

            $payment = Tenancy::runFor($company, fn () => Payment::create([
                'invoice_id'          => $invoice->id,
                'amount'              => $amount,
                'method'              => $data['method'] ?? 'cash',
                'reference'           => $data['reference'] ?? null,
                'paid_at'             => $data['paid_at'] ?? now(),
                'recorded_by_user_id' => $actor?->id,
                'notes'               => $data['notes'] ?? null,
            ]));

            $paid = $invoice->amount_paid + $amount;

            $invoice->forceFill([
                'amount_paid' => $paid,
                'status'      => $paid >= $invoice->total ? 'paid' : $invoice->status,
                'paid_at'     => $paid >= $invoice->total ? now() : null,
            ])->save();

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => $actor?->id,
                'user_name'      => $actor?->name,
                'action'         => 'payment_recorded',
                'auditable_type' => Invoice::class,
                'auditable_id'   => $invoice->id,
                'new_values'     => ['amount' => $amount, 'method' => $payment->method],
                'ip'             => request()->ip(),
            ]);

            return $payment;
        });
    }
}
