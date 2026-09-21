# زاجل: تحليل الميزات وخارطة بناء منصة لوجستية بنظام اشتراكات (B2B SaaS)

> **الهدف المحدَّد**: منصة لوجستية متكاملة، الشركات تربط عليها عبر API وتدفع اشتراكات.
> **الترتيب**: النواة اللوجستية أولاً، ثم التطبيقات.
>
> كل ما في هذا المستند تم التحقق منه بقراءة الكود المصدري مباشرة (نماذج Eloquent، ملفات المسارات، الرخص)، بتاريخ 2026-09-21.

---

## 1. لماذا يتغيّر الاختيار عند نموذج الاشتراكات

نموذج "شركات تربط عليك وتدفع اشتراك" يفرض أربعة متطلبات لا يلبّيها تطبيق توصيل عادي:

| المتطلب | لماذا حاسم | من يوفّره |
|---|---|---|
| **تعدد المستأجرين (Multi-tenancy)** | كل شركة مشتركة = مستأجر معزول ببياناته ومستخدميه وإعداداته | Fleetbase فقط |
| **مفاتيح API + سجل الطلبات** | الشركة تربط نظامها عليك، وأنت تحتاج قياس الاستهلاك للفوترة | Fleetbase فقط |
| **Webhooks** | الشركة تحتاج إشعاراً فورياً عند تغيّر حالة الطلب | Fleetbase فقط |
| **محرّك تسعير قابل للتهيئة** | كل شركة بعقد وأسعار مختلفة | Fleetbase فقط |

**النتيجة**: من بين كل ما فُحص، **Fleetbase هو الوحيد المبني أصلاً كمنصة B2B متعددة المستأجرين**. البقية (Enatega، codflow، Siam) تطبيقات توصيل لشركة واحدة، ولو بنيت عليها ستعيد كتابة الطبقة الأهم بنفسك.

---

## 2. شرح ميزات Fleetbase بالتفصيل

### 2.1 النواة (`core-api`) — البنية التحتية للمنصة

تم التحقق من 50 نموذجاً في `core-api/src/Models/`. أهمها لنموذجك:

**أ) تعدد المستأجرين والهوية**
```
Company.php         ← الشركة المشتركة (المستأجر)
CompanyUser.php     ← ربط المستخدمين بالشركات (مستخدم واحد في عدة شركات)
User.php, Invite.php, Group.php, GroupUser.php
Role.php, Permission.php, Policy.php   ← صلاحيات دقيقة
LoginAttempt.php, VerificationCode.php, UserDevice.php
```
> كل نموذج في النظام يحمل `company_uuid` — العزل بين المستأجرين مبني في صميم قاعدة البيانات، لا مضاف لاحقاً.

**ب) طبقة الـ API — قلب نموذج الاشتراكات**
```
ApiCredential.php      ← مفاتيح API لكل شركة
ApiRequestLog.php      ← سجل كل نداء API  ← أساس الفوترة بالاستهلاك
ApiEvent.php           ← الأحداث
WebhookEndpoint.php    ← نقاط الـ webhook للشركة
WebhookRequestLog.php  ← سجل التسليم وإعادة المحاولة
```
ومعها **لوحة مطوّرين جاهزة** (`dev-engine`) فيها بالضبط: `api-keys`, `webhooks`, `events`, `logs`, `sockets`.
> هذه بالحرف الواجهة التي ستسلّمها للشركة المشتركة لتربط نظامها. بناؤها من الصفر وحده يكلّف شهوراً.

**ج) خدمة الزبائن**
```
ChatChannel, ChatMessage, ChatParticipant, ChatAttachment, ChatReceipt, ChatLog
Comment.php, Notification.php, Alert.php
```
> نظام محادثة كامل بقنوات ومشاركين ومرفقات وإيصالات قراءة — العمود الفقري لفريق خدمة الزبائن (زبون ↔ مندوب ↔ عمليات).

**د) التخصيص لكل شركة**
```
CustomField.php, CustomFieldValue.php   ← حقول مخصصة لكل مستأجر
Dashboard.php, DashboardWidget.php      ← لوحات مخصصة
Report.php, ReportExecution.php, ReportAuditLog.php
Setting.php, Template.php, Type.php, Category.php
Schedule*.php (7 نماذج)                 ← الجدولة والمناوبات
Extension.php, ExtensionInstall.php     ← سوق إضافات
```

---

### 2.2 المحرّك اللوجستي (`fleetops`) — ما تبيعه فعلياً

**أ) `OrderConfig` — أهم فكرة في النظام كله**

الحقول الفعلية: `name`, `namespace`, `key`, `version`, `flow`, `entities`, `company_uuid`, `core_service`, `tags`, `meta`

> `flow` و `entities` حقلا **JSON**، و`version` يعني **إصدارات**، و`company_uuid` يعني **لكل شركة تهيئتها الخاصة**.
>
> **الترجمة العملية**: دورة حياة الطلب معرَّفة كـ *بيانات* لا كـ *كود*. شركة مطاعم تعرّف مسارها (تحضير ← استلام ← توصيل)، وشركة شحن طرود تعرّف مسارها (تجميع ← فرز ← خط سير ← تسليم)، **وكلاهما على نفس النظام دون تعديل سطر برمجي واحد**.
>
> هذه بالضبط الميزة التي تجعل منصة الاشتراكات ممكنة. لو بنيت دورة الحياة داخل الكود، كل شركة جديدة = نسخة جديدة من النظام = انهيار النموذج التجاري.

**ب) محرّك التسعير — `ServiceRate` + `ServiceQuote`**

الحقول الفعلية في `ServiceRate`:
```
company_uuid, service_area_uuid, zone_uuid, order_config_uuid
base_fee, per_meter_flat_rate_fee, per_meter_unit
algorithm, rate_calculation_method, max_distance, max_distance_unit
has_cod_fee, cod_calculation_method, cod_flat_fee, cod_percent   ← الدفع عند الاستلام
has_peak_hours_fee, peak_hours_calculation_method,
  peak_hours_flat_fee, peak_hours_percent, peak_hours_start, peak_hours_end
currency, duration_terms, estimated_days
```
> **مفاجأة إيجابية**: رسوم **الدفع عند الاستلام** مدمجة أصلاً في محرّك التسعير (نسبة أو مبلغ ثابت)، ومعها **رسوم ساعات الذروة**. أي أن Fleetbase يفهم واقع السوق أكثر مما يوحي كونه مشروعاً غربياً.
>
> والتسعير مربوط بـ `zone_uuid` و`service_area_uuid` → **سعر مختلف لكل منطقة/محافظة**، وبـ `order_config_uuid` → **سعر مختلف لكل نوع خدمة**، وبـ `company_uuid` → **عقد مختلف لكل شركة مشتركة**. هذا بالضبط ما تحتاجه لتسعير الاشتراكات والعمولات.

**ج) نموذج البيانات اللوجستي (تم التحقق من 45+ نموذجاً)**
```
الطلبات:   Order, OrderConfig, Payload, Entity, Manifest, ManifestStop
الأسطول:   Driver, Fleet, FleetDriver, Vehicle, FleetVehicle
الجغرافيا: Place, Position, Route, Zone, ServiceArea, Geofence, GeofenceEventLog
الإثبات:   Proof (توقيع/صورة/QR)
التسعير:   ServiceRate, ServiceQuote, ServiceQuoteItem, ServiceRateFee,
           ServiceRateParcelFee, PurchaseRate
الأصول:    Asset, Equipment, Part, Device, Sensor, Telematic
الصيانة:   Maintenance, MaintenanceSchedule, Issue, FuelReport
الفحص:     InspectionForm, InspectionSubmission, InspectionItemResult, InspectionLink
```
> `Payload` + `Entity` يفصلان "الشحنة" عن "محتوياتها" — يعني نفس النظام يحمل وجبة طعام أو طرداً أو عدة أصناف. تصميم ناضج.

**د) واجهة الـ API العامة (`/v1/`) — ما ستبيعه للشركات**

| المجموعة | النقاط المتاحة |
|---|---|
| `orders` | إنشاء، عرض، تعديل، `start`، `complete`، `cancel`، `next-activity`، **`tracker`**، **`eta`**، `distance-and-time`، `comments` |
| إثبات التسليم | `capture-signature`، `capture-photo`، `capture-qr`، `proofs` |
| `service-quotes` | **تسعيرة فورية قبل إنشاء الطلب** — الشركة تسأل "كم يكلّف؟" قبل أن تطلب |
| `tracking-numbers` / `tracking-statuses` | أرقام تتبع + إنشاء من QR |
| `manifests` | عرض + **`optimize`** (تحسين ترتيب المحطات) |
| `zones` / `service-areas` / `geofences` | مناطق الخدمة + `events`، `dwell-report`، `driver/{id}/history` |
| `drivers` | تسجيل، دخول بـ SMS، `toggle-online`، `switch-organization`، الأجهزة |
| `customers` | تسجيل، دخول، طلباتهم، عناوينهم |
| `entities` / `payloads` / `places` / `contacts` / `vendors` | إدارة الموارد |

> **هذه واجهة لوجستية تجارية جاهزة.** شركة مشتركة تستطيع: تسأل عن السعر → تنشئ طلباً → تتابعه لحظياً → تستلم إشعار webhook عند التسليم → تسحب إثبات التسليم. دورة كاملة.

**هـ) تحسين المسارات**
`vroom-api` و `valhalla-api` ضمن تبعيات `composer.json` — محرّكا تحسين مسارات وملاحة مفتوحان حقيقيان، لا مجرد استدعاء لخرائط Google.

---

### 2.3 المحاسبة (`ledger`)

```
Account, Journal, Transaction, Wallet
Invoice, InvoiceItem
Gateway, GatewayTransaction
```
نظام قيد مزدوج + فواتير + محافظ + بوابات دفع. `Invoice` يحمل `order_uuid` و`customer_uuid` و`company_uuid` و`amount_paid` و`balance` و`sent_at` و`viewed_at`.
> يخدمك في ثلاث جهات: فوترة الشركات المشتركة، تسوية عهدة المندوبين النقدية، ومستحقات التجار.

---

## 3. ⚠️ الفجوة الحرجة: وحدة الاشتراكات غير مفتوحة

تم اكتشاف هذا بقراءة `core-api/src/Models/Company.php` سطر 175:

```php
public function billingSubscriptions(): HasMany
{
    return $this->hasMany('\\Fleetbase\\Billing\\Models\\Subscription', 'company_uuid', 'uuid');
}
```

النموذج يشير إلى `Fleetbase\Billing\Models\Subscription` — لكن:
- ❌ المستودع `github.com/fleetbase/billing` **غير موجود علناً** (تم الفحص)
- ❌ الحزمة **غير مدرجة** في `api/composer.json`
- ❌ **غير موجودة** في قائمة `.gitmodules`

**الاستنتاج**: وحدة الاشتراكات والفوترة الدورية هي الجزء التجاري المحجوز لـ Fleetbase Cloud. النظام مصمَّم لاستقبالها (خطّاف جاهز في `Company`)، لكن التنفيذ ليس مفتوحاً.

**ما يعنيه لك**: طبقة الاشتراكات هي **ما ستبنيه أنت** — وهي عملياً ليست عائقاً:
- `stripe/stripe-php` موجود أصلاً في التبعيات
- `ledger` يعطيك الفواتير والمحافظ والمعاملات
- `ApiRequestLog` يعطيك قياس الاستهلاك للفوترة حسب الحجم
- تحتاج فقط: `Plan`, `Subscription`, `SubscriptionItem` + مهمة تجديد دورية + حدود الخطة (Quota)

> **وهذه في الحقيقة ميزة لا عيب**: منطق التسعير والاشتراكات هو تحديداً ما يجب أن يكون ملكك أنت لا نسخة من غيرك.

---

## 4. مقارنة الميزات حسب احتياج نموذجك

| الميزة | Fleetbase | Enatega | codflow | Ever Demand |
|---|:---:|:---:|:---:|:---:|
| تعدد مستأجرين معزول | ✅ `Company` في كل نموذج | ❌ | ❌ متجر واحد | ⚠️ |
| مفاتيح API للشركات | ✅ `ApiCredential` | ❌ | ⚠️ مفاتيح بسيطة | ⚠️ |
| سجل نداءات API (للفوترة) | ✅ `ApiRequestLog` | ❌ | ❌ | ❌ |
| Webhooks بسجل تسليم | ✅ | ❌ | ⚠️ أحداث فقط | ❌ |
| لوحة مطوّرين | ✅ `dev-engine` | ❌ | ❌ | ❌ |
| دورة حياة طلب قابلة للتهيئة | ✅ `OrderConfig.flow` | ❌ ثابتة | ❌ ثابتة | ❌ |
| محرّك تسعير بالمناطق | ✅ `ServiceRate` | ❌ | ⚠️ بالمحافظة | ⚠️ |
| رسوم الدفع عند الاستلام | ✅ نسبة/ثابت | ❌ | ✅ | ❌ |
| رسوم ساعات الذروة | ✅ | ❌ | ❌ | ❌ |
| تسعيرة فورية قبل الطلب | ✅ `service-quotes` | ❌ | ⚠️ شحن فقط | ❌ |
| تحسين المسارات | ✅ VROOM+Valhalla | ❌ | ❌ | ⚠️ |
| إثبات تسليم (توقيع/صورة/QR) | ✅ `Proof` | ⚠️ | ⚠️ | ⚠️ |
| جيوفنس بأحداث وتقارير | ✅ | ❌ | ❌ | ⚠️ |
| محاسبة قيد مزدوج + فواتير | ✅ `ledger` | ❌ | ⚠️ | ⚠️ |
| محادثة بقنوات ومرفقات | ✅ | ⚠️ بسيطة | ❌ | ⚠️ |
| **اشتراكات دورية** | ❌ **مغلقة** | ❌ | ❌ | ❌ |
| تليماتكس/أجهزة المركبات | ✅ | ❌ | ❌ | ❌ |

---

## 5. خارطة البناء المقترحة

### المرحلة 0 — القرار القانوني (قبل أي كود)
Fleetbase تحت **AGPL-3.0**. لو عدّلته وقدّمته كخدمة للشركات، الرخصة تُلزمك بإتاحة مصدر تعديلاتك لمستخدمي الخدمة.

| الخيار | الوصف | ملائم إذا |
|---|---|---|
| **أ) رخصة تجارية** | شراء الرخصة التجارية من Fleetbase | تريد البناء المباشر عليه وإغلاق كودك |
| **ب) مرجع معماري** | تبني نظامك من الصفر مستلهماً نموذج بياناته | تريد ملكية كاملة وتقنية تختارها |
| **ج) الالتزام بـ AGPL** | تنشر تعديلاتك | لا مانع لديك، والميزة التنافسية في التشغيل لا الكود |

> **توصيتي**: (ب) للنواة مع استلهام دقيق من نموذج البيانات — ونموذج البيانات نفسه (أسماء الجداول والعلاقات) **ليس محمياً بالرخصة**، الأفكار حرة. وهذا يعطيك حرية اختيار تقنية حديثة بدل Ember.js.

### المرحلة 1 — النواة اللوجستية (أولويتك المعلنة)
1. تعدد المستأجرين: `Company` + `CompanyUser` + عزل على مستوى قاعدة البيانات + RBAC
2. نموذج الطلب: `Order` + `OrderConfig` (الـ flow كـ JSON) + `Payload` + `Entity`
3. الجغرافيا: `Place`, `Zone`, `ServiceArea`, `Geofence`
4. التسعير: `ServiceRate` + `ServiceQuote` (شامل رسوم COD وساعات الذروة)
5. التتبع: `Position` + WebSocket + `Proof`

### المرحلة 2 — طبقة الربط والاشتراكات (ما يجعله منتجاً تجارياً)
6. `ApiCredential` + مصادقة + تحديد معدّل (Rate limiting)
7. `ApiRequestLog` — قياس الاستهلاك
8. `WebhookEndpoint` + إعادة محاولة بتراجع أسّي
9. لوحة مطوّرين + توثيق OpenAPI + SDK
10. **الاشتراكات**: `Plan`, `Subscription`, `Quota` + Stripe/بوابة محلية + `Invoice`

### المرحلة 3 — العمليات وخدمة الزبائن
11. لوحة الإرسال (Dispatch): تعيين يدوي وآلي
12. محادثة بقنوات + تذاكر + ربط بالطلب
13. تحسين المسارات (VROOM ذاتي الاستضافة)
14. محاسبة: عهدة المندوب، التسوية اليومية، مستحقات التجار

### المرحلة 4 — التطبيقات (بعد استقرار النواة)
15. تطبيق المندوب — ادرس `navigator-app` و `enatega-multivendor-rider`
16. تطبيق/لوحة التاجر — ادرس `fleetbase/storefront` و `codflow/cod-client-astro`
17. بوابة تتبع الزبون — ادرس `fleetbase/customer-portal` (تقلّل ضغط الاتصالات كثيراً)
18. تطبيق الزبون — آخر شيء، وقد لا تحتاجه إن كان نموذجك B2B بحتاً

> **ملاحظة على الترتيب**: قرارك بتأجيل التطبيقات صحيح. التطبيقات واجهات على النواة — وتبديل واجهة سهل، أما تبديل نموذج بيانات بعد دخول عملاء فمؤلم جداً.

---

## 6. ماذا تأخذ من كل مشروع

| المشروع | خذ منه | لا تأخذ منه |
|---|---|---|
| **Fleetbase** | نموذج البيانات كاملاً، `OrderConfig.flow`، `ServiceRate`، طبقة API والـ webhooks، `dev-engine` | Ember.js (تقنية منحسرة)، ولا تنسخ الكود مباشرة إلا بقرار رخصة واعٍ |
| **Enatega** (MIT) | واجهات تطبيقات المندوب/التاجر/الزبون، أنماط Expo Router، بنية 32 لغة مع RTL | المعمارية الخلفية (غير متاحة أصلاً) |
| **codflow** (Apache-2.0) | تسوية نقد المندوبين، OTP عبر واتساب ضد الطلبات الوهمية، التقسيم بالمحافظة، RTL عربي أصلي | الارتباط بـ Cloudflare، وكونه متمركزاً حول تاجر واحد |
| **Ever Demand** | أنماط GraphQL للتوصيل | البناء عليه (alpha بإقرار مطوريه) |

---

*أُعدّ بتاريخ 2026-09-21 بقراءة الكود المصدري مباشرة. المرجع السابق: `delivery-platforms-2026.md`.*
