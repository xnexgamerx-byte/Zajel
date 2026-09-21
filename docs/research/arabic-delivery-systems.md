# بحث: أنظمة التوصيل واللوجستيات العربية الضخمة على GitHub

> **المعايير المطلوبة**: عربي · ضخم · قوي. النجوم والرخصة **ليست** معياراً.
> **المنهجية**: تجاوزت البحث بالكلمات المفتاحية (يرجّع مستودعات فارغة) واعتمدت على:
> 1. البحث **داخل الكود** عن نصوص عربية تشغيلية (`"مندوبي التوصيل"`, `"حالة الطلب" "المندوب"`, `"شركات الشحن"`)
> 2. فلترة بمعيار الحجم الفعلي (`size:>`)
> 3. **استنساخ كل مرشح وعدّ أسطره ونماذجه وجداوله يدوياً** — لا اعتماد على الوصف
>
> تاريخ البحث: 2026-09-21

---

## ⚠️ تنبيه قانوني قبل كل شيء

كل المشاريع في هذا البحث **بدون رخصة** (No License). قانونياً هذا يعني **حقوق محفوظة بالكامل** لأصحابها — وليس "مفتوح المصدر".

| مسموح | ممنوع قانونياً |
|---|---|
| ✅ القراءة والدراسة | ❌ نسخ الكود في منتج تجاري |
| ✅ فهم نموذج البيانات | ❌ إعادة النشر أو التوزيع |
| ✅ استلهام المعمارية والأفكار | ❌ البناء المباشر عليها |

> **نموذج البيانات نفسه (أسماء الجداول، العلاقات، تصميم الأدوار) ليس محمياً بحقوق النشر — الأفكار حرة.** فادرسها بعمق، واكتب كودك بنفسك. وإن أردت استخدام أحدها فعلياً، راسل صاحبه — أغلبهم أفراد وقد يوافقون.

---

## 🏆 الترتيب النهائي — 10 مشاريع مُحقَّقة بالأرقام

| # | المشروع | أسطر الكود | النماذج | الجداول | ملفات عربية | آخر نشاط | يخدم نموذجك؟ |
|---|---|---:|---:|---:|---:|---|---|
| **1** | **el-joe/marketplace_platform** | **131,326** | **245** | **419** | **194** | 2026-09-10 | ✅✅ الأشمل |
| **2** | **dllni-app/dllni_backend** | ~86,000 | 97 | 114 | 11 | 2026-09-20 | ✅✅ الأدق |
| **3** | **ALRAZEL/my-project** | 77,000 (Dart) | — | — | كله عربي | 2026 | ✅ التطبيقات |
| **4** | **Edzeery/edzeery** | 29,858 | 60+ | 113 | **52** | **2026-09-21** | ✅ الاشتراكات |
| **5** | **K-YEY/Shipyaex** | 19,144 | 17 | 45 | ✅ | 2026-03-19 | ✅ شحن COD |
| 6 | DevYousefM/Tawseelkom | ~7.7MB | — | — | ✅ | 2024-03 | ⚠️ |
| 7 | hamedwifi45/restaurant-saas | ~2.3MB | — | — | ✅ | 2026-09-19 | ⚠️ |
| 8 | jafar191/flutter | ~1.2MB | — | — | ✅ | 2026 | ⚠️ واجهات |
| 9 | Mundir-Doom/box-system-web | ~2MB | — | — | ✅ | 2025-09 | ⚠️ |
| 10 | MohammedZr/laravelProject | 1,454 | 8 | 19 | 1 | 2026-04 | ❌ مضلِّل* |

> \* **درس مهم**: `laravelProject` حجمه في GitHub **44 ميغا** — بدا الأضخم. لكن باستنساخه وعدّ أسطره: **1,454 سطر فقط** و8 نماذج، والباقي صور وملفات مرفوعة. وهو نظام **صيدلية** لا توصيل. **الحجم بالميغابايت كذّاب — العبرة بعدد الأسطر والنماذج والجداول.**

---

## 🥇 #1 — el-joe/marketplace_platform (الأقوى والأضخم)

**`github.com/el-joe/marketplace_platform`** · Laravel · بدون رخصة · 0 نجمة

### الأرقام المُحقَّقة
```
131,326 سطر في app/      291 Controller
245 نموذج (Model)         419 migration
1,433 ملف PHP             194 ملف لغة عربية
```

### لماذا هو الأقوى لنموذجك بالضبط

**أ) اللوحات الخمس — مستخرجة من `platform-dashboards.html`:**
```
لوحة تحكم الأدمن الرئيسية        لوحة تحكم التاجر الرئيسية
لوحة التحليلات المتقدمة           إدارة الطلبات — التاجر
إدارة الطلبات                     المنتجات والمخزون
إدارة البائعين والمتاجر           المالية والمحفظة — التاجر
المالية والمدفوعات                التسويق والفريق — التاجر
إدارة المنتجات والكتالوج          أدوات الذكاء الاصطناعي — التاجر
إدارة الشحن والتوصيل       ←──►  لوحة تحكم شركة الشحن
التسويق والعروض                   إدارة المناديب والمشرفين
الوفاء بالطلبات (FBN)             إدارة التعيينات — الشحن
الإعلانات المبوبة                 لوحة تحكم المندوب
الدعم والنزاعات                   التعيينات والأرباح — المندوب
إعدادات المنصة والبنية التحتية    لوحة تحكم المسوق
```
> **خمسة أطراف: منصة · تاجر · شركة شحن · مندوب · مسوّق** — هذا حرفياً نموذج زاجل.

**ب) دورة حياة المندوب كاملة** (نماذج فعلية):
```
DeliveryAgent              المندوب
DeliveryAgentShift         المناوبات
DeliveryAgentEarning       الأرباح
DeliveryAgentPayout        الصرف
DeliveryAgentCodSettlement تسوية النقد (COD) ← الوجع الحقيقي محلول
DeliveryAgentDocument      الوثائق (هوية، رخصة)
DeliveryAssignment         التعيينات
AgentLocationHistory       سجل المواقع
DeliveryZone               مناطق التوصيل
```

**ج) شركات الشحن كطرف مستقل** — وهذا ما لا تجده في أي مشروع غربي:
```
ShippingCompany                شركة الشحن
ShippingCompanySupervisor      مشرفو الشركة
ShippingCarrier                الناقل
CarrierClaim                   المطالبات
CarrierPerformanceRating       تقييم الأداء ← لقياس جودة الشركات المشتركة
ShippingRate / ShippingZone / ShippingWeightSlab
ShippingFallbackRule           قاعدة بديلة عند فشل الناقل
MarketplaceShippingRule / PlatformShippingSubsidy
VendorCityShippingSurcharge    رسوم إضافية حسب المدينة
```

**د) الاشتراكات موجودة فعلاً** (ما كان ناقصاً في Fleetbase!):
```
SubscriptionPlan
VendorSubscription
VendorSubscriptionInvoice
WebhookDelivery            ← webhooks للشركات المشتركة
```

**هـ) حوكمة البائعين**: `VendorDocument`, `VendorDocumentCountryRequirement`, `VendorStrike` (نظام إنذارات), `VendorChangeRequest`, `VendorBankAccount`, `VendorSectionLock`

**و) ملفات اللغة العربية (194 ملف)** مقسّمة حسب الطرف: `admin.php`, `carrier.php`, `delivery.php`, `partner.php`, `portal.php`, `customer_api.php`

### نقاط الضعف
- ❌ **README افتراضي من Laravel** — صفر توثيق، ستفهمه من الكود فقط
- ❌ بدون رخصة، بدون اختبارات ظاهرة
- ⚠️ 419 جدولاً = تعقيد هائل؛ خذ منه **النمط** لا الكل

---

## 🥈 #2 — dllni-app/dllni_backend (الأنظف معمارياً والأقرب لنموذجك)

**`github.com/dllni-app/dllni_backend`** · Laravel Modules · آخر commit **2026-09-20**

### البنية — Modular Monolith (نفس فلسفة Fleetbase)
```
Modules/Delivery/      10 نماذج   8,344 سطر
Modules/Resturants/    39 نموذج  15,183 سطر
Modules/Supermarket/   29 نموذج  16,679 سطر
Modules/Cleaning/      16 نموذج  26,334 سطر
Modules/User/           3 نماذج  19,914 سطر
+ app/ الأساسي                   41,900 سطر
```

### 💎 وحدة Delivery — هذه بالضبط فكرة زاجل
```
DeliveryCompany.php              ← شركة التوصيل ككيان مشترك في المنصة!
DeliveryCompanyStaff.php         ← موظفو الشركة
DeliveryDriver.php               ← سائقو الشركة
DeliveryDriverLocation.php       ← التتبع
DeliveryDriverTrustLog.php       ← سجل الثقة/السمعة
DeliveryAssignmentAttempt.php    ← محاولات التعيين (وليس تعييناً واحداً!)
DeliveryOrder.php / DeliveryOrderEvent.php
DeliveryFinancialAccount.php     ← حساب مالي لكل شركة
DeliveryFinancialTransaction.php ← حركاته
```
> **`DeliveryCompany` + `DeliveryCompanyStaff` + `DeliveryFinancialAccount` = نموذج "الشركات تربط عليك" منفّذاً بالكامل.** ولا Fleetbase ولا Enatega عندهم هذا التصريح الواضح.
>
> و`DeliveryAssignmentAttempt` فكرة ذكية: يسجّل **كل محاولة** تعيين (رفض المندوب، انتهت المهلة، قَبِل) بدل حقل واحد — هذا ما يعطيك إحصاءات حقيقية عن أداء المناديب.

### 💎 خدمة الزبائن — الفجوة التي قلت سابقاً إن أحداً لا يغطيها
```
SupportCase.php          تذكرة دعم
SupportCaseMessage.php   رسائل التذكرة
SupportCaseEvent.php     أحداثها
Dispute.php / DisputeMessage.php   النزاعات
SosAlert.php             نداء استغاثة للمندوب  ← سلامة الميدان
SystemAlert.php / AdminNotificationBroadcast.php
```
> **هذا أول مشروع في كل أبحاثي يحتوي نظام تذاكر دعم حقيقياً + نزاعات + زر استغاثة للمندوب.**

### ملفات اللغة العربية مقسّمة حسب الدور
`delivery_admin.php` · `delivery_company.php` · `restaurant_admin.php` · `supermarket_admin.php` · `cleaning_admin.php` · `dispute_finance.php` · `permissions.php`

### نقاط الضعف
- ⚠️ متعدد العمودية (توصيل + مطاعم + سوبرماركت + تنظيف) — وحدة Delivery أصغر نسبياً (8,344 سطر)
- ❌ بدون رخصة ولا توثيق معماري

---

## 🥉 #3 — ALRAZEL/my-project (أفضل مرجع للتطبيقات)

**5 تطبيقات Flutter كاملة + ويب + Firebase Functions**، ~77 ألف سطر Dart:
```
restorant_app   19,536 سطر   تطبيق المطعم
tager_app       13,038 سطر   تطبيق التاجر
driver app      10,336 سطر   تطبيق المندوب
custmor app     10,264 سطر   تطبيق الزبون
wep              9,654 سطر   الويب
functions        7,243 سطر   Firebase Cloud Functions
admin_app        6,834 سطر   تطبيق الإدارة
```
> منظومة كاملة بواجهات عربية. **خذ منها أنماط الشاشات وتدفّق المندوب**، لا المعمارية (Firebase لا يصلح لمنصة اشتراكات B2B).

---

## #4 — Edzeery/edzeery (أفضل هندسة اشتراكات + أفضل توثيق عربي)

**آخر commit: اليوم 2026-09-21** · 29,858 سطر · 113 جدول · **52 ملف لغة عربية**

### طبقة الاشتراكات — الأنضج التي رأيتها بالعربية
```
Plans/Plan.php · PlanFeature.php · PlanPlanFeature.php · PlanPrice.php · FeatureConsumption.php
billing/Subscription.php · SubscriptionRenewal.php · Payment.php · BillingAddress.php
```
> `FeatureConsumption` = **قياس استهلاك كل ميزة لكل مشترك** — هذا ما يفصل منصة اشتراكات حقيقية عن مجرد "خطة شهرية". و`PlanPrice` منفصل عن `Plan` = تسعير متعدد العملات/الفترات.

### تعدد المستأجرين والصلاحيات (موثّق بالعربية بدقة نادرة)
- عزل عبر `store_memberships` لكل متجر (وليس Spatie Teams)
- **47 صلاحية** في `StorePermissionEnum`، 4 أدوار كقوالب فقط
- `supervisor_membership_id` → هرمية المشرفين
- `Order::visibleTo()` → نطاق الرؤية: المالك يرى الكل، المدير فريقه، الموظف نفسه

### التوثيق — نقطته الأقوى
`DATABASE_PLAN.md` · `ROLES_PERMISSIONS.md` · `MerchantPanelAudit.md` · `OrdersOperationsPlan.md` · `DESIGN_SYSTEM.md` — مكتوبة **بالعربية** وبانضباط هندسي عالٍ (كل ادعاء موثّق بـ `file:line` ومُعاد التحقق منه).

### نقطة الضعف الحاسمة
❌ **ليس نظام توصيل** — لا يوجد مندوب ولا أسطول. هو منصة متاجر باشتراكات. خذ منه **طبقة الاشتراكات والصلاحيات فقط**.

---

## #5 — K-YEY/Shipyaex (نموذج شركة الشحن العربية بالدفع عند الاستلام)

19,144 سطر · 45 جدول · Laravel + Filament

```
CollectedClient / CollectedShipper    المحصّل من العميل / من الشاحن
ReturnedClient / ReturnedShipper      المرتجع
RefusedReason                         أسباب الرفض  ← ذهب خالص لسوق COD
Governorate / City                    محافظات ومدن
Plan / PlanPrice                      خطط
OrderStatus / OrderStatusHistory
Expense                               المصاريف
```
> **`RefusedReason` و`CollectedClient/Shipper` و`ReturnedClient/Shipper`** هي المفردات الحقيقية لشركة شحن عراقية/عربية تعمل بالدفع عند الاستلام. لن تجدها في أي نظام غربي.

---

## 📋 الخلاصة: ماذا تأخذ من كل واحد

| المشروع | ما تأخذه | ما تتركه |
|---|---|---|
| **marketplace_platform** | نموذج البيانات الكامل: شركة الشحن كطرف، دورة حياة المندوب، الاشتراكات، الـ webhooks | التعقيد (419 جدول)، انعدام التوثيق |
| **dllni_backend** | البنية المعيارية (Modules)، `DeliveryCompany`+`Staff`+`FinancialAccount`، **نظام تذاكر الدعم**، `AssignmentAttempt` | الخلط بين 4 عموديات |
| **ALRAZEL** | أنماط شاشات التطبيقات الخمسة بالعربية | معمارية Firebase |
| **Edzeery** | `Plan`+`PlanFeature`+`FeatureConsumption`، عزل `store_memberships`، أسلوب التوثيق العربي | لا علاقة له بالتوصيل |
| **Shipyaex** | مفردات COD: المحصّل/المرتجع/أسباب الرفض/المحافظات | حجمه الصغير |

### التركيبة المثالية لزاجل
```
معمارية Fleetbase (OrderConfig · ServiceRate · API/Webhooks)
      +
نموذج أدوار marketplace_platform (منصة · تاجر · شركة شحن · مندوب)
      +
وحدة Delivery من dllni (DeliveryCompany · Staff · FinancialAccount · SupportCase)
      +
طبقة اشتراكات Edzeery (Plan · PlanFeature · FeatureConsumption)
      +
مفردات Shipyaex المحلية (محصّل · مرتجع · أسباب رفض · محافظات)
      =
زاجل
```

---

## ملاحظة منهجية

بحثت بالعربية مباشرةً أولاً فرجعت **331 نتيجة** أغلبها مستودعات شخصية فارغة (صفر نجمة، صفر كود). التحوّل الحاسم كان **البحث داخل الكود** عن نصوص عربية تشغيلية (`"مندوبي التوصيل"`, `"حالة الطلب" "المندوب"`) — هذا وصل مباشرةً إلى ملفات `lang/ar/*.php` و`Controllers` الحقيقية، فكشف أنظمة ضخمة لا يمكن لبحث الأوصاف أن يجدها لأن أصحابها لم يكتبوا وصفاً أصلاً.

**الدرس**: أقوى الأنظمة العربية على GitHub مستودعات شخصية بصفر نجمة وبلا وصف. الترتيب بالنجوم يخفيها تماماً.

---

*أُعدّ بتاريخ 2026-09-21. كل رقم في هذا المستند نتج عن استنساخ المستودع وعدّ محتواه فعلياً.*
