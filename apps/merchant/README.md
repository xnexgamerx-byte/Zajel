# تطبيق الشركة: التجّار والمناديب والكول سنتر

تطبيق فلاتر بعلامة كل شركة — الخطة: `docs/plan/48-merchant-app.md`. وهو تطبيقٌ واحدٌ لتجّار الشركة ومناديبها وموظّفي
الكول سنتر (`docs/plan/114-unified-app.md`، `docs/plan/115-unified-app-courier.md`): دخولٌ واحد، والنظام يقول قسم
الحساب فيُفتح.

| القسم | الكود | خطّته |
|---|---|---|
| التاجر | `lib/core`، `lib/screens`، `lib/widgets` | `docs/plan/48` وما بعدها |
| الكول سنتر | `lib/callcenter/` (core، screens، widgets) | `docs/plan/73` وما بعدها |
| المندوب | `lib/courier/` (core، screens، widgets) | `docs/plan/66` وما بعدها |

والمشترك بينهما في `lib/core`: الحساب ورمزه وقسمه (`account.dart`)، والدخول والخروج والإشعارات (`api.dart`،
`push.dart`)، وإعداد الشركة (`config.dart`). ونسخة العرض تفتح قسم الكول سنتر بـ`--dart-define=DEMO_SECTION=callcenter`،
وقسم المندوب بـ`DEMO_SECTION=courier`.

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
| `lib/main.dart` | التطبيق: الدخول ثم قسم الحساب |
| `lib/core/config.dart` | إعداد الشركة من ملفها |
| `lib/core/account.dart` | الحساب الداخل: رمزه وقسمه في خزنة الجهاز |
| `lib/core/api.dart` | واجهة النظام (`/api/v1`): الحساب وقسم التاجر |
| `lib/callcenter/core/api.dart` | واجهة قسم الكول سنتر (`/api/v1/callcenter`) |
| `lib/courier/core/api.dart` | واجهة قسم المندوب (`/api/v1/courier`) |
| `lib/core/palette.dart` | ألوان التصميم ودرجات لون الشركة والخطّ |
| `lib/screens/home_screen.dart` | الرئيسية بقياسات ملف التصميم |
| `lib/screens/shell.dart` | الشريط السفلي والصفحات |
| `lib/screens/login_screen.dart` | الدخول |

الخطّ «القاهرة» (`assets/fonts`) برخصة OFL — `assets/fonts/OFL.txt`.
