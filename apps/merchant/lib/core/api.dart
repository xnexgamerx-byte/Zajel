import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import 'config.dart';
import 'demo.dart';
import 'models.dart';

/// خطأٌ يُعرض للتاجر كما كتبه النظام («اسم المستخدم أو كلمة المرور غير صحيحة.»)
class ApiError implements Exception {
  ApiError(this.message, {this.unauthorised = false, this.fields = const {}});

  final String message;

  /// أخطاء النموذج بحقولها: {recipient_phone: «رقم الهاتف يجب أن يبدأ بـ 07…»}
  final Map<String, String> fields;

  /// انتهى الدخول (رمزٌ أُبطل أو حسابٌ أُوقف): يعود إلى شاشة الدخول
  final bool unauthorised;

  @override
  String toString() => message;
}

/// واجهة النظام (routes/api.php): الشركة من عنوانها، والدخول برمزٍ محفوظٍ في خزنة الجهاز.
class Api {
  Api._();

  static final instance = Api._();

  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'token';

  String? _token;

  Uri _uri(String path) => Uri.parse('${AppConfig.apiUrl}/api/v1$path');

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    if (_token != null) 'Authorization': 'Bearer $_token',
  };

  Future<bool> restore() async {
    if (AppConfig.demo) return false;
    _token = await _storage.read(key: _tokenKey);
    return _token != null;
  }

  Future<Session> login(String username, String password) async {
    if (AppConfig.demo) return Demo.session;
    final body = await _send(
      http.post(
        _uri('/login'),
        headers: _headers,
        body: jsonEncode({'username': username, 'password': password, 'app': 'merchant', 'device': 'phone'}),
      ),
    );
    _token = body['token'] as String;
    await _storage.write(key: _tokenKey, value: _token);
    return Session.fromJson(body);
  }

  Future<Session> me() async {
    if (AppConfig.demo) return Demo.session;
    return Session.fromJson(await _send(http.get(_uri('/me'), headers: _headers)));
  }

  Future<HomeData> home() async {
    if (AppConfig.demo) return Demo.home;
    return HomeData.fromJson(await _send(http.get(_uri('/merchant/home'), headers: _headers)));
  }

  Future<ShipmentPage> shipments({String filter = 'all', String q = '', int page = 1}) async {
    if (AppConfig.demo) return Demo.shipments(filter, q, page);
    final uri = _uri('/merchant/shipments')
        .replace(queryParameters: {'filter': filter, if (q.isNotEmpty) 'q': q, 'page': '$page'});
    return ShipmentPage.fromJson(await _send(http.get(uri, headers: _headers)));
  }

  Future<ShipmentDetail> shipment(int id) async {
    if (AppConfig.demo) return Demo.shipment(id);
    return ShipmentDetail.fromJson(await _send(http.get(_uri('/merchant/shipments/$id'), headers: _headers)));
  }

  // ---------------------------------------------------------------- طلب جديد (docs/plan/52)

  Future<CreateForm> createForm() async {
    if (AppConfig.demo) return Demo.form;
    return CreateForm.fromJson(await _send(http.get(_uri('/merchant/shipments/form'), headers: _headers)));
  }

  Future<List<Choice>> areas(String governorate) async {
    if (AppConfig.demo) return Demo.areas(governorate);
    final uri = _uri('/merchant/areas').replace(queryParameters: {'governorate': governorate});
    final body = await _send(http.get(uri, headers: _headers));
    return [for (final a in body['areas'] as List) Choice.fromJson((a as Map).cast())];
  }

  Future<Quote> quote({required String governorate, String? area, int cod = 0, String size = 'normal'}) async {
    if (AppConfig.demo) return Demo.quote(cod);
    final uri = _uri(
      '/merchant/shipments/quote',
    ).replace(queryParameters: {'governorate_id': governorate, 'city_id': ?area, 'cod_amount': '$cod', 'size': size});
    return Quote.fromJson(await _send(http.get(uri, headers: _headers)));
  }

  Future<Created> createShipment(Map<String, dynamic> data) async {
    if (AppConfig.demo) return Demo.create(data);
    return Created.fromJson(
      await _send(http.post(_uri('/merchant/shipments'), headers: _headers, body: jsonEncode(data))),
    );
  }

  /// صورة إعلانٍ محميّة برمز الدخول
  Map<String, String> get imageHeaders => {if (_token != null) 'Authorization': 'Bearer $_token'};

  Future<void> logout() async {
    if (_token != null && !AppConfig.demo) {
      try {
        await http.post(_uri('/logout'), headers: _headers);
      } catch (_) {
        // بلا شبكة: يُنسى الرمز هنا، ويبقى ساري المدة على الخادم حتى يُبطله الخروج التالي
      }
    }
    _token = null;
    await _storage.delete(key: _tokenKey);
  }

  Future<Map<String, dynamic>> _send(Future<http.Response> request) async {
    final http.Response res;
    try {
      res = await request.timeout(const Duration(seconds: 20));
    } catch (_) {
      throw ApiError('تعذّر الاتصال بالنظام. تأكّد من الإنترنت وحاول مجدداً.');
    }

    final body = res.body.isEmpty ? <String, dynamic>{} : jsonDecode(res.body) as Map<String, dynamic>;

    if (res.statusCode == 401) {
      _token = null;
      await _storage.delete(key: _tokenKey);
      throw ApiError(body['message'] as String? ?? 'انتهى دخولك. سجّل الدخول من جديد.', unauthorised: true);
    }

    if (res.statusCode >= 400) {
      final errors = body['errors'] as Map<String, dynamic>?;
      final first = errors?.values.whereType<List>().expand((e) => e).firstOrNull;
      throw ApiError(
        (first ?? body['message'] ?? 'حدث خطأ. حاول مجدداً.').toString(),
        fields: {
          for (final e in (errors ?? const {}).entries)
            if (e.value is List && (e.value as List).isNotEmpty) e.key: '${(e.value as List).first}',
        },
      );
    }

    return body;
  }
}
