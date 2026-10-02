<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\SeenByBranch;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفتر وصولاتٍ مطبوعة مسبقاً: مدىً من أرقام الشركة، لتاجرٍ بعينه أو في المخزن حتى
 * يُسنَد. يطبعه التاجر من بوابته (أو الشركة له) ويكتب على كل وصلٍ بيده، ثم تُدخَل
 * الشحنة بمسحه (CreateFromWaybill).
 *
 * الرقم المطبوع ثمانية أرقام تبدأ بـ٩ (٩٠٬٠٠٠٬٠٠٠ + التسلسل): أرقامٌ كوصلٍ عاديّ،
 * لا تلتقي بأرقام الشحنات — تبدأ ستّة أرقام وتكبر بالتدريج — إلّا بعد تسعين مليون شحنة.
 */
class WaybillBook extends Model
{
    use BelongsToCompany, SeenByBranch;

    public const BASE = 90_000_000;

    public const MAX_SERIAL = 9_999_999;

    /** أكثر الوصولات في دفترٍ واحد: ما تطبعه طابعة ملصقاتٍ في جلسة */
    public const MAX_SIZE = 200;

    /** مقاسا الطباعة بالمليمتر: [العرض، الطول] — ملصقات الطابعات الحرارية */
    public const PRINT_SIZES = [
        '80x120'  => [80, 120],
        '100x100' => [100, 100],
    ];

    /** ما يُطبع أسفل الوصل إن لم تكتب الشركة شروطها (إعدادات ← بيانات الشركة) */
    public const DEFAULT_TERMS = [
        'المبلغ المكتوب هو ما يدفعه الزبون، وأجرة التوصيل منه.',
        'لا يُدفع للمندوب شيءٌ فوق المبلغ المكتوب.',
        'الشركة تنقل الطلب ولا تسأل عن محتواه أو جودته.',
        'تُراجَع تفاصيل الطلب خلال ثلاثين يوماً من تاريخه.',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'printed_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public static function codeFor(int $serial): string
    {
        return (string) (self::BASE + $serial);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(fn (int $serial) => self::codeFor($serial), range($this->from_serial, $this->to_serial));
    }

    public function firstCode(): string
    {
        return self::codeFor($this->from_serial);
    }

    public function lastCode(): string
    {
        return self::codeFor($this->to_serial);
    }

    /** الرقم كما يُخزَّن: أرقامٌ لاتينية بلا فراغات — الماسحة والهاتف العربيّ يكتبانه مختلفَين */
    public static function normalise(string $code): string
    {
        return preg_replace('/\s+/', '', Phone::latinDigits(trim($code)));
    }

    /** تسلسل الرقم المطبوع، أو null لما ليس وصلاً مطبوعاً */
    public static function serialOf(string $code): ?int
    {
        $code = self::normalise($code);

        if (! preg_match('/^9\d{7}$/', $code)) {
            return null;
        }

        $serial = (int) $code - self::BASE;

        return $serial >= 1 ? $serial : null;
    }

    /**
     * دفتر الرقم المطبوع في الشركة الحالية: الدفاتر لا تتداخل، فهو آخر دفترٍ يبدأ
     * قبل الرقم إن بلغه — صفٌّ واحد من الفهرس مهما كثرت الدفاتر.
     */
    public static function forCode(string $code): ?self
    {
        $serial = self::serialOf($code);

        $book = $serial === null ? null : static::query()
            ->where('from_serial', '<=', $serial)
            ->orderByDesc('from_serial')
            ->first();

        return $book && $book->to_serial >= $serial ? $book : null;
    }

    /** الرقم كما جاء في رابطٍ أو نموذج: نصٌّ قصير، وما سواه فارغ */
    public static function fromInput(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 40) : '';
    }

    /** شروط الوصل: ما كتبته الشركة، سطراً سطراً، وإلّا الافتراضيّة */
    public static function terms(Company $company): array
    {
        $written = collect(preg_split('/\R/u', (string) $company->setting('waybill.terms')))
            ->map(fn ($line) => trim($line))->filter()->values()->all();

        return $written ?: self::DEFAULT_TERMS;
    }
}
