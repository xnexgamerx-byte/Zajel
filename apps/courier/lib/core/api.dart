import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import 'config.dart';
import 'demo.dart';
import 'models.dart';

/// خطأٌ يُعرض للمندوب كما كتبه النظام («اختر السبب»)
class ApiError implements Exception {
  ApiError(this.message, {this.unauthorised = false, this.notFound = false, this.fields = const {}});

  final String message;

  /// أخطاء النموذج بحقولها: {failure_reason_id: «اختر السبب…»}
  final Map<String, String> fields;

  /// انتهى الدخول (رمزٌ أُبطل أو حسابٌ أُوقف): يعود إلى شاشة الدخول
  final bool unauthorised;

  /// شحنةٌ ليست بيده (أو لا وجود لها — الجواب واحد)
  final bool notFound;

  @override
  String toString() => message;
}

/// واجهة المندوب في النظام (routes/api.php ← /api/v1/courier، docs/plan/66): الشركة من عنوانها،
/// والدخول برمزٍ محفوظٍ في خزنة الجهاز المشفّرة. القواعد كلّها في النظام؛ التطبيق يعرض ويسأل.
class Api {
  Api._();

  static final instance = Api._();

  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'courier_token';

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

  /// هوية الشركة لشاشة الدخول (docs/plan/88): بلا شبكةٍ أو في نسخة العرض تبقى الشاشة باسمها من الإعداد
  Future<CompanyBrand?> company() async {
    if (AppConfig.demo || AppConfig.apiUrl.isEmpty) return null;
    try {
      final body = await _send(http.get(_uri('/company'), headers: _headers));
      return CompanyBrand.fromJson(((body['company'] as Map?) ?? const {}).cast<String, dynamic>());
    } catch (_) {
      return null;
    }
  }

  Future<Session> login(String username, String password) async {
    if (AppConfig.demo) return Demo.session;
    final body = await _send(
      http.post(
        _uri('/login'),
        headers: _headers,
        body: jsonEncode({'username': username, 'password': password, 'app': 'courier', 'device': 'phone'}),
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

  Future<void> logout() async {
    if (_token != null && !AppConfig.demo) {
      try {
        await http.post(_uri('/logout'), headers: _headers);
      } catch (_) {
        // بلا شبكة: يُنسى الرمز هنا، ويبقى ساري المدة على الخادم حتى يُبطله الخروج التالي
      }
    }
    _token = null;
    _reasons = null;
    await _storage.delete(key: _tokenKey);
  }

  // ---------------------------------------------------------------- الشاشات

  Future<HomeData> home() async {
    if (AppConfig.demo) return Demo.home();
    return HomeData.fromJson(await _send(http.get(_uri('/courier/home'), headers: _headers)));
  }

  /// «مهامي»: delivery أو redelivery أو postponed أو returns، ويُبحث برقم الوصل أو الاسم أو الهاتف
  Future<TaskList> tasks({String kind = 'delivery', String q = ''}) async {
    if (AppConfig.demo) return Demo.tasks(kind, q);
    final uri = _uri('/courier/tasks').replace(queryParameters: {'kind': kind, if (q.isNotEmpty) 'q': q});
    return TaskList.fromJson(await _send(http.get(uri, headers: _headers)));
  }

  Future<ShipmentDetail> shipment(int id) async {
    if (AppConfig.demo) return Demo.shipment(id);
    final body = await _send(http.get(_uri('/courier/shipments/$id'), headers: _headers));
    return ShipmentDetail.fromJson((body['data'] as Map).cast());
  }

  /// الماسح: رقم الوصل أو باركوده أو رابط QR — ويعود رقم الشحنة إن كانت بيده
  Future<int> find(String code) async {
    if (AppConfig.demo) return Demo.find(code);
    final uri = _uri('/courier/shipments/find').replace(queryParameters: {'q': code});
    final body = await _send(http.get(uri, headers: _headers));
    return (body['data'] as Map)['id'] as int;
  }

  Future<CashPage> cash() async {
    if (AppConfig.demo) return Demo.cash();
    return CashPage.fromJson(await _send(http.get(_uri('/courier/cash'), headers: _headers)));
  }

  // ---------------------------------------------------------------- عند الباب

  /// الأسباب لا تتغيّر في الجلسة: تُحفظ أوّل مرّة
  List<Reason>? _reasons;

  Future<List<Reason>> reasons() async {
    if (AppConfig.demo) return Demo.reasons;
    if (_reasons != null) return _reasons!;
    final body = await _send(http.get(_uri('/courier/reasons'), headers: _headers));
    return _reasons = [for (final r in body['data'] as List) Reason.fromJson((r as Map).cast())];
  }

  /// delivered · partially_delivered · failed_attempt · postponed — والمبلغ لا يُرسَل أبداً:
  /// الواصل بمبلغ الشحنة، والجزئي بما اعتمده الكول سنتر (docs/plan/66)
  Future<Outcome> act(
    int id,
    String action, {
    int? reasonId,
    String? note,
    String? scan,
    DateTime? until,
    double? lat,
    double? lng,
  }) async {
    if (AppConfig.demo) {
      return Demo.act(id, action, reasonId: reasonId, note: note, scan: scan, until: until);
    }
    final body = await _send(
      http.post(
        _uri('/courier/shipments/$id/act'),
        headers: _headers,
        body: jsonEncode({
          'action': action,
          'failure_reason_id': ?reasonId,
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
          if (scan != null && scan.isNotEmpty) 'scan': scan,
          if (until != null) 'until': until.toIso8601String(),
          'lat': ?lat,
          'lng': ?lng,
        }),
      ),
    );
    return Outcome(body['message'] as String? ?? 'سُجِّلت.', ShipmentDetail.fromJson((body['data'] as Map).cast()));
  }

  /// «أوصلها الآن» (docs/plan/75): المؤجّلة بيده تخرج للتوصيل في موعدها
  Future<Outcome> resume(int id) async {
    if (AppConfig.demo) return Demo.resume(id);
    final body = await _send(http.post(_uri('/courier/shipments/$id/resume'), headers: _headers));
    return Outcome(body['message'] as String? ?? 'معك للتوصيل.', ShipmentDetail.fromJson((body['data'] as Map).cast()));
  }

  /// «الزبون دفع غير المبلغ» — price (سُلّمت بسعرٍ آخر) أو partial (أخذ جزءاً وأرجع الباقي). وfinalize
  /// (docs/plan/80): سلّمها وينتظر الموظّف، فالموافقة تسجّلها — والجزئيّ بعدد القطع الراجعة
  Future<(String, Ticket)> openTicket(
    int id, {
    required String kind,
    required int amount,
    required String reason,
    bool finalize = false,
    int? returnedPieces,
  }) async {
    if (AppConfig.demo) return Demo.openTicket(id, kind, amount, reason, returnedPieces: returnedPieces);
    final body = await _send(
      http.post(
        _uri('/courier/shipments/$id/ticket'),
        headers: _headers,
        body: jsonEncode({
          'kind': kind,
          'requested_amount': amount,
          'reason': reason,
          'finalize': finalize,
          'returned_pieces': ?returnedPieces,
        }),
      ),
    );
    return (body['message'] as String? ?? 'أُرسل طلبك إلى الكول سنتر.', Ticket.fromJson((body['data'] as Map).cast()));
  }

  // ------------------------------------------- «اليوم» والاستلام والحصص والجرس والمكتب (docs/plan/71)

  Future<TodayPage> today() async {
    if (AppConfig.demo) return Demo.today();
    return TodayPage.fromJson(await _send(http.get(_uri('/courier/today'), headers: _headers)));
  }

  Future<PickupsPage> pickups() async {
    if (AppConfig.demo) return Demo.pickups();
    return PickupsPage.fromJson(await _send(http.get(_uri('/courier/pickups'), headers: _headers)));
  }

  /// ما مسحه عند التاجر: شحنةٌ من شحناته تنتظر الاستلام، أو خطأٌ يقول لماذا (docs/plan/72)
  Future<ScannedParcel> pickupScan(int pickupId, String code) async {
    if (AppConfig.demo) return Demo.pickupScan(pickupId, code);
    final uri = _uri('/courier/pickups/$pickupId/scan').replace(queryParameters: {'code': code});
    final body = await _send(http.get(uri, headers: _headers));
    return ScannedParcel.fromJson((body['data'] as Map).cast());
  }

  /// «استلمت الطرود»: كلّ شحنات التاجر المعلّقة دفعةً واحدة — أو ما مُسح منها وحده
  Future<String> completePickup(int id, int count, {String? note, List<int>? shipments}) async {
    if (AppConfig.demo) return Demo.completePickup(id, shipments?.length ?? count);
    final body = await _send(
      http.post(
        _uri('/courier/pickups/$id/complete'),
        headers: _headers,
        body: jsonEncode({
          if (shipments == null) 'actual_count': count else 'shipments': shipments,
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        }),
      ),
    );
    return body['message'] as String? ?? 'سُجّل الاستلام.';
  }

  Future<SharesPage> shares() async {
    if (AppConfig.demo) return Demo.shares();
    return SharesPage.fromJson(await _send(http.get(_uri('/courier/shares'), headers: _headers)));
  }

  Future<String> objectShare(int id, int count, String reason) async {
    if (AppConfig.demo) return Demo.objectShare(id, count, reason);
    final body = await _send(
      http.post(
        _uri('/courier/shares/$id/object'),
        headers: _headers,
        body: jsonEncode({'claimed_count': count, 'reason': reason.trim()}),
      ),
    );
    return body['message'] as String? ?? 'سُجّل اعتراضك.';
  }

  Future<String> confirmPayout(int id) async {
    if (AppConfig.demo) return Demo.confirmPayout(id);
    final body = await _send(http.post(_uri('/courier/payouts/$id/confirm'), headers: _headers));
    return body['message'] as String? ?? 'أكّدت استلام الدفعة.';
  }

  Future<List<Notice>> notifications() async {
    if (AppConfig.demo) return Demo.notifications();
    final body = await _send(http.get(_uri('/courier/notifications'), headers: _headers));
    return [for (final n in body['data'] as List) Notice.fromJson((n as Map).cast())];
  }

  Future<List<ChatMessage>> chat() async {
    if (AppConfig.demo) return Demo.chat();
    final body = await _send(http.get(_uri('/courier/chat'), headers: _headers));
    return [for (final m in body['data'] as List) ChatMessage.fromJson((m as Map).cast())];
  }

  Future<void> sendChat(String text, {int? shipmentId}) async {
    if (AppConfig.demo) return Demo.sendChat(text, shipmentId: shipmentId);
    await _send(
      http.post(
        _uri('/courier/chat'),
        headers: _headers,
        body: jsonEncode({'body': text.trim(), 'shipment_id': ?shipmentId}),
      ),
    );
  }

  /// موقع الهاتف والتطبيق مفتوح — يُرمى بصمت إن لم يصل
  Future<void> location(double lat, double lng, double accuracy) async {
    if (AppConfig.demo) return;
    await _send(
      http.post(
        _uri('/courier/location'),
        headers: _headers,
        body: jsonEncode({'lat': lat, 'lng': lng, 'accuracy': accuracy}),
      ),
    );
  }

  Future<Map<String, dynamic>> _send(
    Future<http.Response> request, {
    Duration timeout = const Duration(seconds: 20),
  }) async {
    final http.Response res;
    try {
      res = await request.timeout(timeout);
    } catch (_) {
      // بلا شبكة لا يُسجَّل شيء (docs/plan/66 §القرار ٢): خطأٌ واضح، والمندوب يعيد
      throw ApiError('تعذّر الاتصال بالنظام. تأكّد من الإنترنت وحاول مجدداً — لم يُسجَّل شيء.');
    }

    Map<String, dynamic> body;
    try {
      body = res.body.isEmpty ? <String, dynamic>{} : jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {
      body = {};
    }

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
        notFound: res.statusCode == 404,
        fields: {
          for (final e in (errors ?? const {}).entries)
            if (e.value is List && (e.value as List).isNotEmpty) e.key: '${(e.value as List).first}',
        },
      );
    }

    return body;
  }
}
