import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;

/// إعداد الشركة: ما يفرّق تطبيق الزاجل عن تطبيق البرق — لا شيء غيره في الكود.
///
/// يُبنى كلّ تطبيقٍ بملفّه: `flutter build apk --dart-define-from-file=config/zajel.json`
/// (docs/plan/48). والاسم واللون والشعار الحيّة تأتي من النظام عند الدخول وتغلب هذه.
class AppConfig {
  /// عنوان نظام الشركة: https://zajel.wahajiq.net
  static const apiUrl = String.fromEnvironment('API_URL');

  /// اسم الشركة قبل الدخول (شاشة الدخول)
  static const companyName = String.fromEnvironment('COMPANY_NAME', defaultValue: 'شركة التوصيل');

  /// لون العلامة قبل أن يصل لون الشركة من النظام
  static const brandColor = String.fromEnvironment('BRAND_COLOR', defaultValue: '#FE0A0E');

  /// بياناتٌ تجريبية بلا نظام — للقطات المتجر ومطابقة التصميم
  static const demo = bool.fromEnvironment('DEMO');

  /// تحت flutter test: لا مؤقّتات تتكرّر (تقليب الإعلانات) تبقى بعد الاختبار
  static bool get testing => !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');
}

/// عنوان صورةٍ أو ملفٍّ من النظام على عنوان النظام في إعداد التطبيق.
///
/// النظام يبني الروابط من الطلب الذي وصله، وخلف Cloudflare قد يراه «http» أو باسمٍ داخليّ —
/// والهاتف يرفض http ولا يعرف الاسم الداخليّ، فتظهر الصورة فارغة. المسار وحده من النظام.
String media(String url) => rebase(url, AppConfig.apiUrl);

String rebase(String url, String base) {
  if (base.isEmpty || url.isEmpty) return url;
  final u = Uri.parse(url);
  return Uri.parse(base).replace(path: u.path, query: u.hasQuery ? u.query : null).toString();
}
