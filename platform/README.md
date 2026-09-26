# وهج العراق — منصّة إدارة شحنات متعدّدة الشركات

نواة واحدة، ولكل شركة توصيل مشتركة نظامها الخاص ببياناتها وعلامتها،
معزولة عزلاً كاملاً عن الشركات الأخرى على المنصّة نفسها.

## التشغيل محلياً

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

بيانات الدخول التجريبية تُطبع بـ:

```bash
php artisan zajel:demo-credentials
```

## التشغيل على خادم

Docker بأربع حاويات وشهادات HTTPS تلقائية لكل شركة، ونسخ احتياطيّ ليليّ:
[`deploy/README.md`](../deploy/README.md). وتجربة الشحنات الحقيقية الأولى:
[`docs/plan/16-pilot-guide.md`](../docs/plan/16-pilot-guide.md).

## البنية

```
platform/
├─ app/
│  ├─ Actions/Shipments/     إنشاء الشحنة وتغيير حالتها — المدخل الوحيد للكتابة
│  ├─ Enums/                 ShipmentStatus (المفردات + خريطة الانتقالات)، UserRole
│  ├─ Http/Middleware/       تحديد الشركة من النطاق الفرعي وحماية الجلسة
│  ├─ Models/Concerns/       BelongsToCompany — الفلترة التلقائية
│  ├─ Models/Scopes/         CompanyScope — يرمي إن غاب سياق الشركة
│  ├─ Services/              التسعير وتوليد الأرقام المتسلسلة
│  └─ Support/Tenancy/       TenantContext + Tenancy
├─ database/migrations/      36 جدولاً
└─ resources/views/          واجهات عربية RTL
```

## قرارات مثبّتة

| القرار | السبب |
|---|---|
| `company_id` في كل جدول من أول migration | إضافته لاحقاً تعني ترحيل بيانات مؤلماً وتسريباً محتملاً |
| المال `BIGINT` بالدينار الصحيح | لا كسور في العملة العراقية، ولا أخطاء تقريب في الحسابات |
| `landmark` إلزامي على الشحنة | لا رموز بريدية عاملة في العراق — النقطة الدالّة هي العنوان |
| `status` نصّ لا `ENUM` | إضافة حالة بعد ملايين الصفوف يجب ألّا تُقفل الجدول |
| `shipment_events` إضافة فقط | التتبّع والتقارير والمحاسبة تُبنى على سجلّ لا يُعدَّل |
| مندوب الاستلام ≠ مندوب التوصيل | وظيفتان بأرباح وتقارير مختلفة |

## الاختبارات

```bash
php artisan test
```

أهمّها `TenantIsolationTest`: يفشل البناء إن أمكن قراءة بيانات شركة من
سياق شركة أخرى، أو إن حمل نموذج جديد `company_id` بلا `BelongsToCompany`.
