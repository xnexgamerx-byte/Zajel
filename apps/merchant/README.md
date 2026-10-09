# تطبيق التاجر

تطبيق فلاتر بعلامة كل شركة — الخطة: `docs/plan/48-merchant-app.md`.

## الإعداد لكل شركة

ملفٌّ في `config/` لكل شركة، وهو الفرق الوحيد بين تطبيقٍ وآخر:

```json
{ "COMPANY_NAME": "الزاجل", "API_URL": "https://zajel.wahaj.iq", "BRAND_COLOR": "#FE0A0E" }
```

الاسم واللون هنا لشاشة الدخول فقط؛ بعد الدخول يأتيان من النظام (لون الشركة وشعارها).

## التشغيل

```bash
flutter pub get
flutter run --dart-define-from-file=config/zajel.json          # على هاتفٍ موصول
flutter build apk --dart-define-from-file=config/zajel.json    # ملف أندرويد
```

**نسخة العرض** ببيانات ملف التصميم، بلا نظام:

```bash
flutter build web --dart-define-from-file=config/demo.json
```

## الاختبار

```bash
flutter analyze
flutter test --dart-define-from-file=config/demo.json
```

## البنية

| الملف | ما فيه |
|---|---|
| `lib/core/config.dart` | إعداد الشركة من ملفها |
| `lib/core/api.dart` | واجهة النظام (`/api/v1`) والرمز في خزنة الجهاز |
| `lib/core/palette.dart` | ألوان التصميم ودرجات لون الشركة والخطّ |
| `lib/screens/home_screen.dart` | الرئيسية بقياسات ملف التصميم |
| `lib/screens/shell.dart` | الشريط السفلي والصفحات |
| `lib/screens/login_screen.dart` | الدخول |

الخطّ «القاهرة» (`assets/fonts`) برخصة OFL — `assets/fonts/OFL.txt`.
