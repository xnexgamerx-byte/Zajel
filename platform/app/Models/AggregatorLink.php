<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط الشركة بنظام الشركة الوسيطة (docs/plan/104): واحدٌ لكل شركة.
 *
 * كلمة المرور ورمز الدخول ورمز التاجر مشفّرةٌ بمفتاح التطبيق ولا تُعرض. ومفتاح الـ Webhook الذي
 * يرسلونه في كل طلبٍ يُحفظ ببصمته وآخر أربعة أحرفٍ منه: يُرى مرّةً واحدة حين يُولَّد.
 */
class AggregatorLink extends Model
{
    use BelongsToCompany;

    public const DEFAULT_BASE_URL = 'https://agg-iq.net/operation/v1';

    /** الرمز يعيش ١٥ يوماً عندهم: يُجدَّد قبلها بيوم */
    public const TOKEN_DAYS = 14;

    /** ما يُفعل بحالةٍ من حالاتهم عندنا */
    public const ACTIONS = [
        'none' => 'تُسجَّل فقط',
        'track' => 'سطر تتبّع يراه التاجر',
        'out_for_delivery' => 'قيد التوصيل',
        'delivered' => 'واصل',
        'failed_attempt' => 'لم يُسلَّم (للمعالجة)',
        'postponed' => 'مؤجّل',
        'price_change' => 'تغيّر السعر (تذكرة للمتابعة)',
    ];

    /** ما يفعله كل خيار بالشحنة وما يراه التاجر، بكلام الشاشة */
    public const ACTION_HINTS = [
        'none' => 'لا يتغيّر شيءٌ على الشحنة ولا يراه التاجر؛ يبقى في سجلّ الربط فقط، لتعرف أنّه وصل.',
        'track' => 'حالة الشحنة عندنا لا تتغيّر، ويُضاف سطرٌ إلى مسارها بنصّ حالتهم (مثل «في مخزن بغداد»)، ويراه التاجر في تتبّع شحنته.',
        'out_for_delivery' => 'تصير «قيد التوصيل» عندنا.',
        'delivered' => 'تصير «واصل»، ومبلغها نقدٌ بيد الوسيط يُحاسَب بكشف المندوب. وإن سلّموها بمبلغٍ غير مبلغ الوصل فلا تُسجَّل بل تنتظر موافقة الكول سنتر.',
        'failed_attempt' => 'تصير «لم يُسلَّم» بالسبب المختار، وتظهر في المعالجة ليقرّر الكول سنتر إعادة التوصيل أو الإرجاع.',
        'postponed' => 'تصير «مؤجّل» بالسبب المختار.',
        'price_change' => 'تُفتح تذكرة «السعر تغيّر» عند الكول سنتر، ويصله إشعار، ولا يتغيّر المبلغ إلّا بموافقته. تجدها في «الطلبات» ← «طلبات المناديب لتغيير المبلغ».',
    ];

    /**
     * ما نعرفه من حالاتهم من وثائقهم، والباقي سطر تتبّع حتى يُربط. «واصل» يأتي أيضاً بعلامة
     * deliver_done أيّاً كانت الحالة.
     */
    public const DEFAULT_MAP = [
        '1' => 'none',
        '2' => 'track',
        '3' => 'out_for_delivery',
        '12' => 'track',
        '13' => 'track',
        '15' => 'none',
        '23' => 'failed_attempt',
        '44' => 'track',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'aggre_token', 'merchant_token', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'aggre_token' => 'encrypted',
            'merchant_token' => 'encrypted',
            'aggre_token_at' => 'datetime',
            'catalog' => 'array',
            'status_map' => 'array',
            'sends_orders' => 'boolean',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /** ما يُفعل بحالتهم: ما اختارته الشركة، وإلّا ما نعرفه، وإلّا سطر تتبّع */
    public function actionFor(?string $statusId): string
    {
        $map = ($this->status_map ?? []) + self::DEFAULT_MAP;

        return $map[(string) $statusId] ?? 'track';
    }

    /** رقم الطلب في نظامهم: البادئة المسجّلة عندهم ثم رقم الوصل عندنا */
    public function companyOrderId(Shipment $shipment): string
    {
        return ($this->order_prefix ?? '').$shipment->number;
    }

    /** رقم الوصل عندنا من رقم الطلب عندهم */
    public function localNumber(string $companyOrderId): string
    {
        $prefix = (string) $this->order_prefix;

        return $prefix !== '' && str_starts_with($companyOrderId, $prefix)
            ? substr($companyOrderId, strlen($prefix))
            : $companyOrderId;
    }

    /** جاهزٌ لإرسال الطلبات: الحساب والتاجر والمندوب الوسيط وحجم الطرد */
    public function readyToSend(): bool
    {
        return $this->sends_orders && $this->courier_id && filled($this->username) && filled($this->password)
            && filled($this->merchant_id) && filled($this->package_size_id);
    }

    public function webhookAccepts(?string $token): bool
    {
        return $this->webhook_token_hash !== null && $token !== null && $token !== ''
            && hash_equals($this->webhook_token_hash, hash('sha256', $token));
    }
}
