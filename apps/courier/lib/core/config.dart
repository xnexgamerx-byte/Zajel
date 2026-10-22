import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;

/// إعداد الشركة: ما يفرّق «مندوب الزاجل» عن مندوب شركةٍ أخرى — لا شيء غيره في الكود.
///
/// يُبنى كلّ تطبيقٍ بملفّه: `flutter build apk --dart-define-from-file=config/zajel.json`
/// (docs/plan/66، كتطبيق التاجر في docs/plan/48). والاسم واللون الحيّان يأتيان من النظام عند الدخول.
class AppConfig {
  /// عنوان نظام الشركة: https://zajel.wahajiq.net
  static const apiUrl = String.fromEnvironment('API_URL');

  /// اسم الشركة قبل الدخول (شاشة الدخول)
  static const companyName = String.fromEnvironment('COMPANY_NAME', defaultValue: 'شركة التوصيل');

  /// لون العلامة قبل أن يصل لون الشركة من النظام
  static const brandColor = String.fromEnvironment('BRAND_COLOR', defaultValue: '#B3202A');

  /// لون التطبيق من ملف إعداده هو لونه (docs/plan/61): تطبيق الزاجل أحمر كتصميمه، ولو كان لون
  /// الشركة في النظام غيره (لون واجهة الموقع). ما لم يُكتب في الإعداد يأخذ لون الشركة من النظام.
  static const brandFixed = bool.hasEnvironment('BRAND_COLOR');

  /// بياناتٌ تجريبية بلا نظام — للقطات المتجر ومطابقة التصميم
  static const demo = bool.fromEnvironment('DEMO');

  /// اسم التطبيق على الهاتف وفي شاشة الدخول: «مندوب الزاجل»
  static const appName = 'مندوب $companyName';

  /// تحت flutter test: لا مؤقّتات تتكرّر (إرسال الموقع) تبقى بعد الاختبار
  static bool get testing => !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');
}
