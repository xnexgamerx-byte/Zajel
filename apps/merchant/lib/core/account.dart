import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';

/// أقسام التطبيق الموحّد (docs/plan/114): كلّ حسابٍ يدخل قسم دوره، والنظام يقول أيّها عند الدخول.
enum Section {
  merchant,
  callcenter;

  /// ما يرسله النظام («merchant»، «callcenter»)، وnull لما لا يعرفه هذا التطبيق (المندوب في تطبيقه)
  static Section? of(Object? value) => values.where((s) => s.name == value).firstOrNull;
}

/// الدخول كما يعود من النظام: القسم الذي يُفتح، وجواب الحساب يقرؤه القسم بنموذجه.
/// وفي نسخة العرض لا جواب (body: null): يفتح القسم ببياناته التجريبية.
class SignIn {
  const SignIn(this.section, this.body);

  final Section section;
  final Map<String, dynamic>? body;
}

/// الحساب الداخل على هذا الهاتف: رمزه وقسمه، في خزنة الجهاز المشفّرة — مرّةً للتطبيق كلّه، فواجهة التاجر وواجهة
/// الكول سنتر تحملان الرمز نفسه.
class Account {
  Account._();

  static const _storage = FlutterSecureStorage();

  /// اسمه في تطبيق التاجر قبل الدمج: التاجر الذي يحدّث التطبيق يبقى داخلاً
  static const _tokenKey = 'token';
  static const _sectionKey = 'section';

  static String? token;
  static Section? section;

  static bool get signedIn => token != null;

  /// نسخة العرض والاختبارات: لا خزنة جهاز، والحساب في الذاكرة وحدها
  static bool get _memory => AppConfig.demo || AppConfig.testing;

  /// عند فتح التطبيق. رمزٌ بلا قسمٍ محفوظ رمزُ تطبيق التاجر قبل الدمج: قسمه التاجر
  static Future<bool> restore() async {
    if (_memory) return false;
    token = await _storage.read(key: _tokenKey);
    if (token == null) {
      section = null;
      return false;
    }
    section = Section.of(await _storage.read(key: _sectionKey)) ?? Section.merchant;
    return true;
  }

  static Future<void> save(String newToken, Section newSection) async {
    token = newToken;
    section = newSection;
    if (_memory) return;
    await _storage.write(key: _tokenKey, value: newToken);
    await _storage.write(key: _sectionKey, value: newSection.name);
  }

  /// النظام صحّح القسم (رمزٌ قديم، أو تغيّر دوره): يُحفظ ليُفتح به في المرّة التالية
  static Future<void> adopt(Section newSection) async {
    if (section == newSection || token == null) return;
    section = newSection;
    if (_memory) return;
    await _storage.write(key: _sectionKey, value: newSection.name);
  }

  /// الخروج، أو رمزٌ أبطله النظام
  static Future<void> clear() async {
    token = null;
    section = null;
    if (_memory) return;
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _sectionKey);
  }
}
