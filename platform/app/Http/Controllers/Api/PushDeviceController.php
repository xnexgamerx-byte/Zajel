<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** تسجيل هاتف الإشعارات بعد الدخول، وإلغاؤه عند الخروج (docs/plan/82). */
class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        // الرمز نفسه قد يكون مربوطاً بشركةٍ أخرى من الهاتف نفسه: يُعاد ربطه بصاحبه الحالي
        PushDevice::withoutGlobalScopes()->where('token', $data['token'])->delete();
        PushDevice::create([
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'سُجّل الهاتف للإشعارات.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        PushDevice::query()->where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['message' => 'أُلغي تسجيل الهاتف.']);
    }
}
