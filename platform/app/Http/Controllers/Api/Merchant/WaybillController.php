<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Waybills\IssueWaybillBook;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\WaybillBook;
use App\Support\Arabic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * «وصولات للطباعة» في تطبيق التاجر (docs/plan/57): دفاتره بما استُعمل منها، ودفترٌ جديد — والطباعة
 * صفحة البوابة نفسها تُفتح في متصفّح الهاتف برابطٍ موقَّعٍ لساعة، فتطبع بطابعته أو تُحفظ PDF.
 */
class WaybillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $books = WaybillBook::where('merchant_id', $merchant->id)
            ->withCount(['shipments as used_count' => fn ($q) => $q->withTrashed()])
            ->latest('id')->limit(30)->get();

        return response()->json([
            'max'   => WaybillBook::MAX_SIZE,
            'sizes' => collect(WaybillBook::PRINT_SIZES)->keys()->map(fn ($s) => ['value' => $s, 'label' => str_replace('x', '×', $s).' مم'])->values(),
            'books' => $books->map(fn (WaybillBook $b) => [
                'id'    => $b->id,
                'range' => $b->firstCode().'–'.$b->lastCode(),
                'size'  => (int) $b->size,
                'label' => Arabic::waybills((int) $b->size),
                'used'  => (int) $b->used_count,
                'at'    => ($b->printed_at ?? $b->created_at)?->toIso8601String(),
                'print' => $b->used_count < $b->size ? self::links($b) : null,
            ])->values(),
        ]);
    }

    public function store(Request $request, IssueWaybillBook $issue): JsonResponse
    {
        $data = $request->validate([
            'size'       => ['required', 'integer', 'min:1', 'max:'.WaybillBook::MAX_SIZE],
            'print_size' => ['required', Rule::in(array_keys(WaybillBook::PRINT_SIZES))],
        ], [], ['size' => 'عدد الوصولات', 'print_size' => 'المقاس']);

        $book = $issue->handle($request->attributes->get('merchant'), (int) $data['size'], $request->user(), 'app');

        return response()->json([
            'range'   => $book->firstCode().'–'.$book->lastCode(),
            'print'   => self::links($book)[$data['print_size']],
            'message' => 'جاهزٌ دفترٌ من '.Arabic::waybills((int) $book->size).': '.$book->firstCode().'–'.$book->lastCode().'.',
        ], 201);
    }

    /** رابط الطباعة لكلّ مقاس — موقَّعٌ لساعة، فلا يُفتح دفترٌ بغيره */
    public static function links(WaybillBook $book): array
    {
        return collect(WaybillBook::PRINT_SIZES)->keys()->mapWithKeys(fn ($size) => [
            $size => URL::temporarySignedRoute('app.waybills.print', now()->addHour(), ['book' => $book->id, 'size' => $size]),
        ])->all();
    }
}
