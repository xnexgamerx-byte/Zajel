<?php

namespace App\Services\Orders\Speech;

/** تسجيلات المتصفّح: webm من أندرويد وكروم، وmp4 من الآيفون */
final class Recording
{
    /** ما يُقبل تسجيلاً كما يكشفه الخادم من محتواه */
    public const TYPES = ['audio/webm', 'video/webm', 'audio/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a',
        'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav'];

    public static function extension(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a'), str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'wav')  => 'wav',
            default                     => 'webm',
        };
    }
}
