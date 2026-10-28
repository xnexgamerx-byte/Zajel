import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'api.dart';
import 'config.dart';

/// إشعارات الهاتف (docs/plan/82): «وصلت شحنتك» وقرار المعالجة تصل الهاتف والتطبيق مغلق.
///
/// إعداد Firebase يأتي مع البناء (`--dart-define`) من سرّ المستودع، لا من ملفٍّ في الكود. وبلا إعداد —
/// نسخة العرض والاختبارات، أو بناءٌ بلا السرّ — لا شيء هنا يعمل، ويبقى التطبيق كما هو بجرسه.
///
/// الخادم يرسل إلى رمز FCM نفسه (`message.token` في HTTP v1)، فيبقى getToken/onTokenRefresh هنا: طريق
/// «FID» الجديد في firebase_messaging 17 هدفه غير هذا الرمز، ولا يتبدّل إلّا مع الخادم.
class Push {
  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');

  static bool get configured =>
      _apiKey.isNotEmpty &&
      _appId.isNotEmpty &&
      _senderId.isNotEmpty &&
      _projectId.isNotEmpty &&
      !AppConfig.demo &&
      !kIsWeb &&
      !AppConfig.testing;

  static bool _ready = false;
  static String? _token;

  /// إشعارٌ وصل والتطبيق مفتوح: لا يظهر في شريط الهاتف، فيُعرض داخل التطبيق
  static void Function(String title, String body)? onForeground;

  static Future<void> _init() async {
    if (_ready || !configured) return;
    try {
      await Firebase.initializeApp(
        options: const FirebaseOptions(
          apiKey: _apiKey,
          appId: _appId,
          messagingSenderId: _senderId,
          projectId: _projectId,
        ),
      );
      _ready = true;
      FirebaseMessaging.onMessage.listen((m) {
        final n = m.notification;
        if (n != null) onForeground?.call(n.title ?? '', n.body ?? '');
      });
      // ignore: deprecated_member_use
      FirebaseMessaging.instance.onTokenRefresh.listen(_register);
    } catch (e) {
      debugPrint('push: $e');
    }
  }

  /// بعد الدخول، ومع كلّ فتحٍ وهو داخل: يُسأل الإذن مرّةً، ويُسجَّل الهاتف برمزه
  static Future<void> register() async {
    await _init();
    if (!_ready) return;
    try {
      await FirebaseMessaging.instance.requestPermission();
      // ignore: deprecated_member_use
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) await _register(token);
    } catch (e) {
      debugPrint('push: $e');
    }
  }

  static Future<void> _register(String token) async {
    if (!Api.instance.signedIn) return;
    try {
      await Api.instance.registerDevice(token);
      _token = token;
    } catch (e) {
      debugPrint('push: $e');
    }
  }

  /// إذن الإشعارات في الهاتف (docs/plan/89): granted · denied · unknown — وoff حين بُنيت النسخة بلا إشعارات
  static Future<String> permission() async {
    await _init();
    if (!_ready) return 'off';
    try {
      final s = await FirebaseMessaging.instance.getNotificationSettings();
      return switch (s.authorizationStatus) {
        AuthorizationStatus.authorized || AuthorizationStatus.provisional => 'granted',
        AuthorizationStatus.denied => 'denied',
        _ => 'unknown',
      };
    } catch (_) {
      return 'unknown';
    }
  }

  /// قبل الخروج: الهاتف لا يُشعَر بعدها بشحنات من خرج
  static Future<void> unregister() async {
    if (!_ready) return;
    try {
      // ignore: deprecated_member_use
      final token = _token ?? await FirebaseMessaging.instance.getToken();
      if (token != null) await Api.instance.unregisterDevice(token);
      // ignore: deprecated_member_use
      await FirebaseMessaging.instance.deleteToken();
    } catch (e) {
      debugPrint('push: $e');
    }
    _token = null;
  }
}
