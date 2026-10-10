import 'dart:convert';
import 'dart:typed_data';

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

  /// النموذج لا يتغيّر في الجلسة: يُحفظ أوّل مرّة، فيعرف الزرّ الأوسط فوراً هل للشركة وصولاتٌ مطبوعة
  CreateForm? _form;

  Future<CreateForm> createForm() async {
    if (AppConfig.demo) return Demo.form;
    return _form ??= CreateForm.fromJson(await _send(http.get(_uri('/merchant/shipments/form'), headers: _headers)));
  }

  /// وصلٌ ممسوح: الرقم مقبولاً، أو خطأٌ يقول لماذا (ليس من وصولاتك، استُعمل للشحنة…)
  Future<String> checkWaybill(String code) async {
    if (AppConfig.demo) return Demo.waybill(code);
    final uri = _uri('/merchant/waybills/check').replace(queryParameters: {'code': code});
    return (await _send(http.get(uri, headers: _headers)))['code'] as String;
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

  // ---------------------------------------------------------------- للمعالجة والمالية (docs/plan/54)

  Future<ProcessingPage> processing({int page = 1}) async {
    if (AppConfig.demo) return Demo.processing();
    final uri = _uri('/merchant/processing').replace(queryParameters: {'page': '$page'});
    return ProcessingPage.fromJson(await _send(http.get(uri, headers: _headers)));
  }

  /// قرار التاجر: redeliver · postpone (مع until) · return — ويعود نصّ النظام
  Future<String> process(int id, String action, {String? until, String? note}) async {
    if (AppConfig.demo) return Demo.process(action);
    final body = await _send(
      http.post(
        _uri('/merchant/processing/$id'),
        headers: _headers,
        body: jsonEncode({'action': action, 'until': ?until, if (note != null && note.isNotEmpty) 'note': note}),
      ),
    );
    return body['message'] as String? ?? 'سُجّل قرارك.';
  }

  Future<Finance> finance() async {
    if (AppConfig.demo) return Demo.finance;
    return Finance.fromJson(await _send(http.get(_uri('/merchant/finance'), headers: _headers)));
  }

  Future<PaymentRequest> requestPayment({required String method, String? details, String? note}) async {
    if (AppConfig.demo) return Demo.requestPayment(method);
    return PaymentRequest.fromJson(
      await _send(
        http.post(
          _uri('/merchant/finance/request'),
          headers: _headers,
          body: jsonEncode({
            'payout_method': method,
            if (details != null && details.isNotEmpty) 'payout_details': details,
            if (note != null && note.isNotEmpty) 'note': note,
          }),
        ),
      ),
    );
  }

  Future<String> confirmStatement(int id) async {
    if (AppConfig.demo) return Demo.confirmStatement(id);
    final body = await _send(http.post(_uri('/merchant/finance/statements/$id/confirm'), headers: _headers));
    return body['message'] as String? ?? 'أكّدت استلام الدفعة.';
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
    _form = null;
    await _storage.delete(key: _tokenKey);
  }

  // ---------------------------------------------------------------- الصورة والجرس (docs/plan/59)

  /// صورته أو شعاره — ويعود رابطها الجديد
  Future<String?> uploadLogo(Uint8List bytes, String filename) async {
    if (AppConfig.demo) return null;
    return (await _upload('/merchant/logo', 'logo', bytes, filename))['logo'] as String?;
  }

  Future<void> deleteLogo() async {
    if (AppConfig.demo) return;
    await _send(http.delete(_uri('/merchant/logo'), headers: _headers));
  }

  /// الجرس: يُفتح فيُقرأ ما فيه من إعلانات
  Future<List<AppNotice>> notifications() async {
    if (AppConfig.demo) return Demo.notices();
    final body = await _send(http.get(_uri('/merchant/notifications'), headers: _headers));
    return [for (final n in body['data'] as List) AppNotice.fromJson((n as Map).cast())];
  }

  // ---------------------------------------------------------------- الأدوات السريعة (docs/plan/56)

  /// ما يفتح ملفّات المحادثة (الصور) برمز التاجر
  Map<String, String> get fileHeaders => {if (_token != null) 'Authorization': 'Bearer $_token'};

  Future<PickupsPage> pickups() async {
    if (AppConfig.demo) return Demo.pickups();
    return PickupsPage.fromJson(await _send(http.get(_uri('/merchant/pickups'), headers: _headers)));
  }

  /// يعود نصّ النظام: «أُرسل طلب استلام برقم …»
  Future<String> requestPickup({required int count, String? day, String? address, String? phone, String? notes}) async {
    if (AppConfig.demo) return Demo.requestPickup(count, day, notes);
    final body = await _send(
      http.post(
        _uri('/merchant/pickups'),
        headers: _headers,
        body: jsonEncode({
          'expected_count': count,
          'scheduled_at': ?day,
          if (address?.isNotEmpty == true) 'address': address,
          if (phone?.isNotEmpty == true) 'contact_phone': phone,
          if (notes?.isNotEmpty == true) 'notes': notes,
        }),
      ),
    );
    return body['message'] as String? ?? 'أُرسل طلب الاستلام.';
  }

  Future<RequestsPage> requests() async {
    if (AppConfig.demo) return Demo.requests();
    return RequestsPage.fromJson(await _send(http.get(_uri('/merchant/requests'), headers: _headers)));
  }

  /// طلب كشف راجع — مع مندوب الاستلام أو من المخزن
  Future<String> requestReturns({required bool courier, String? note}) async {
    if (AppConfig.demo) return Demo.requestReturns(courier, note);
    final body = await _send(
      http.post(
        _uri('/merchant/requests'),
        headers: _headers,
        body: jsonEncode({'via_pickup_courier': courier, if (note?.isNotEmpty == true) 'note': note}),
      ),
    );
    return body['message'] as String? ?? 'أُرسل طلب كشف الراجع.';
  }

  Future<String> cancelRequest(int id) async {
    if (AppConfig.demo) return Demo.cancelRequest(id);
    final body = await _send(http.post(_uri('/merchant/requests/$id/cancel'), headers: _headers));
    return body['message'] as String? ?? 'أُلغي الطلب.';
  }

  /// «وصلتني»: رواجع الإيصال وصلت التاجر
  Future<String> confirmReturns(int id) async {
    if (AppConfig.demo) return Demo.confirmReturns(id);
    final body = await _send(http.post(_uri('/merchant/returns/$id/confirm'), headers: _headers));
    return body['message'] as String? ?? 'أكّدت الاستلام.';
  }

  Future<SupportPage> support() async {
    if (AppConfig.demo) return Demo.support();
    return SupportPage.fromJson(await _send(http.get(_uri('/merchant/support'), headers: _headers)));
  }

  /// محادثةٌ جديدة — ويعود رقمها لتُفتح
  Future<int> startConversation({
    required String subject,
    required String body,
    String? shipment,
    Uint8List? file,
    String? filename,
  }) async {
    if (AppConfig.demo) return Demo.startConversation(subject, body, shipment);
    final request = http.MultipartRequest('POST', _uri('/merchant/support'))
      ..headers.addAll({..._headers}..remove('Content-Type'))
      ..fields.addAll({
        'subject': subject,
        'body': body,
        if (shipment?.isNotEmpty == true) 'shipment_number': shipment!,
      });
    if (file != null) request.files.add(http.MultipartFile.fromBytes('attachment', file, filename: filename));
    final res = await _send(request.send().then(http.Response.fromStream), timeout: _reading);
    return res['id'] as int;
  }

  Future<Thread> thread(int id) async {
    if (AppConfig.demo) return Demo.thread(id);
    return Thread.fromJson(await _send(http.get(_uri('/merchant/support/$id'), headers: _headers)));
  }

  Future<void> reply(int id, String body, {Uint8List? file, String? filename}) async {
    if (AppConfig.demo) return Demo.reply(id, body, image: file != null);
    final request = http.MultipartRequest('POST', _uri('/merchant/support/$id/reply'))
      ..headers.addAll({..._headers}..remove('Content-Type'))
      ..fields.addAll({if (body.isNotEmpty) 'body': body});
    if (file != null) request.files.add(http.MultipartFile.fromBytes('attachment', file, filename: filename));
    await _send(request.send().then(http.Response.fromStream), timeout: _reading);
  }

  // ---------------------------------------------------------------- الوصولات والرفع من ملف (docs/plan/57)

  Future<WaybillsPage> waybills() async {
    if (AppConfig.demo) return Demo.waybills();
    return WaybillsPage.fromJson(await _send(http.get(_uri('/merchant/waybills'), headers: _headers)));
  }

  Future<IssuedBook> issueWaybills(int size, String printSize) async {
    if (AppConfig.demo) return Demo.issueWaybills(size, printSize);
    return IssuedBook.fromJson(
      await _send(
        http.post(
          _uri('/merchant/waybills'),
          headers: _headers,
          body: jsonEncode({'size': size, 'print_size': printSize}),
        ),
      ),
    );
  }

  /// الأعمدة ورابط القالب (موقَّعٌ لساعة)
  Future<({List<({String label, bool required})> columns, String template})> importInfo() async {
    if (AppConfig.demo) return Demo.importInfo;
    final j = await _send(http.get(_uri('/merchant/import'), headers: _headers));
    return (
      columns: [
        for (final c in j['columns'] as List)
          (label: (c as Map)['label'] as String, required: c['required'] as bool? ?? false),
      ],
      template: j['template'] as String,
    );
  }

  Future<ImportPreview> previewImport(Uint8List bytes, String filename) async {
    if (AppConfig.demo) return Demo.previewImport();
    return ImportPreview.fromJson(await _upload('/merchant/import', 'file', bytes, filename));
  }

  /// يُنشئ الشحنات — ويعود نصّ النظام بعددها
  Future<String> confirmImport(String path, {bool skipErrors = false}) async {
    if (AppConfig.demo) return Demo.confirmImport(skipErrors);
    final body = await _send(
      http.post(
        _uri('/merchant/import/confirm'),
        headers: _headers,
        body: jsonEncode({'path': path, 'skip_errors': skipErrors}),
      ),
      timeout: _reading,
    );
    return body['message'] as String? ?? 'أُنشئت شحناتك.';
  }

  // ---------------------------------------------------------------- بالذكاء الاصطناعي وبالصوت (docs/plan/55)

  /// قراءة الصورة والتسجيل قد تطول: الذكاء الاصطناعي يقرأ لقطة الشاشة كلّها
  static const _reading = Duration(seconds: 90);

  /// رسالة الزبون ملصوقةً، أو ما قاله التاجر بمايك لوحة المفاتيح (spoken)
  Future<OrderReading> readText(String text, {bool spoken = false}) async {
    if (AppConfig.demo) return Demo.read(spoken: spoken);
    return OrderReading.fromJson(
      await _send(
        http.post(
          _uri('/merchant/shipments/read'),
          headers: _headers,
          body: jsonEncode({'text': text, if (spoken) 'spoken': true}),
        ),
        timeout: _reading,
      ),
    );
  }

  /// لقطة شاشةٍ لمحادثة الزبون — تُقرأ على الخادم وتُرمى
  Future<OrderReading> readImage(Uint8List bytes, String filename) async {
    if (AppConfig.demo) return Demo.read();
    return OrderReading.fromJson(await _upload('/merchant/shipments/read', 'image', bytes, filename));
  }

  /// تسجيلٌ حتى «أوقف»: يصير نصّاً على الخادم ثم يُقرأ منه الطلب
  Future<OrderReading> listen(Uint8List bytes, String filename) async {
    if (AppConfig.demo) return Demo.read(heard: true);
    return OrderReading.fromJson(await _upload('/merchant/shipments/listen', 'audio', bytes, filename));
  }

  Future<Map<String, dynamic>> _upload(String path, String field, Uint8List bytes, String filename) {
    final request = http.MultipartRequest('POST', _uri(path))
      ..headers.addAll({..._headers}..remove('Content-Type'))
      ..files.add(http.MultipartFile.fromBytes(field, bytes, filename: filename));
    return _send(request.send().then(http.Response.fromStream), timeout: _reading);
  }

  Future<Map<String, dynamic>> _send(
    Future<http.Response> request, {
    Duration timeout = const Duration(seconds: 20),
  }) async {
    final http.Response res;
    try {
      res = await request.timeout(timeout);
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
