<?php

namespace App\Services\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * المدّة المشتركة بين التقارير.
 *
 * وكل تقرير يقول صراحةً ما الذي يُقاس بها: تقرير عن الشحنات يَعُدّ ما
 * أُنشئ في المدّة، وتقرير عن العمل يَعُدّ ما أُنجز فيها. الخلط بينهما
 * يُنتج رقمين مختلفين للسؤال نفسه ولا أحد يعرف أيّهما الصحيح.
 */
class ReportPeriod
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from->startOfDay(), $to->endOfDay());
    }

    /** @return array{string, string} */
    public function bounds(): array
    {
        return [$this->from->toDateTimeString(), $this->to->toDateTimeString()];
    }

    public function days(): int
    {
        return max(1, $this->from->diffInDays($this->to) + 1);
    }

    public function label(): string
    {
        return $this->from->format('Y-m-d').' — '.$this->to->format('Y-m-d');
    }

    public function query(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
