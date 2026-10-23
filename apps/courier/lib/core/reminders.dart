import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

import 'config.dart';
import 'models.dart';
import 'palette.dart';

/// تنبيه مواعيد المؤجّلة على هاتف المندوب (docs/plan/75): يُجدوَل في الهاتف نفسه فيصل في موعده ولو
/// كان التطبيق مغلقاً أو بلا شبكة، ويُعاد بعد إعادة تشغيل الهاتف. والمواعيد من الرئيسية كلّما فُتحت.
///
/// الموعد بساعته يُنبَّه قبله بربع ساعة، والموعد بيومه وحده يُنبَّه التاسعة صباح يومه. وضغط التنبيه يفتح
/// الشحنة نفسها.
class Reminders {
  Reminders._();

  static final _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;

  /// الشحنة التي فُتح التطبيق من تنبيهها — يفتحها الهيكل
  static final opened = ValueNotifier<int?>(null);

  /// قبل الموعد بساعته، والتاسعة صباحاً لما لا ساعة له
  static const before = Duration(minutes: 15);
  static const morning = 9;

  /// لا هاتف في العرض والاختبارات والويب
  static bool get _off => AppConfig.demo || AppConfig.testing || kIsWeb || !Platform.isAndroid;

  static const _details = NotificationDetails(
    android: AndroidNotificationDetails(
      'appointments',
      'مواعيد التسليم',
      channelDescription: 'تذكيرٌ بموعد تسليم الشحنات المؤجّلة',
      importance: Importance.high,
      priority: Priority.high,
      category: AndroidNotificationCategory.reminder,
    ),
  );

  static Future<void> init() async {
    if (_off || _ready) return;
    try {
      tzdata.initializeTimeZones();
      tz.setLocalLocation(tz.getLocation('Asia/Baghdad'));
      await _plugin.initialize(
        settings: const InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher')),
        onDidReceiveNotificationResponse: (r) => _open(r.payload),
      );
      final launch = await _plugin.getNotificationAppLaunchDetails();
      if (launch?.didNotificationLaunchApp ?? false) _open(launch!.notificationResponse?.payload);
      // أندرويد 13 فما فوق يسأل المندوب مرّةً: «السماح بالإشعارات»
      await _plugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
      _ready = true;
    } catch (_) {
      // هاتفٌ لا يجدول: تبقى التذكيرات في الرئيسية
    }
  }

  static void _open(String? payload) {
    final id = int.tryParse(payload ?? '');
    if (id != null) opened.value = id;
  }

  /// المواعيد كما هي الآن في النظام: يُلغى ما سبق ويُجدوَل ما بقي
  static Future<void> sync(List<Appointment> appointments) async {
    if (_off) return;
    if (!_ready) await init();
    if (!_ready) return;
    try {
      await _plugin.cancelAllPendingNotifications();
      final now = DateTime.now();
      for (final a in appointments.take(50)) {
        final at = remindAt(a, now: now);
        if (at == null) continue;
        await _plugin.zonedSchedule(
          id: a.id & 0x7fffffff,
          scheduledDate: tz.TZDateTime.from(at, tz.local),
          notificationDetails: _details,
          // بلا إذن «المنبّهات الدقيقة»: قد يتأخّر دقائق قليلة، ولذلك قبل الموعد بربع ساعة
          androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
          title: title(a),
          body: body(a),
          payload: '${a.id}',
        );
      }
    } catch (_) {
      // تبقى التذكيرات في الرئيسية
    }
  }

  /// متى يُنبَّه: قبل ساعته بربع ساعة، أو التاسعة صباح يومه — وما فات لا يُنبَّه به
  static DateTime? remindAt(Appointment a, {DateTime? now}) {
    final n = now ?? DateTime.now();
    final at = a.hasTime ? a.at.subtract(before) : DateTime(a.at.year, a.at.month, a.at.day, morning);
    return at.isAfter(n) ? at : null;
  }

  static String title(Appointment a) => 'موعد تسليم ${a.recipient.isEmpty ? 'شحنة' : a.recipient}';

  static String body(Appointment a) => [
    'الشحنة ${a.number}${(a.city ?? '').isEmpty ? '' : ' — ${a.city}'}',
    'موعدها ${appointmentLabel(a.at)}',
    if (a.amount > 0) 'للتحصيل ${money(a.amount)} د.ع',
  ].join(' · ');
}
