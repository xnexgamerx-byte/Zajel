<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;

/**
 * رسالة عملٍ على دفعةٍ من القائمة (تحديث الحالة، الإسناد، اعتماد التسليم): كم
 * تمّ، وما استُلم أوّلاً، وما تُخطّي ولماذا — شحنةً شحنة. ولا شيء تمّ: رسالةٌ
 * حمراء لا خضراء.
 */
trait ReportsBulkOutcome
{
    /**
     * @param  array{moved: list<string>, received?: list<string>, skipped: array<string, string>}  $result
     * @param  string  $done  جملة النجاح، و:count مكان العدد
     */
    protected function outcome(array $result, string $done, string $none): RedirectResponse
    {
        ['moved' => $moved, 'skipped' => $skipped] = $result;
        $received = $result['received'] ?? [];

        $list = fn (array $numbers) => implode('، ', array_slice($numbers, 0, 5)).(count($numbers) > 5 ? '…' : '');

        $message = $moved
            ? str_replace(':count', Arabic::shipments(count($moved)), $done)
                .($received ? ' (استُلمت من التاجر أوّلاً: '.$list($received).')' : '').'.'
            : $none;

        if ($skipped) {
            $message .= ' تُخطّيت '.Arabic::shipments(count($skipped)).': '
                .$list(array_map(fn ($number, $reason) => "{$number} ({$reason})", array_keys($skipped), $skipped)).'.';
        }

        return $moved
            ? back()->with('success', $message)
            : back()->withErrors(['shipment_ids' => $message]);
    }
}
