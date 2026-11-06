import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';
import 'models.dart';

/// ما فتحه التاجر من شحناته (docs/plan/123): اللون الشفّاف خلف الشحنة في القوائم علامة «لم تُفتح». يذهب حين
/// يفتحها أو يقرّر فيها، ويعود إن تغيّرت حالتها بعد آخر مرّةٍ رآها — فاللون دائماً «فيها ما لم تره».
///
/// على الهاتف وحده (خزنة الجهاز، مع رمز الدخول)، ويُمسح عند الخروج: من يدخل بعده يبدأ من جديد. وأوّل مرّة: ما
/// تغيّر قبل يومين وأكثر محسوبٌ مفتوحاً، فلا تتلوّن القائمة كلّها لتاجرٍ قديم.
class Seen {
  Seen._();

  static const _storage = FlutterSecureStorage();
  static const _key = 'seen_shipments';

  /// يكفي هذا العدد من آخر ما فُتح: ما قبله قديمٌ تغيّر منذ زمن
  static const _keep = 1500;

  /// يُرسم من جديد كلّ صفٍّ يسمعه حين تُفتح شحنة
  static final changed = ValueNotifier<int>(0);

  /// رقم الشحنة ← متى رآها (ثوانٍ)
  static final _at = <int, int>{};

  /// ما تغيّر قبل هذا (ثوانٍ) محسوبٌ مفتوحاً
  static int _since = 0;
  static bool _loaded = false;

  static bool get _memory => AppConfig.demo || AppConfig.testing;

  static int _now() => DateTime.now().millisecondsSinceEpoch ~/ 1000;

  /// عند فتح قسم التاجر
  static Future<void> load() async {
    if (_loaded) return;
    _loaded = true;
    _since = _now() - 2 * 24 * 3600;
    if (_memory) return;
    try {
      final raw = await _storage.read(key: _key);
      if (raw == null) {
        await _save();
        return;
      }
      final j = (jsonDecode(raw) as Map).cast<String, dynamic>();
      _since = j['since'] as int? ?? _since;
      _at
        ..clear()
        ..addAll({for (final e in ((j['at'] as Map?) ?? const {}).entries) int.parse('${e.key}'): e.value as int});
    } catch (_) {
      // خزنةٌ تالفة أو غير متاحة: يبدأ من الآن، والقوائم تُرسم بلا خطأ
    }
    changed.value++;
  }

  /// فيها ما لم يره: تغيّرت بعد آخر مرّةٍ فتحها، ولم تكن قديمةً حين بدأ
  static bool fresh(RecentShipment row) {
    final at = row.at;
    if (row.id == 0 || at == null) return false;
    final t = at.millisecondsSinceEpoch ~/ 1000;
    if (t <= _since) return false;
    final seen = _at[row.id];
    return seen == null || seen < t;
  }

  /// فتحها أو قرّر فيها
  static void open(int id) {
    if (id == 0) return;
    _at[id] = _now();
    if (_at.length > _keep) {
      final oldest = (_at.entries.toList()..sort((a, b) => a.value.compareTo(b.value))).take(_at.length - _keep);
      for (final e in oldest.toList()) {
        _at.remove(e.key);
      }
    }
    changed.value++;
    _save();
  }

  static Future<void> _save() async {
    if (_memory) return;
    try {
      await _storage.write(
        key: _key,
        value: jsonEncode({
          'since': _since,
          'at': {for (final e in _at.entries) '${e.key}': e.value},
        }),
      );
    } catch (_) {
      // تبقى في الذاكرة لهذه المرّة
    }
  }

  /// الخروج: لا يرى من يدخل بعده ما فتحه هو
  static Future<void> clear() async {
    _at.clear();
    _loaded = false;
    changed.value++;
    if (_memory) return;
    try {
      await _storage.delete(key: _key);
    } catch (_) {}
  }
}
