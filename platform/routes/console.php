<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| الفوترة الشهرية. تعمل ثاني كل شهر الساعة 2 فجراً بتوقيت بغداد،
| لا أوّله: شحنة سُلّمت آخر يوم قد يتأخّر تأكيدها ساعات.
*/
Schedule::command('zajel:bill')
    ->monthlyOn(2, '02:00')
    ->timezone('Asia/Baghdad')
    ->withoutOverlapping();
