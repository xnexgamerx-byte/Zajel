<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| مراكز المحافظات (docs/plan/61): الخريطة تُفتح على محافظة الفرع، ومندوبٌ لم يُرسل هاتفه موقعاً
| يُرسم تقريبياً عند مركز محافظة شحنته. مركز المدينة الأمّ لكلّ محافظة. لا يُمسّ ما كُتب من قبل.
*/
return new class extends Migration
{
    private const CENTRES = [
        'BGD' => [33.3152, 44.3661], 'BSR' => [30.5085, 47.7804], 'NIN' => [36.3350, 43.1189],
        'ERB' => [36.1911, 44.0092], 'SUL' => [35.5613, 45.4300], 'DHK' => [36.8669, 42.9503],
        'KIR' => [35.4681, 44.3922], 'NJF' => [32.0259, 44.3462], 'KRB' => [32.6160, 44.0249],
        'BBL' => [32.4637, 44.4196], 'ANB' => [33.4206, 43.3078], 'DYL' => [33.7466, 44.6437],
        'WST' => [32.5128, 45.8182], 'MYS' => [31.8356, 47.1440], 'DHQ' => [31.0439, 46.2576],
        'MTH' => [31.3099, 45.2803], 'QAD' => [31.9929, 44.9250], 'SAL' => [34.6071, 43.6782],
    ];

    public function up(): void
    {
        foreach (self::CENTRES as $code => [$lat, $lng]) {
            DB::table('governorates')->where('code', $code)->whereNull('lat')->update(['lat' => $lat, 'lng' => $lng]);
        }
    }

    public function down(): void
    {
        // المراكز لا تضرّ: تبقى
    }
};
