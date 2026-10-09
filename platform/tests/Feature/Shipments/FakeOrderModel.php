<?php

namespace Tests\Feature\Shipments;

use App\Services\Orders\Ai\OrderModel;

/** نموذجٌ ثابت للاختبار: يحفظ ما سُئل ويُرجع جوابه، أو يرمي خطأه */
class FakeOrderModel implements OrderModel
{
    /** @var list<array{0: array, 1: array, 2: array}> */
    public array $calls = [];

    public array $answer = [];

    public ?\Throwable $failure = null;

    public function extract(array $system, array $content, array $schema): array
    {
        $this->calls[] = [$system, $content, $schema];

        if ($this->failure) {
            throw $this->failure;
        }

        return $this->answer;
    }
}
