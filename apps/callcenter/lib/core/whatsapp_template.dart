import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';
import 'palette.dart';

/// قالب رسالة الزبون في واتساب (docs/plan/80): تكتبه الموظّفة مرّةً من حسابها، فيُملأ من الشحنة —
/// اسم الزبون ووصله ومبلغه ومتجره ومنطقته وسبب آخر محاولة — حين تختار «واتساب» بدل الاتصال بالخط.
/// يُحفظ على هاتفها في الخزنة المشفّرة نفسها التي تحفظ دخولها.
class WhatsAppTemplate {
  WhatsAppTemplate._();

  static const _storage = FlutterSecureStorage();
  static const _key = 'callcenter_whatsapp_template';

  /// ما يُستبدل في القالب، وما يُكتب مكانه
  static const fields = [
    ('{الزبون}', 'اسم الزبون'),
    ('{الوصل}', 'رقم الوصل'),
    ('{المبلغ}', 'المبلغ'),
    ('{التاجر}', 'المتجر'),
    ('{المنطقة}', 'المنطقة'),
    ('{السبب}', 'سبب عدم التسليم'),
    ('{الموظفة}', 'اسمك'),
    ('{الشركة}', 'الشركة'),
  ];

  static const suggested =
      'مرحباً {الزبون}، معك {الموظفة} من {الشركة}. بخصوص طلبك من {التاجر} (الوصل {الوصل}) بمبلغ {المبلغ} د.ع '
      'إلى {المنطقة}: حاول المندوب توصيله ولم يتمّ ({السبب}). متى يناسبك أن نوصله إليك؟';

  /// القالب الحاليّ: المقترح حتى تغيّره، وفارغٌ إن مسحته (فيُفتح واتساب بلا رسالة)
  static final text = ValueNotifier<String>(suggested);

  /// اسم الموظّفة وشركتها — من الدخول
  static String agent = '';
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
  static String fill({
    required String recipient,
    required String number,
    required int amount,
    String? merchant,
    String? place,
    String? reason,
  }) => fillWith(text.value, {
    '{الزبون}': recipient.isEmpty ? 'عزيزنا' : recipient,
    '{الوصل}': number,
    '{المبلغ}': money(amount),
    '{التاجر}': (merchant ?? '').isEmpty ? 'المتجر' : merchant!,
    '{المنطقة}': (place ?? '').isEmpty ? 'عنوانك' : place!,
    '{السبب}': (reason ?? '').isEmpty ? 'لم نصل إليك' : reason!,
    '{الموظفة}': agent,
    '{الشركة}': company,
  });

  static String fillWith(String template, Map<String, String> values) {
    var out = template;
    values.forEach((k, v) => out = out.replaceAll(k, v));
    return out.replaceAll(RegExp(r'[ \t]{2,}'), ' ').replaceAll(RegExp(r'\(\s*\)'), '').trim();
  }
}
