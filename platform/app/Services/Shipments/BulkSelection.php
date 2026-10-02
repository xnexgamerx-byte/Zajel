<?php

namespace App\Services\Shipments;

use App\Actions\Shipments\ChangeStatusInBulk;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * ما اختاره الموظّف من القائمة (_bulk_bar): الشحنات بأرقامها، أو «الكل» — كل ما
 * يطابق بحث القائمة حتى ChangeStatusInBulk::MAX.
 *
 * «الكل» يُعاد بحثه بالفلاتر نفسها ويُقارَن عدده بما رآه الموظّف: شحنةٌ دخلت
 * القائمة بعد فتحها لا يُعمل بها وهو لم يرَها. يشترك فيه تحديث الحالة من
 * القائمة واعتماد التسليم.
 */
final class BulkSelection
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'all'            => ['nullable', 'boolean'],
            'expected'       => ['exclude_unless:all,1', 'required', 'integer', 'min:1'],
            'filters'        => ['exclude_unless:all,1', 'nullable', 'array'],
            'filters.*'      => ['nullable', 'string', 'max:200'],
            'shipment_ids'   => ['exclude_if:all,1', 'required', 'array', 'min:1', 'max:'.ChangeStatusInBulk::MAX],
            'shipment_ids.*' => ['integer'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'shipment_ids.required' => 'اختر شحنةً واحدة على الأقل.',
            'shipment_ids.max'      => 'الحدّ '.ChangeStatusInBulk::MAX.' شحنة في المرّة — ضيّق البحث بيومٍ أو مندوب.',
        ];
    }

    /**
     * يقصر الاستعلام على المختار — أو يُرجع سبب الرفض نصّاً.
     *
     * @param  array<string, mixed>  $data  ما مرّ بقواعد rules()
     * @param  (Closure(Builder): Builder)|null  $within  شرطٌ يُضاف قبل العدّ (مرحلةٌ بعينها)
     */
    public static function resolve(Builder $query, array $data, ?Closure $within = null): Builder|string
    {
        if ($within) {
            $within($query);
        }

        if (! filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $query->whereIn('shipments.id', $data['shipment_ids']);
        }

        $filters = Arr::only((array) ($data['filters'] ?? []), ShipmentFilters::KEYS);
        ShipmentFilters::apply($query, Request::create('/', 'GET', $filters));

        $count = (clone $query)->count();
        $expected = (int) $data['expected'];

        if ($count !== $expected) {
            return 'تغيّرت القائمة منذ فتحتها: كانت '.number_format($expected)
                .' وصارت '.number_format($count).'. راجعها ثم أعد التحديث.';
        }

        if ($count > ChangeStatusInBulk::MAX) {
            return 'في القائمة '.number_format($count).' شحنة، والحدّ '
                .ChangeStatusInBulk::MAX.' في المرّة — اختر يوماً أو مندوباً ثم أعد.';
        }

        return $query;
    }
}
