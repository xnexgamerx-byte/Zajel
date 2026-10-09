<?php

namespace App\Services\Orders\Ai;

/**
 * نموذجٌ يقرأ الطلب (docs/plan/40): يُعطى التعليمات وما أُرسل — صورةً أو نصّاً — ويُرجع
 * الحقول بمخطّطها. Claude في التشغيل (ClaudeOrderModel)، وبديلٌ ثابت في الاختبارات.
 */
interface OrderModel
{
    /**
     * @param  list<array<string, mixed>>  $system  كتل التعليمات
     * @param  list<array<string, mixed>>  $content  ما يُقرأ: كتلة صورة و/أو نصّ
     * @param  array<string, mixed>  $schema  مخطّط الجواب (JSON Schema)
     * @return array<string, mixed> الجواب مفكوكاً
     *
     * @throws UnreadableByModel ما لا جواب صالحاً منه: يُقرأ الطلب محلّياً
     */
    public function extract(array $system, array $content, array $schema): array;
}
