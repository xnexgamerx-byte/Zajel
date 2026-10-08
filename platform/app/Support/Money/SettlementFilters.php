<?php

namespace App\Support\Money;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * البحث والفلترة في محاسبة المندوبين والتجّار: القائمة كما هي، وفوقها بحثٌ بالاسم أو
 * الكود أو الهاتف، والحالة، والمدّة، ومن قام بها — المحاسب الذي بنى الكشف أو أقفله أو
 * دفعه. خمسون مندوباً في الشاشة لا يُبحث عن أحدهم بالعين.
 */
final class SettlementFilters
{
    public function __construct(
        public readonly string $q,
        public readonly ?string $status,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?int $by,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;
        $status = (string) $request->query('status');

        return new self(
            q: mb_substr(trim((string) $request->query('q')), 0, 60),
            status: in_array($status, ['draft', 'confirmed', 'paid'], true) ? $status : null,
            from: $date('from'),
            to: $date('to'),
            by: $request->integer('by') ?: null,
        );
    }

    public function active(): bool
    {
        return $this->q !== '' || $this->status || $this->from || $this->to || $this->by;
    }

    /**
     * يطابق الطرف (المندوب أو التاجر) بالبحث: الاسم في أيّ موضع، والكود والهاتف من أوّلهما.
     *
     * @param  list<string>  $nameColumns
     */
    public function matchParty(Builder $party, array $nameColumns): Builder
    {
        if ($this->q === '') {
            return $party;
        }

        $digits = \App\Support\Phone::latinDigits($this->q);

        return $party->where(function (Builder $w) use ($nameColumns, $digits) {
            foreach ($nameColumns as $column) {
                $w->orWhere($column, 'like', '%'.$this->q.'%');
            }

            $w->orWhere('code', 'like', $this->q.'%')
                ->orWhere('phone', 'like', '%'.$digits.'%');
        });
    }

    /**
     * يقصر الكشوف على البحث والحالة والمدّة ومن قام بها.
     *
     * @param  list<string>  $actorColumns  أعمدة «من قام بها»: بنى الكشف، أقفله، دفعه
     */
    public function apply(Builder $settlements, string $partyColumn, Builder $parties, array $actorColumns): Builder
    {
        return $settlements
            ->when($this->q !== '', fn (Builder $s) => $s->whereIn($partyColumn, $parties->select('id')))
            ->when($this->status, fn (Builder $s, $status) => $s->where('status', $status))
            ->when($this->from, fn (Builder $s, $from) => $s->whereFromDate('created_at', $from))
            ->when($this->to, fn (Builder $s, $to) => $s->whereUntilDate('created_at', $to))
            ->when($this->by, fn (Builder $s, $by) => $s->where(function (Builder $w) use ($actorColumns, $by) {
                foreach ($actorColumns as $column) {
                    $w->orWhere($column, $by);
                }
            }));
    }

    /**
     * من يُختار في «بواسطة»: موظّفو الشركة الذين بنوا كشفاً أو أقفلوه أو دفعوه.
     *
     * @param  list<string>  $actorColumns
     * @return Collection<int, User>
     */
    public static function actors(Builder $settlements, array $actorColumns): Collection
    {
        $ids = collect($actorColumns)
            ->flatMap(fn (string $column) => (clone $settlements)->whereNotNull($column)->distinct()->pluck($column))
            ->unique()->filter()->values();

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
}
