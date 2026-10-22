import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import 'config.dart';
import 'demo.dart';
import 'models.dart';

/// خطأٌ يُعرض للموظّفة كما كتبه النظام («تعالجها سارة منذ 3 دقيقة — لا تُعالَج مرّتين.»)
class ApiError implements Exception {
  ApiError(this.message, {this.unauthorised = false, this.notFound = false, this.fields = const {}});

  final String message;

  /// أخطاء النموذج بحقولها: {claim: «تعالجها سارة…»}
  final Map<String, String> fields;

  /// انتهى الدخول (رمزٌ أُبطل أو حسابٌ أُوقف): يعود إلى شاشة الدخول
  final bool unauthorised;

  /// طلبٌ حُسم أو ليس في محافظاتها
  final bool notFound;

  /// حجزته زميلتها (docs/plan/73)
  bool get claimed => fields.containsKey('claim');

  @override
  String toString() => message;
}

/// واجهة الكول سنتر في النظام (routes/api.php ← /api/v1/callcenter، docs/plan/73): الشركة من عنوانها،
/// والدخول برمزٍ محفوظٍ في خزنة الجهاز المشفّرة. القواعد كلّها في النظام — ومنها «من فتحت الطلب
/// تعالجه وحدها» —؛ التطبيق يعرض ويسأل.
class Api {
  Api._();

  static final instance = Api._();

  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'callcenter_token';

  String? _token;

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('${AppConfig.apiUrl}/api/v1$path').replace(queryParameters: query);

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
        body: jsonEncode({'username': username, 'password': password, 'app': 'callcenter', 'device': 'phone'}),
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
    await _storage.delete(key: _tokenKey);
  }

  // ---------------------------------------------------------------- الرئيسية

  Future<HomeData> home() async {
    if (AppConfig.demo) return Demo.home();
    return HomeData.fromJson(await _get('/callcenter/home'));
  }

  // ---------------------------------------------------------------- تذاكر المبلغ

  Future<List<TicketItem>> tickets() async {
    if (AppConfig.demo) return Demo.tickets();
    return [
      for (final t in (await _get('/callcenter/tickets'))['data'] as List) TicketItem.fromJson((t as Map).cast()),
    ];
  }

  Future<TicketItem> ticket(int id) async {
    if (AppConfig.demo) return Demo.ticket(id);
    return TicketItem.fromJson(((await _get('/callcenter/tickets/$id'))['data'] as Map).cast());
  }

  /// «أعالجها»: تصير لها — أو ApiError.claimed إن سبقتها زميلتها
  Future<TicketItem> claimTicket(int id) async {
    if (AppConfig.demo) return Demo.claimTicket(id);
    return TicketItem.fromJson(((await _post('/callcenter/tickets/$id/claim'))['data'] as Map).cast());
  }

  Future<String> releaseTicket(int id) async {
    if (AppConfig.demo) return Demo.release('ticket', id);
    return _message(await _post('/callcenter/tickets/$id/release'));
  }

  Future<String> approveTicket(int id, int amount, {String? reply}) async {
    if (AppConfig.demo) return Demo.approveTicket(id, amount);
    return _message(
      await _post('/callcenter/tickets/$id/approve', {
        'approved_amount': amount,
        if (reply != null && reply.trim().isNotEmpty) 'reply': reply.trim(),
      }),
    );
  }

  Future<String> rejectTicket(int id, String reply) async {
    if (AppConfig.demo) return Demo.rejectTicket(id, reply);
    return _message(await _post('/callcenter/tickets/$id/reject', {'reply': reply.trim()}));
  }

  // ---------------------------------------------------------------- المعالجة

  Future<List<CaseItem>> cases({String q = ''}) async {
    if (AppConfig.demo) return Demo.cases(q);
    final body = await _get('/callcenter/processing', q.isEmpty ? null : {'q': q});
    return [for (final c in body['data'] as List) CaseItem.fromJson((c as Map).cast())];
  }

  Future<CaseItem> caseDetail(int id) async {
    if (AppConfig.demo) return Demo.caseDetail(id);
    return CaseItem.fromJson(((await _get('/callcenter/processing/$id'))['data'] as Map).cast());
  }

  Future<CaseItem> claimCase(int id, {String? note}) async {
    if (AppConfig.demo) return Demo.claimCase(id, note: note);
    return CaseItem.fromJson(
      ((await _post('/callcenter/processing/$id/claim', {'note': ?note}))['data'] as Map).cast(),
    );
  }

  Future<String> releaseCase(int id) async {
    if (AppConfig.demo) return Demo.release('processing', id);
    return _message(await _post('/callcenter/processing/$id/release'));
  }

  /// redeliver · postpone (ومعه until) · return
  Future<String> decide(int id, String action, {DateTime? until, String? note}) async {
    if (AppConfig.demo) return Demo.decide(id, action, until: until);
    return _message(
      await _post('/callcenter/processing/$id/decide', {
        'action': action,
        if (until != null) 'until': until.toIso8601String().substring(0, 10),
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
      }),
    );
  }

  /// to: merchant (التاجر) أو courier (مندوبها)
  Future<void> caseMessage(int id, String to, String body) async {
    if (AppConfig.demo) return Demo.caseMessage(id, to, body);
    await _post('/callcenter/processing/$id/$to', {'body': body.trim()});
  }

  // ---------------------------------------------------------------- المحادثات

  Future<List<ConversationItem>> conversations({bool waiting = true}) async {
    if (AppConfig.demo) return Demo.conversations(waiting: waiting);
    final body = await _get('/callcenter/conversations', waiting ? null : {'show': 'open'});
    return [for (final c in body['data'] as List) ConversationItem.fromJson((c as Map).cast())];
  }

  /// تُفتح فتصير لها إن لم تكن لزميلتها
  Future<ConversationItem> conversation(int id) async {
    if (AppConfig.demo) return Demo.conversation(id);
    return ConversationItem.fromJson(((await _get('/callcenter/conversations/$id'))['data'] as Map).cast());
  }

  Future<void> reply(int id, String body) async {
    if (AppConfig.demo) return Demo.reply(id, body);
    await _post('/callcenter/conversations/$id/reply', {'body': body.trim()});
  }

  Future<String> closeConversation(int id) async {
    if (AppConfig.demo) return Demo.closeConversation(id);
    return _message(await _post('/callcenter/conversations/$id/close'));
  }

  Future<String> releaseConversation(int id) async {
    if (AppConfig.demo) return Demo.release('conversation', id);
    return _message(await _post('/callcenter/conversations/$id/release'));
  }

  // ---------------------------------------------------------------- البحث

  /// رقم الوصل أو هاتف الزبون أو اسمه، أو ما قرأه الماسح
  Future<List<FoundShipment>> search(String q) async {
    if (AppConfig.demo) return Demo.search(q);
    final body = await _get('/callcenter/shipments', {'q': q.trim()});
    return [for (final s in body['data'] as List) FoundShipment.fromJson((s as Map).cast())];
  }

  Future<ShipmentDetail> shipment(int id) async {
    if (AppConfig.demo) return Demo.shipment(id);
    return ShipmentDetail.fromJson(((await _get('/callcenter/shipments/$id'))['data'] as Map).cast());
  }

  // ---------------------------------------------------------------- الطلبات

  Future<Map<String, dynamic>> _get(String path, [Map<String, String>? query]) =>
      _send(http.get(_uri(path, query), headers: _headers));

  Future<Map<String, dynamic>> _post(String path, [Map<String, dynamic>? data]) =>
      _send(http.post(_uri(path), headers: _headers, body: jsonEncode(data ?? const {})));

  String _message(Map<String, dynamic> body) => body['message'] as String? ?? 'تمّ.';

  Future<Map<String, dynamic>> _send(
    Future<http.Response> request, {
    Duration timeout = const Duration(seconds: 20),
  }) async {
    final http.Response res;
    try {
      res = await request.timeout(timeout);
    } catch (_) {
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
