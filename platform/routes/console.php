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

/*
| تجديد الاشتراكات التلقائية كل ليلة، قبل أن يفتح أحدٌ لوحة المنصّة:
| ما يظهر فيها «انتهى ولم يُجدَّد» يكون حينها ما يحتاج قراراً فعلاً.
*/
Schedule::command('zajel:renew')
    ->dailyAt('01:00')
    ->timezone('Asia/Baghdad')
    ->withoutOverlapping();

/*
| مطابقة الدفتر كل ليلة بعد التجديد: الأرصدة بالقيود، والقيود بالشحنات.
| تخرج بغير الصفر إن وجدت فرقاً، فيلتقطها منبّه المهامّ.
*/
Schedule::command('zajel:reconcile')
    ->dailyAt('01:30')
    ->timezone('Asia/Baghdad')
    ->withoutOverlapping();
