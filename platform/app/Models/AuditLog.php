<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Audit\AuditChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * سجلّ التدقيق — لكل شركة سجلّها، وللنواة كلّها.
 *
 * كان بلا نطاق الشركة: قراءاته اليوم كلّها من النواة (حيث لا فلترة
 * أصلاً)، لكن أوّل قراءةٍ من داخل شركة كانت ستقرأ سجلّات الشركات كلّها
 * — مَن دخل بياناتها ومتى، وفواتيرها. والنطاق يُسقَط في وضع النواة،
 * فلوحة المنصّة ترى الكلّ كما كانت. وهو إضافةٌ فقط كالدفتر.
 *
 * وكل سطرٍ يحمل بصمة سابقه في سلسلة شركته (docs/plan/68 §١٣): يُكتب تحت قفل رأس السلسلة،
 * فلا يتفرّع سطران عن سابقٍ واحد، وما حُذف أو عُدِّل في القاعدة مباشرةً يكسرها (AuditChain).
 */
class AuditLog extends Model
{
    use AppendOnly, BelongsToCompany;

    public const UPDATED_AT = null;

    /**
     * أسماء الأفعال بالعربية. كانت الشاشتان تحملان قائمتين كُتبتا قبل ثلاثة
     * أفعالٍ أُضيفت بعدهما، فظهر «company_settings_updated» كما خُزِّن.
     * ويُلزم ArabicLabelsTest كل فعلٍ يُكتب في الشيفرة بأن يكون هنا.
     */
    public const ACTIONS = [
        'company_registered'       => 'سُجّلت شركة',
        'company_suspended'        => 'أُوقفت شركة',
        'company_activated'        => 'فُعّلت شركة',
        'company_settings_updated' => 'عُدِّلت بيانات الشركة',
        'company_updated'          => 'عدّلت المنصّة بيانات الشركة',
        'subscription_started'     => 'بدأ اشتراكٌ جديد',
        'subscription_cancelled'   => 'أُلغي الاشتراك',
        'impersonation_started'    => 'دخول إلى نظام شركة',
        'impersonation_ended'      => 'خروج من نظام شركة',
        'payment_recorded'         => 'سُجِّلت دفعة',
        'subscription_renewed'     => 'جُدِّد الاشتراك',
        'rank_created'             => 'أُنشئت مرتبة',
        'rank_updated'             => 'عُدِّلت مرتبة',
        'rank_deleted'             => 'حُذفت مرتبة',
        'user_rank_changed'        => 'تغيّرت مرتبة موظّف',
        'permissions_reset'        => 'أُزيل تخصيص الصلاحيات',
        'permission_granted'       => 'مُنحت صلاحية استثنائية',
        'permission_revoked'       => 'سُحبت صلاحية استثنائية',
        'shipments_exported'       => 'صُدِّرت قائمة شحنات',
        'shipment_deleted'         => 'مُسحت شحنة',
        'shipment_restored'        => 'استُرجعت شحنة ممسوحة',
        'settlement_draft_deleted' => 'حُذف كشفٌ مسودّة',
        'settlement_draft_edited'  => 'عُدِّل كشفٌ مسودّة',
        'collection_batch_deleted' => 'حُذفت وجبة تحصيل مسودّة',
        'login_changed'            => 'تغيّر اسم الدخول أو كلمة المرور',
        'feature_enabled'          => 'فُتحت ميزة',
        'feature_disabled'         => 'أُغلقت ميزة',
        'feature_price_changed'    => 'تغيّر رسم ميزة',
        'theme_changed'            => 'تغيّر مظهر النظام',
        'payment_notice_reported'  => 'أبلغت الشركة عن دفعة',
        'payment_notice_rejected'  => 'رُفض إبلاغٌ عن دفعة',
        'billing_exempt_changed'   => 'تغيّر الإعفاء من الإيقاف التلقائي',
        'collection_batch'         => 'حدثٌ في وجبة تحصيل',
        'period_prepared'              => 'أُعِدّ إقفال فترة',
        'period_closed'                => 'أُقفلت فترة',
        'period_preparation_cancelled' => 'أُلغي إعداد إقفال',
        'period_report_reissued'       => 'أُعيد إصدار تقرير فترة',
        'profit_withdrawal_requested'  => 'طُلب سحبٌ من الأرباح',
        'profit_withdrawal_approved'   => 'اعتُمد سحبٌ من الأرباح',
        'profit_withdrawal_paid'       => 'دُفع سحبٌ من الأرباح',
        'profit_withdrawal_cancelled'  => 'أُلغي طلب سحب',
        'profit_withdrawal_reversed'   => 'عُكس سحبٌ مدفوع',
        'finance_settings_updated' => 'عُدِّلت الإعدادات المالية',
        'branch_remittance_sent'     => 'أُرسل تسديدٌ بين الفروع',
        'branch_remittance_received' => 'استُلم تسديدٌ بين الفروع',
        'financial_alert'          => 'إجراءٌ على تنبيهٍ مالي',
    ];

    protected static function booted(): void
    {
        // بعد ملء company_id من سياق الشركة (BelongsToCompany): السلسلة سلسلة الشركة
        static::creating(function (AuditLog $log) {
            if ($log->created_at === null) {
                $log->created_at = $log->freshTimestamp();
            }

            $chain = (int) ($log->company_id ?? 0);
            $head = DB::table('audit_heads')->where('chain', $chain)->lockForUpdate()->first();

            if ($head === null) {
                DB::table('audit_heads')->insertOrIgnore(['chain' => $chain, 'updated_at' => now()]);
                $head = DB::table('audit_heads')->where('chain', $chain)->lockForUpdate()->first();
            }

            $log->prev_hash = $head->last_hash;
            $log->hash = AuditChain::hash($log->getAttributes(), $head->last_hash);
        });

        static::created(function (AuditLog $log) {
            DB::table('audit_heads')->where('chain', (int) ($log->company_id ?? 0))
                ->update(['last_id' => $log->id, 'last_hash' => $log->hash, 'updated_at' => now()]);
        });
    }

    /** الإضافة في معاملةٍ واحدة مع قفل رأس السلسلة وتحديثه */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return DB::transaction(fn () => parent::save($options));
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? (string) $this->action;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }
}
