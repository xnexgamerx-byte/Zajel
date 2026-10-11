<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Support\CourierLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * هاتف المندوب يُرسل موقعه ما دامت شاشته مفتوحة (docs/plan/62). موقعٌ تقديره أوسع من كيلومترين
 * (شبكة بلا GPS) لا يُحفظ: يُري المندوب في غير مكانه.
 */
class LocationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (($data['accuracy'] ?? 0) <= 2000) {
            CourierLocation::record($request->attributes->get('courier'), (float) $data['lat'], (float) $data['lng']);
        }

        return response()->noContent();
    }
}
