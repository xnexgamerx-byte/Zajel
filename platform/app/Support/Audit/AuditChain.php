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
     */
    public static function brokenAt(int $chain): ?int
    {
        $previous = null;
        $lastId = null;
        $broken = null;

        // بالمفتاح لا بالإزاحة: الإزاحة تعيد قراءة ما قبلها في كل صفحة
        DB::table('audit_logs')
            ->when($chain === 0, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $chain))
            ->chunkById(2000, function ($rows) use (&$previous, &$lastId, &$broken) {
                foreach ($rows as $row) {
                    if ($row->prev_hash !== $previous || $row->hash !== self::hash((array) $row, $previous)) {
                        $broken = (int) $row->id;

                        return false;
                    }
                    $previous = $row->hash;
                    $lastId = (int) $row->id;
                }
            });

        if ($broken !== null) {
            return $broken;
        }

        $head = DB::table('audit_heads')->where('chain', $chain)->first();

        // حُذف آخر السلسلة: الرأس يذكر سطراً لم يعد آخرها
        if ($head !== null && ($head->last_hash !== $previous || (int) $head->last_id !== (int) $lastId)) {
            return (int) $head->last_id;
        }

        return null;
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
