<?php

namespace App\Services\Aggregator;

/** لم يردّ في الوقت: لا يُعاد الطلب فوراً، فقد يكون نُفّذ عندهم ولم يصلنا ردّه */
class AggregatorTimeout extends AggregatorException {}
