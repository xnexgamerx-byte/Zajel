import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';

/// الردود الجاهزة في المحادثات (docs/plan/81): جملٌ تُرسل بضغطة للتاجر أو المندوب — «تم التبليغ»،
/// «الزبون بالانتظار» — تضيفها الموظّفة وتحذفها من حسابها، وتُحفظ على هاتفها.
class QuickReplies {
  QuickReplies._();

  static const _storage = FlutterSecureStorage();
  static const _key = 'callcenter_quick_replies';

  static const suggested = [
    'تم التبليغ',
    'الزبون بالانتظار',
    'تواصلنا مع الزبون',
    'يُسلَّم اليوم إن شاء الله',
    'الزبون لا يرد، نعيد المحاولة',
    'شكراً لك',
  ];

  static final items = ValueNotifier<List<String>>(List.of(suggested));

  static bool get _memory => AppConfig.demo || AppConfig.testing;

  static Future<void> load() async {
    if (_memory) return;
    try {
      final saved = await _storage.read(key: _key);
      if (saved != null) items.value = [for (final r in jsonDecode(saved) as List) '$r'];
    } catch (_) {
      // خزنةٌ لا تُقرأ: تبقى المقترحة
    }
  }

  static Future<void> save(List<String> replies) async {
    items.value = [
      for (final r in replies)
        if (r.trim().isNotEmpty) r.trim(),
    ];
    if (!_memory) await _storage.write(key: _key, value: jsonEncode(items.value));
  }
}
