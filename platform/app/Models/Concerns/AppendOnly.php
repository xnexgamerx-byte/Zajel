<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * سجلٌّ يُضاف إليه ولا يُعدَّل ولا يُحذف منه.
 *
 * الدفتر وحركات الصندوق وأحداث الشحنة وحركات التأمين: قيمتها كلّها في أنّ
 * ما كُتب يبقى كما كُتب، والتصحيح حركةٌ معاكسة تحمل سببها. وكان هذا
 * عُرفاً في التعليقات لا قيداً في الشيفرة — فسطرٌ واحد
 * $row->update([...]) يعيد كتابة التاريخ بلا أثر.
 *
 * يمنع التعديل والحذف عبر النموذج. أمّا الاستعلام المباشر
 * (Model::where(..)->update()) فلا تمرّ به أحداث النموذج؛ ومنعه على
 * مستوى القاعدة (صلاحيات مستخدمها) مذكورٌ في وثيقة المراجعة.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model) {
            throw new LogicException(class_basename($model).' سجلٌّ لا يُعدَّل: التصحيح حركةٌ معاكسة.');
        });

        static::deleting(function ($model) {
            throw new LogicException(class_basename($model).' سجلٌّ لا يُحذف منه.');
        });
    }
}
