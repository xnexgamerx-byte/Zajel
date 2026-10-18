<span @class(['chip', 'chip-ok' => $batch->isApproved(), 'chip-warn' => ! $batch->isApproved()])>{{ $batch->statusLabel() }}</span>
<span @class(['chip', 'chip-ok' => (int) $batch->discrepancy === 0, 'chip-bad' => $batch->discrepancy < 0, 'chip-info' => $batch->discrepancy > 0])>{{ $batch->reconciliationLabel() }}</span>
