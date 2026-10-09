/// إعداد الشركة: ما يفرّق تطبيق الزاجل عن تطبيق البرق — لا شيء غيره في الكود.
///
/// يُبنى كلّ تطبيقٍ بملفّه: `flutter build apk --dart-define-from-file=config/zajel.json`
/// (docs/plan/48). والاسم واللون والشعار الحيّة تأتي من النظام عند الدخول وتغلب هذه.
class AppConfig {
  /// عنوان نظام الشركة: https://zajel.wahaj.iq
  static const apiUrl = String.fromEnvironment('API_URL');

  /// اسم الشركة قبل الدخول (شاشة الدخول)
  static const companyName = String.fromEnvironment('COMPANY_NAME', defaultValue: 'شركة التوصيل');

  /// لون العلامة قبل أن يصل لون الشركة من النظام
  static const brandColor = String.fromEnvironment('BRAND_COLOR', defaultValue: '#FE0A0E');

  /// بياناتٌ تجريبية بلا نظام — للقطات المتجر ومطابقة التصميم
  static const demo = bool.fromEnvironment('DEMO');
}
