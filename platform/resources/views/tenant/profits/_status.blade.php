<span @class(['chip', 'chip-ok' => $batch->isApproved(), 'chip-mute' => $batch->isCancelled(), 'chip-bad' => $batch->status === 'discrepancy',
    'chip-warn' => in_array($batch->status, ['draft', 'awaiting_cash', 'awaiting_reconciliation'], true)])>{{ $batch->statusLabel() }}</span>
@unless ($batch->isCancelled())
    <span @class(['chip', 'chip-ok' => (int) $batch->discrepancy === 0, 'chip-bad' => $batch->discrepancy < 0, 'chip-info' => $batch->discrepancy > 0])>{{ $batch->reconciliationLabel() }}</span>
@endunless
