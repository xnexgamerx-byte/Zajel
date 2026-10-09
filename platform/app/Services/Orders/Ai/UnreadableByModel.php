<?php

namespace App\Services\Orders\Ai;

/** النموذج لم يُرجع جواباً صالحاً (رفض، أو انقطع، أو ليس JSON): يُقرأ الطلب محلّياً */
class UnreadableByModel extends \RuntimeException {}
