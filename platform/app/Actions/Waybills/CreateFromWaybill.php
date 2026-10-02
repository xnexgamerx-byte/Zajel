<?php

namespace App\Actions\Waybills;

use App\Actions\Shipments\CreateShipment;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WaybillBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «شحنة من وصلٍ مطبوع»: يُمسح الوصل الذي كتب عليه التاجر بيده، وتُدخَل بياناته،
 * فتُنشأ الشحنة وعليها رقمه — يجدها به المندوب والمخزن والزبون كما يجدونها برقمها.
 *
 * الوصل يُستعمل مرّةً واحدة: القفل على دفتره يجعل مسحَين متزامنين للوصل نفسه
 * شحنةً واحدة لا اثنتين. ووصلٌ من دفتر تاجرٍ لا يُدخَل لتاجرٍ آخر.
 */
class CreateFromWaybill
{
    public function __construct(protected CreateShipment $create) {}

    /**
     * ما يمنع إدخال الرقم الآن، أو null — يسأله المسح قبل فتح النموذج. ومع
     * الموظّف: وصلٌ من دفتر تاجرٍ لا يراه فرعه يُدخله فرع التاجر.
     */
    public static function problem(string $code, ?User $user = null): ?string
    {
        $book = WaybillBook::forCode($code);

        if (! $book) {
            return 'الرقم '.WaybillBook::normalise($code).' ليس من وصولات الشركة المطبوعة.';
        }

        $used = Shipment::withTrashed()->where('barcode', WaybillBook::codeFor(WaybillBook::serialOf($code)))->first();

        if ($used) {
            return "استُعمل هذا الوصل للشحنة {$used->number}.";
        }

        if (self::merchantStopped($book)) {
            return 'تاجر هذا الوصل ('.$book->merchant?->business_name.') غير مفعّل الآن، فلا تُدخَل له شحنة.';
        }

        if ($user && $book->merchant_id && ! Merchant::visibleTo($user)->whereKey($book->merchant_id)->exists()) {
            return 'هذا الوصل من دفتر تاجرٍ في فرعٍ آخر، فيُدخله فرعه.';
        }

        return null;
    }

    /** دفترٌ لتاجرٍ موقوفٍ أو قيد الموافقة أو محذوف: لا تُدخَل له شحنة كما لا يظهر في قائمة النموذج */
    private static function merchantStopped(WaybillBook $book): bool
    {
        return $book->merchant_id !== null
            && (! $book->merchant || $book->merchant->trashed() || $book->merchant->status !== 'active');
    }

    public function handle(string $code, array $data, ?User $actor = null): Shipment
    {
        // الدفتر يُعرف قبل المعاملة، فيكون قفلُه أوّل ما تقرؤه: لقطة MySQL تُؤخذ عند أوّل
        // قراءةٍ عاديّة في المعاملة، ولو أُخذت قبل انتظار القفل لما رأى المسحُ الثاني
        // شحنةَ مسحٍ سبقه للوصل نفسه فأنشأ أخرى
        $found = WaybillBook::forCode($code);

        if (! $found) {
            throw ValidationException::withMessages([
                'waybill' => 'الرقم '.WaybillBook::normalise($code).' ليس من وصولات الشركة المطبوعة.',
            ]);
        }

        $code = WaybillBook::codeFor(WaybillBook::serialOf($code));

        return DB::transaction(function () use ($found, $code, $data, $actor) {
            $book = WaybillBook::query()->lockForUpdate()->findOrFail($found->id);

            if ($used = Shipment::withTrashed()->where('barcode', $code)->first()) {
                throw ValidationException::withMessages(['waybill' => "استُعمل هذا الوصل للشحنة {$used->number}."]);
            }

            if ($book->merchant_id && (int) $data['merchant_id'] !== (int) $book->merchant_id) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'هذا الوصل من دفتر '.$book->merchant?->business_name.'، فشحنته له.',
                ]);
            }

            if (self::merchantStopped($book)) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'تاجر هذا الوصل غير مفعّل الآن، فلا تُدخَل له شحنة.',
                ]);
            }

            return $this->create->handle($data + ['barcode' => $code, 'waybill_book_id' => $book->id], $actor);
        });
    }
}
