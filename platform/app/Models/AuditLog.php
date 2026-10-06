<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * سجلّ التدقيق — لكل شركة سجلّها، وللنواة كلّها.
 *
 * كان بلا نطاق الشركة: قراءاته اليوم كلّها من النواة (حيث لا فلترة
 * أصلاً)، لكن أوّل قراءةٍ من داخل شركة كانت ستقرأ سجلّات الشركات كلّها
 * — مَن دخل بياناتها ومتى، وفواتيرها. والنطاق يُسقَط في وضع النواة،
 * فلوحة المنصّة ترى الكلّ كما كانت. وهو إضافةٌ فقط كالدفتر.
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
        'login_changed'            => 'تغيّر اسم الدخول أو كلمة المرور',
        'feature_enabled'          => 'فُتحت ميزة',
        'feature_disabled'         => 'أُغلقت ميزة',
        'feature_price_changed'    => 'تغيّر رسم ميزة',
        'theme_changed'            => 'تغيّر مظهر النظام',
        'payment_notice_reported'  => 'أبلغت الشركة عن دفعة',
        'payment_notice_rejected'  => 'رُفض إبلاغٌ عن دفعة',
        'billing_exempt_changed'   => 'تغيّر الإعفاء من الإيقاف التلقائي',
    ];

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
