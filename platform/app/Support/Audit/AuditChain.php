<?php

namespace App\Support\Audit;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * سلسلة بصمات سجلّ التدقيق (docs/plan/68 §١٣). لكل شركةٍ سلسلتها، وللنواة سلسلةٌ رقمها صفر.
 *
 * البصمة على ما يُقرأ من السطر لا على نصّه كما خُزِّن: عمود JSON في MySQL يعيد ترتيب مفاتيحه،
 * فتُرتَّب المفاتيح قبل البصمة، ويُكتب الرقم والتاريخ بصيغةٍ واحدة أيّاً كان مصدرهما.
 */
class AuditChain
{
    /** @param  array<string, mixed>  $row */
    public static function hash(array $row, ?string $prev): string
    {
        $id = fn ($v) => $v === null || $v === '' ? '' : (string) (int) $v;
        $at = $row['created_at'] ?? null;

        return hash('sha256', implode('|', [
            $prev ?? '',
            $id($row['company_id'] ?? null),
            $id($row['user_id'] ?? null),
            (string) ($row['user_name'] ?? ''),
            $id($row['impersonator_user_id'] ?? null),
            (string) ($row['action'] ?? ''),
            (string) ($row['auditable_type'] ?? ''),
            $id($row['auditable_id'] ?? null),
            self::canonical($row['old_values'] ?? null),
            self::canonical($row['new_values'] ?? null),
            (string) ($row['reason'] ?? ''),
            $at instanceof DateTimeInterface ? $at->format('Y-m-d H:i:s') : substr((string) $at, 0, 19),
        ]));
    }

    /**
     * أوّل سطرٍ انكسرت عنده سلسلة الشركة (أو النواة بصفر)، أو null إن سلمت.
     * وتنكسر أيضاً إن حُذف آخرها: الرأس يحفظ آخر رقمٍ وبصمته.
     *
     * $full = false للشاشات: يُفحص ما بعد آخر سطرٍ سلم (نقطة التحقّق في الرأس) ما دامت بصمته
     * لم تتغيّر، وكسرٌ وجده فحصٌ سابق يبقى حتى يسلم فحصٌ كامل — كما في Journal::brokenAt.
     */
    public static function brokenAt(int $chain, bool $full = true): ?int
    {
        // الرأس قبل السطور: ما يُكتب أثناء الفحص بعد آخر سطرٍ يذكره، فلا يُحسب حذفاً
        $head = DB::table('audit_heads')->where('chain', $chain)->first();
        $rows = DB::table('audit_logs')
            ->when($chain === 0, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $chain));

        if (! $full && $head?->broken_id) {
            return (int) $head->broken_id;
        }

        $previous = null;
        $lastId = null;

        if (! $full && $head?->verified_id) {
            $anchor = (clone $rows)->where('id', $head->verified_id)->value('hash');

            // نقطة البدء نفسها تغيّرت: يُفحص من أوّله
            if ($anchor !== null && $anchor === $head->verified_hash) {
                $previous = $anchor;
                $lastId = (int) $head->verified_id;
            }
        }

        $broken = null;

        // بالمفتاح لا بالإزاحة: الإزاحة تعيد قراءة ما قبلها في كل صفحة
        (clone $rows)
            ->when($lastId, fn ($q) => $q->where('id', '>', $lastId))
            ->when($head, fn ($q) => $q->where('id', '<=', (int) $head->last_id))
            ->chunkById(2000, function ($items) use (&$previous, &$lastId, &$broken) {
                foreach ($items as $row) {
                    if ($row->prev_hash !== $previous || $row->hash !== self::hash((array) $row, $previous)) {
                        $broken = (int) $row->id;

                        return false;
                    }
                    $previous = $row->hash;
                    $lastId = (int) $row->id;
                }
            });

        // حُذف آخر السلسلة: الرأس يذكر سطراً لم يعد آخرها
        if ($broken === null && $head !== null && ($head->last_hash !== $previous || (int) $head->last_id !== (int) $lastId)) {
            $broken = (int) $head->last_id;
        }

        if ($head !== null) {
            DB::table('audit_heads')->where('chain', $chain)->update($broken !== null
                ? ['broken_id' => $broken]
                : array_filter([
                    'verified_id'   => $lastId,
                    'verified_hash' => $previous,
                    'verified_at'   => $full ? now() : null,
                ], fn ($v) => $v !== null) + ['broken_id' => null]);
        }

        return $broken;
    }

    private static function canonical(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        return (string) json_encode(self::sorted($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sorted(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return array_map(fn ($v) => self::sorted($v), $data);
    }
}
