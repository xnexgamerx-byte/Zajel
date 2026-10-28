import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import 'config.dart';
import 'models.dart';

/// هوية الشركة وشعارها محفوظان في الهاتف (docs/plan/88): شاشة الدخول ترسم الشعار من أوّل لحظة، لا حرف الشركة
/// ثم الشعار بعد ثانية. يُقرأ قبل أوّل رسم، ويُحدَّث من النظام في الخلفية مع كلّ فتح.
class BrandCache {
  BrandCache._();

  static const _storage = FlutterSecureStorage();
  static const _brandKey = 'company_brand';
  static const _logoKey = 'company_logo';

  static CompanyBrand? brand;
  static Uint8List? logo;

  /// قبل runApp: ما حُفظ في الفتح السابق
  static Future<void> load() async {
    if (AppConfig.demo) return;
    try {
      final saved = await _storage.read(key: _brandKey);
      if (saved != null) brand = CompanyBrand.fromJson((jsonDecode(saved) as Map).cast<String, dynamic>());
      final bytes = await _storage.read(key: _logoKey);
      if (bytes != null && brand?.logo != null) logo = base64Decode(bytes);
    } catch (_) {
      // حفظٌ تالف: يُعاد من النظام
      brand = null;
      logo = null;
    }
  }

  /// ما وصل من النظام: يُحفظ، والشعار يُنزَّل حين يتغيّر رابطه (فيه بصمته ?v=…)
  static Future<void> save(CompanyBrand next) async {
    final same = brand?.logo == next.logo && logo != null;
    brand = next;
    try {
      await _storage.write(key: _brandKey, value: jsonEncode(next.toJson()));
      if (next.logo == null) {
        logo = null;
        await _storage.delete(key: _logoKey);
        return;
      }
      if (same) return;
      final res = await http.get(Uri.parse(next.logo!)).timeout(const Duration(seconds: 20));
      if (res.statusCode == 200 && res.bodyBytes.isNotEmpty) {
        logo = res.bodyBytes;
        await _storage.write(key: _logoKey, value: base64Encode(res.bodyBytes));
      }
    } catch (_) {
      // بلا شبكة: يبقى ما حُفظ
    }
  }
}
