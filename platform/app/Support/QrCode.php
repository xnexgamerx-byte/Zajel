<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as Encoder;
use chillerlan\QRCode\QROptions;

/**
 * رمز QR كرسمٍ SVG (chillerlan/php-qrcode، رخصة MIT).
 *
 * تصحيح الخطأ بالمستوى M: يبقى الرمز مقروءاً وإن اتّسخ الملصق أو انطوى
 * ربعه تقريباً، دون أن يكبر الرمز على ملصقٍ مساحته عشرة سنتيمترات.
 */
final class QrCode
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface'  => QRMarkupSVG::class,
            'outputBase64'     => false,
            'eccLevel'         => EccLevel::M,
            'addQuietzone'     => true,
            'quietzoneSize'    => 2,
            'svgAddXmlHeader'  => false,
            'drawLightModules' => false,
            'connectPaths'     => true,
        ]);

        return (new Encoder($options))->render($data);
    }

    /** للوسم <img>: الصورة تُرسَم من عنوانها، فلا يدخل الصفحةَ HTML خام. */
    public static function dataUri(string $data): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($data));
    }
}
