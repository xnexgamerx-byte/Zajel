import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:url_launcher/url_launcher.dart';

import 'config.dart';
import 'models.dart';
import 'palette.dart';

/// واتساب المندوب (docs/plan/74): زرٌّ بجانب كلّ اتّصال يفتح المحادثة في واتساب نفسه، ورسالةٌ للزبون
/// يكتبها المندوب مرّةً في «المزيد» فتُملأ باسم الزبون ووصله ومبلغه — يضغط «إرسال» وحسب.

/// الرقم كما يريده واتساب: دوليٌّ بلا «+» ولا صفر — 07701234567 ← 9647701234567
String? whatsappNumber(String? phone) {
  var n = (phone ?? '').replaceAll(RegExp(r'\D'), '');
  if (n.startsWith('00')) n = n.substring(2);
  if (n.startsWith('0')) n = '964${n.substring(1)}';
  if (n.length == 10 && n.startsWith('7')) n = '964$n';
  return n.length < 8 ? null : n;
}

Uri? whatsappUri(String? phone, {String? text}) {
  final n = whatsappNumber(phone);
  if (n == null) return null;
  final t = (text ?? '').trim();
  return Uri.parse('https://wa.me/$n${t.isEmpty ? '' : '?text=${Uri.encodeComponent(t)}'}');
}

/// محادثة واتساب مباشرةً مع الرقم — في تطبيق واتساب لا في المتصفّح
Future<void> openWhatsApp(String? phone, {String? text}) async {
  final uri = whatsappUri(phone, text: text);
  if (uri != null) await launchUrl(uri, mode: LaunchMode.externalApplication);
}

/// قالب رسالة الزبون: يُحفظ على هاتف المندوب، ويُملأ من الشحنة عند الضغط
class WhatsAppTemplate {
  WhatsAppTemplate._();

  static const _storage = FlutterSecureStorage();
  static const _key = 'courier_whatsapp_template';

  /// ما يُستبدل في القالب، وما يُكتب مكانه
  static const fields = [
    ('{الزبون}', 'اسم الزبون'),
    ('{الوصل}', 'رقم الوصل'),
    ('{المبلغ}', 'المبلغ'),
    ('{التاجر}', 'المتجر'),
    ('{المندوب}', 'اسمك'),
    ('{الشركة}', 'الشركة'),
  ];

  static const suggested =
      'مرحباً {الزبون}، معك {المندوب} مندوب {الشركة}. عندي طلبك من {التاجر} (الوصل {الوصل}) بمبلغ {المبلغ} د.ع، '
      'أنا بالطريق إليك. أرسل لي موقعك أو أقرب نقطة دالّة رجاءً.';

  /// القالب الحاليّ: المقترح حتى يغيّره المندوب، وفارغٌ إن مسحه (فيُفتح واتساب بلا رسالة)
  static final text = ValueNotifier<String>(suggested);

  /// اسم المندوب وشركته — من الدخول
  static String courier = '';
  static String company = '';

  /// في نسخة العرض والاختبارات يبقى في الذاكرة
  static bool get _memory => AppConfig.demo || AppConfig.testing;

  static Future<void> load() async {
    if (_memory) return;
    try {
      final saved = await _storage.read(key: _key);
      if (saved != null) text.value = saved;
    } catch (_) {
      // خزنةٌ لا تُقرأ: يبقى المقترح
    }
  }

  static Future<void> save(String value) async {
    text.value = value.trim();
    if (!_memory) await _storage.write(key: _key, value: text.value);
  }

  /// القالب مملوءاً من الشحنة
  static String fill(String template, ShipmentDetail d) => fillWith(template, {
    '{الزبون}': d.recipient.isEmpty ? 'عزيزنا' : d.recipient,
    '{الوصل}': d.number,
    '{المبلغ}': money(d.amount),
    '{التاجر}': (d.merchant ?? '').isEmpty ? 'المتجر' : d.merchant!,
    '{المندوب}': courier,
    '{الشركة}': company,
  });

  static String fillWith(String template, Map<String, String> values) {
    var out = template;
    values.forEach((k, v) => out = out.replaceAll(k, v));
    return out.replaceAll(RegExp(r'[ \t]{2,}'), ' ').trim();
  }
}
