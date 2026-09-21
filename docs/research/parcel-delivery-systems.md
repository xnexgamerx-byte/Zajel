# بحث مُركَّز: أنظمة توصيل الطلبات والطرود (غير المطاعم)

> **التصحيح في النطاق**: المطلوب **تطبيق توصيل طلبات وشحنات (طرود)** — لا نظام مطاعم.
> هذا يُسقط نصف نتائج البحث السابق ويرفع مشاريع لم تظهر أصلاً لأن البحث كان مائلاً لمفردات الطعام.
>
> تاريخ: 2026-09-21 · كل رقم نتج عن استنساخ المستودع وعدّ محتواه.

---

## ما سقط وما صعد

| سقط (مطاعم/طعام) | لماذا |
|---|---|
| ❌ Enatega | جوهره طلب طعام من مطاعم |
| ❌ ALRAZEL/my-project | `restorant_app` هو أكبر تطبيقاته (19.5k سطر) |
| ❌ siam-cloud / kitchenasty / nextorders | مطاعم بالكامل |
| ❌ Edzeery | متاجر واشتراكات، بلا توصيل |

| صعد (طرود وشحنات) | لماذا |
|---|---|
| ✅ **el-joe/marketplace_platforms** | شركات شحن + مناديب + تطبيقاتهم |
| ✅ **lc3lx/Mrasil** | منصة شحن طرود خالصة |
| ✅ **mshari-11/firstlinelog.com** | منصة شركة لوجستيات سعودية حقيقية |
| ✅ Fleetbase | مصمَّم للطرود لا للطعام |
| ✅ Shipyaex | شركة شحن بالدفع عند الاستلام |

---

## 🏆 الترتيب لتوصيل الطلبات والطرود

| # | المشروع | أسطر الكود | ما يميّزه | عربي |
|---|---|---:|---|:---:|
| **1** | **el-joe/marketplace_platforms** | **422,545** | Monorepo: باكند + ٣ تطبيقات موبايل + واجهة + SQL + Postman | ✅ |
| **2** | **mshari-11/firstlinelog.com** | **188,805** | منصة شركة لوجستيات سعودية عاملة (16 مدينة) | ✅ |
| **3** | **lc3lx/Mrasil** | **86,904** | منصة شحن طرود خالصة: بوالص، مرتجعات، استبدالات | ✅ |
| **4** | **Fleetbase** | ضخم | المرجع المعماري (مفتوح المصدر فعلاً) | جزئي |
| **5** | **K-YEY/Shipyaex** | 19,144 | مفردات COD العربية الدقيقة | ✅ |
| 6 | dllni `Modules/Delivery` | 8,344 | `DeliveryCompany` + `Staff` + حساب مالي | ✅ |
| 7 | seadadevo/shipping-dashboard | 19,551 | لوحة شحن | ✅ |
| 8 | bighadj22/codflow | متوسط | COD + Apache-2.0 (رخصة حرة!) | ✅ |
| 9 | wityliti/witylogix | صغير | إدارة توصيل مرخّصة | ❌ |
| 10 | AmjedKhaled165/Hanln | صغير | تطبيق تتبع بأدوار | ✅ |

---

## 🥇 #1 — el-joe/marketplace_platforms

**`github.com/el-joe/marketplace_platforms`** · آخر تحديث 2026-09-20 · بدون رخصة

> ⚠️ لاحظ: هذا **غير** `marketplace_platform` (المفرد) الذي ورد في البحث السابق. هذه النسخة **monorepo أكبر بكثير** وأحدث.

### البنية الكاملة
```
backend/        Laravel 11 · 271 نموذج · 143 جدول · 163,401 سطر
frontend/       Next.js 15 App Router ·  41,260 سطر
carrier_app/    Flutter — تطبيق شركة الشحن ·  4,873 سطر
delivery_app/   Flutter — تطبيق المندوب    ·  4,652 سطر
partner_app/    Flutter — تطبيق التاجر     ·  5,115 سطر
travel_app/     Flutter                    ·  1,148 سطر
docs/ + master_plan.md + postman/ + marketplace_platform.sql
```
**المجموع: 422,545 سطر**

### نماذج الشحن والمناديب (31 نموذجاً)
```
ShippingCompany · ShippingCompanySupervisor · ShippingCarrier
CarrierClaim · CarrierPerformanceRating · ShippingFallbackRule
Shipment · ShipmentTrackingEvent · InboundShipment
ShippingRate · ShippingZone · ShippingWeightSlab · ShippingMethod
InternationalShippingRate · InternationalShippingEligibility
MarketplaceShippingRule · PlatformShippingSubsidy
VendorCityShippingSurcharge · WarehouseShippingSurcharge · CountryShippingSetting

DeliveryAgent · DeliveryAgentShift · DeliveryAgentEarning · DeliveryAgentPayout
DeliveryAgentCodSettlement · DeliveryAgentDocument · DeliveryAssignment
DeliveryZone · AgentLocationHistory · WebhookDelivery
```

### لماذا هو الأول
- **الوحيد** الذي فيه **تطبيق لشركة الشحن** + **تطبيق للمندوب** + **تطبيق للتاجر** في مستودع واحد
- `ShippingFallbackRule` — ناقل بديل عند فشل الأول (لا يوجد في أي مشروع آخر رأيته)
- `CarrierPerformanceRating` — تقييم أداء الشركات المشتركة (أداة إدارة تعاقدات)
- `DeliveryAgentCodSettlement` — تسوية النقد، أصعب مسألة في السوق العربي
- شحن **دولي** بقواعد أهلية وأسعار منفصلة
- **`marketplace_platform.sql`** — قاعدة بيانات كاملة جاهزة للاستيراد والدراسة
- **`postman/`** — مجموعة API جاهزة للتجريب
- **`master_plan.md`** — وثيقة هندسية ممتازة تبدأ بـ: *"Ground truth: Every formula, table, and route in this document was extracted from the live codebase — not assumed."*

### قاعدة ذهبية منه تستحق النقل لزاجل
من `master_plan.md`:
> **"All monetary values are BIGINT base-currency integers. `500` = 500 AED. No `/100`, no `*100`, no `.toFixed(2)` on raw DB values."**

تخزين المبالغ كأعداد صحيحة لا عشرية — يمنع أخطاء التقريب في الحسابات المالية. **طبّقها من أول يوم**، تصحيحها لاحقاً كابوس.

### نقاط الضعف
- تطبيقات الموبايل مبكرة (4-5 آلاف سطر لكل تطبيق)
- الشحن جزء من سوق إلكتروني ضخم (إعلانات، مبوّبة، سفر) — ستستخرج الجزء اللوجستي
- بلا رخصة

---

## 🥈 #2 — mshari-11/firstlinelog.com

**منصة First Line Logistics** — شركة لوجستيات سعودية حقيقية · 188,805 سطر · TypeScript

من `ARCHITECTURE.md` حرفياً:
> *"Saudi logistics platform for managing delivery drivers, fleet, finance, and operations across **7 platforms and 16 cities**"*

### البوابات
```
src/pages/admin/     لوحة الإدارة       admin-dashboard.html
src/pages/courier/   بوابة المندوب      courier-dashboard.html
src/pages/driver/    بوابة السائق       DriverLogin.tsx
UnifiedPortal.tsx    بوابة موحّدة
TrackLocation.tsx    تتبع المواقع
ForPlatforms.tsx     صفحة للمنصات المشتركة  ← نموذج B2B!
```
+ `ARCHITECTURE.md` · `CLAUDE.md` · `SECURITY.md` · `e2e/` · AWS Lambdas · Vercel

### لماذا يهمّك
هذا **نظام تشغيل شركة توصيل فعلية** لا مشروع تعليمي: إدارة سائقين وأسطول ومالية وعمليات عبر 16 مدينة، مع صفحة مخصصة للمنصات التي تتعاقد معها (`ForPlatforms`) — أي **نفس نموذجك**: منصات تربط على شركة التوصيل.

---

## 🥉 #3 — lc3lx/Mrasil

**منصة شحن طرود خالصة** · 86,904 سطر · Next.js · عربي

### المسارات — كلها شحن، صفر طعام
```
create-shipment      إنشاء شحنة
shipments / parcels  الشحنات والطرود
tracking             التتبع
custom-tracking      تتبع بعلامة تجارية خاصة  ← تبيعه للتجار
carriers             الناقلون
returns              المرتجعات
customer-return      مرتجع الزبون
replacements         الاستبدالات
customer-replacement استبدال الزبون
invoices             الفواتير
tax-invoices         الفواتير الضريبية  ← امتثال ZATCA
payments             المدفوعات
contracts            العقود  ← مع التجار المشتركين
integrations         التكاملات
webhooks             الـ webhooks  ← ربط الشركات
customers / locations / orders / store / support / team / settings
```

> **`returns` + `replacements` + `customer-return` + `customer-replacement`** — أربعة مسارات للمرتجعات والاستبدالات. في سوق الدفع عند الاستلام المرتجعات تصل **30-40%** من الشحنات، وهي العملية التي تُفلس شركات التوصيل إن لم تُدَر جيداً. **لا Fleetbase ولا أي مشروع غربي يعطيها هذا الاهتمام.**
>
> و`custom-tracking` (صفحة تتبع بعلامة التاجر) + `contracts` + `webhooks` = **بالضبط أدوات منصة اشتراكات B2B**.

---

## #4 — Fleetbase (المرجع المعماري المفتوح)

يبقى الأفضل معمارياً، وهو **مصمَّم للطرود** لا للطعام:
```
Payload + Entity          الشحنة ومحتوياتها (طرد أو عدة أصناف)
ServiceRateParcelFee      تسعير خاص بالطرود
tracking-numbers          أرقام تتبع + إنشاء من QR
Manifest + ManifestStop   بيان الشحن ومحطاته
POST manifests/{id}/optimize   تحسين ترتيب المحطات
Proof                     إثبات تسليم (توقيع/صورة/QR)
```
وهو **الوحيد المفتوح المصدر فعلاً** بين الخمسة الأوائل (AGPL-3.0 + رخصة تجارية).

---

## الخلاصة العملية لزاجل

### المعمارية المقترحة
```
Fleetbase                → الهيكل: OrderConfig · ServiceRate · API · Webhooks
marketplace_platforms    → الأدوار: ShippingCompany · DeliveryAgent · تطبيقاتهم
                           + قاعدة BIGINT للمبالغ
Mrasil                   → المرتجعات والاستبدالات + custom-tracking + contracts
firstlinelog             → تنظيم بوابات admin/courier/driver + ForPlatforms
Shipyaex                 → مفردات COD: محصّل · مرتجع · أسباب رفض · محافظات
dllni Modules/Delivery   → DeliveryCompany + Staff + FinancialAccount + تذاكر دعم
```

### ثلاث ميزات لا تؤجّلها (خاصة بالطرود لا الطعام)
1. **المرتجعات والاستبدالات** — أهم من ميزة التتبع نفسها في سوق COD. ابنِها في النواة لا كإضافة.
2. **تسوية النقد مع المندوب** — عهدة يومية، تحصيل، تسوية. `DeliveryAgentCodSettlement` نموذج جاهز.
3. **صفحة تتبع بعلامة التاجر** (`custom-tracking`) — ميزة تُباع في الخطط الأعلى، ورخيصة التنفيذ.

### وتذكّر
المبالغ **BIGINT** لا `decimal`، ولا `/100` ولا `toFixed(2)` على قيم قاعدة البيانات.

---

*كل المشاريع العربية أعلاه بلا رخصة = حقوق محفوظة. ادرس نموذج البيانات (غير محمي) واكتب كودك بنفسك، أو راسل أصحابها.*
