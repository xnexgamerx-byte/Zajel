<?php

namespace App\Services\Orders;

use RuntimeException;

/** صورةٌ لا تُقرأ: رسالتها بالعربية لمن رفعها، لا تفاصيل الخادم */
final class UnreadableImage extends RuntimeException {}
