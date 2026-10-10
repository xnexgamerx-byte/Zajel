/// ما يعيده النظام لرئيسية التاجر: GET /api/v1/merchant/home
class HomeData {
  HomeData({
    required this.greeting,
    required this.name,
    required this.unread,
    required this.banners,
    required this.balance,
    required this.owed,
    this.available = 0,
    this.pending = 0,
    this.pendingReason,
    required this.stats,
    required this.processing,
    required this.canProcess,
    required this.attention,
    required this.recent,
  });

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final stats = (j['stats'] as Map).cast<String, dynamic>();
    return HomeData(
      greeting: j['greeting'] as String,
      name: j['name'] as String? ?? '',
      unread: j['unread'] as int? ?? 0,
      banners: [for (final b in j['banners'] as List) AdBanner.fromJson((b as Map).cast())],
      balance: j['balance']['total'] as int,
      owed: j['balance']['owed'] as bool,
      available: j['balance']['available'] as int? ?? 0,
      pending: j['balance']['pending'] as int? ?? 0,
      pendingReason: j['balance']['reason'] as String?,
      stats: Stats(
        total: stats['total'] as int,
        delivered: stats['delivered'] as int,
        inDelivery: stats['in_delivery'] as int,
        returns: stats['returns'] as int,
      ),
      processing: j['processing']['count'] as int,
      canProcess: j['processing']['allowed'] as bool,
      attention: j['attention']['count'] as int,
      recent: [for (final r in j['recent'] as List) RecentShipment.fromJson((r as Map).cast())],
    );
  }

  final String greeting;
  final String name;
  final int unread;
  final List<AdBanner> banners;
  final int balance;

  /// لك عند الشركة (true) أو عليك لها
  final bool owed;

  /// المتاح للسحب: ما حاسبت الشركة مندوبه عليه (docs/plan/49)
  final int available;

  /// قيد المطابقة: واصلٌ نقده ما زال مع المندوب، وسببه بكلام التاجر
  final int pending;
  final String? pendingReason;
  final Stats stats;
  final int processing;
  final bool canProcess;
  final int attention;
  final List<RecentShipment> recent;
}

class Stats {
  const Stats({required this.total, required this.delivered, required this.inDelivery, required this.returns});

  final int total;
  final int delivered;
  final int inDelivery;
  final int returns;
}

class AdBanner {
  AdBanner({required this.title, required this.image, this.link});

  factory AdBanner.fromJson(Map<String, dynamic> j) =>
      AdBanner(title: j['title'] as String? ?? '', image: j['image'] as String, link: j['link'] as String?);

  final String title;
  final String image;
  final String? link;
}

/// صفّ شحنةٍ في القوائم: آخر الشحنات في الرئيسية، و«شحناتي»
class RecentShipment {
  RecentShipment({
    this.id = 0,
    required this.number,
    required this.name,
    required this.area,
    required this.amount,
    required this.at,
    required this.status,
    required this.urgent,
    this.tone = 'slate',
  });

  factory RecentShipment.fromJson(Map<String, dynamic> j) => RecentShipment(
    id: j['id'] as int? ?? 0,
    number: j['number'] as String,
    name: j['name'] as String? ?? '',
    area: j['area'] as String? ?? '',
    amount: j['amount'] as int? ?? 0,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
    status: j['status'] as String,
    urgent: j['urgent'] as bool? ?? false,
    tone: j['tone'] as String? ?? 'slate',
  );

  final int id;
  final String number;
  final String name;
  final String area;
  final int amount;
  final DateTime? at;
  final String status;
  final bool urgent;

  /// لون الحالة كما في النظام: green · blue · amber · red · gray · slate
  final String tone;
}

/// شريحةٌ فوق القائمة: «مسلمة 86»
class ShipmentFilter {
  ShipmentFilter({required this.key, required this.label, required this.count});

  factory ShipmentFilter.fromJson(Map<String, dynamic> j) =>
      ShipmentFilter(key: j['key'] as String, label: j['label'] as String, count: j['count'] as int? ?? 0);

  final String key;
  final String label;
  final int count;
}

/// صفحةٌ من «شحناتي»: GET /api/v1/merchant/shipments
class ShipmentPage {
  ShipmentPage({
    required this.filters,
    required this.items,
    required this.page,
    required this.lastPage,
    required this.total,
  });

  factory ShipmentPage.fromJson(Map<String, dynamic> j) => ShipmentPage(
    filters: [for (final f in j['filters'] as List) ShipmentFilter.fromJson((f as Map).cast())],
    items: [for (final r in j['data'] as List) RecentShipment.fromJson((r as Map).cast())],
    page: j['meta']['page'] as int,
    lastPage: j['meta']['last_page'] as int,
    total: j['meta']['total'] as int,
  );

  final List<ShipmentFilter> filters;
  final List<RecentShipment> items;
  final int page;
  final int lastPage;
  final int total;
}

/// سطرٌ في مسار الشحنة
class TimelineStep {
  TimelineStep({required this.title, this.note, this.at});

  factory TimelineStep.fromJson(Map<String, dynamic> j) => TimelineStep(
    title: j['title'] as String,
    note: j['note'] as String?,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
  );

  final String title;
  final String? note;
  final DateTime? at;
}

/// الشحنة كاملةً: GET /api/v1/merchant/shipments/{id}
class ShipmentDetail {
  ShipmentDetail({
    required this.row,
    this.createdAt,
    this.reference,
    this.deliveryCode,
    this.failureReason,
    this.attempts = 0,
    required this.trackingUrl,
    required this.timeline,
    required this.recipient,
    required this.money,
  });

  factory ShipmentDetail.fromJson(Map<String, dynamic> j) {
    final failure = j['failure'] as Map?;
    return ShipmentDetail(
      row: RecentShipment.fromJson(j),
      createdAt: DateTime.tryParse(j['created_at'] as String? ?? '')?.toLocal(),
      reference: j['reference'] as String?,
      deliveryCode: j['delivery_code'] as String?,
      failureReason: failure?['reason'] as String?,
      attempts: failure?['attempts'] as int? ?? 0,
      trackingUrl: j['tracking_url'] as String? ?? '',
      timeline: [for (final t in j['timeline'] as List) TimelineStep.fromJson((t as Map).cast())],
      recipient: (j['recipient'] as Map).cast<String, dynamic>(),
      money: (j['money'] as Map).cast<String, dynamic>(),
    );
  }

  final RecentShipment row;
  final DateTime? createdAt;
  final String? reference;
  final String? deliveryCode;
  final String? failureReason;
  final int attempts;
  final String trackingUrl;
  final List<TimelineStep> timeline;

  /// name · phone · phone_alt · governorate · city · address · landmark · pieces · type · size · goods
  final Map<String, dynamic> recipient;

  /// cod · collected · delivery_fee · cod_fee · return_fee · due · owed
  final Map<String, dynamic> money;
}

/// الحساب بعد الدخول: من هو، ولأيّ شركة — اسمها ولونها وشعارها
class Session {
  Session({required this.name, required this.companyName, this.companyColor, this.companyLogo, this.companyInitial});

  factory Session.fromJson(Map<String, dynamic> j) => Session(
    name: j['user']['name'] as String,
    companyName: j['company']['name'] as String,
    companyColor: j['company']['color'] as String?,
    companyLogo: j['company']['logo'] as String?,
    companyInitial: j['company']['initial'] as String?,
  );

  final String name;
  final String companyName;
  final String? companyColor;
  final String? companyLogo;
  final String? companyInitial;
}

// ------------------------------------------------------------------ طلب جديد (docs/plan/52)

/// خيارٌ في النموذج: محافظة أو منطقة (id)، أو حجم ونوع (value)
class Choice {
  const Choice(this.key, this.label);

  factory Choice.fromJson(Map<String, dynamic> j) =>
      Choice('${j['id'] ?? j['value']}', j['name'] as String? ?? j['label'] as String);

  final String key;
  final String label;
}

/// ما يحتاجه نموذج «طلب جديد» مرّةً: GET /merchant/shipments/form
class CreateForm {
  CreateForm({
    required this.governorates,
    required this.home,
    required this.sizes,
    required this.types,
    required this.required,
    required this.waybills,
    required this.goods,
    this.reading = false,
    this.listening = false,
  });

  factory CreateForm.fromJson(Map<String, dynamic> j) => CreateForm(
    governorates: [for (final g in j['governorates'] as List) Choice.fromJson((g as Map).cast())],
    home: j['home'] == null ? null : '${j['home']}',
    sizes: [for (final s in j['sizes'] as List) Choice.fromJson((s as Map).cast())],
    types: [for (final t in j['types'] as List) Choice.fromJson((t as Map).cast())],
    required: [for (final r in j['required'] as List) '$r'],
    waybills: j['waybills'] as bool? ?? false,
    goods: j['goods'] as String? ?? 'ملابس',
    reading: j['reading'] as bool? ?? false,
    listening: j['listening'] as bool? ?? false,
  );

  /// «إنشاء بالذكاء الاصطناعي»: ميزة القراءة مفعّلةٌ للشركة
  final bool reading;

  /// «إنشاء بالتسجيل الصوتي» بتسجيلٍ يُسمع على الخادم — وإلّا فمايك لوحة المفاتيح
  final bool listening;

  final List<Choice> governorates;

  /// محافظة التاجر: يُفتح النموذج عليها
  final String? home;
  final List<Choice> sizes;
  final List<Choice> types;

  /// ما ألزمته الشركة من الحقول الاختيارية (recipient_name، landmark…)
  final List<String> required;
  final bool waybills;
  final String goods;
}

/// «يصلك»: أجرة التوصيل بتسعيرته وما يبقى له — GET /merchant/shipments/quote
class Quote {
  const Quote({required this.deliveryFee, required this.fees, required this.due});

  factory Quote.fromJson(Map<String, dynamic> j) =>
      Quote(deliveryFee: j['delivery_fee'] as int? ?? 0, fees: j['fees'] as int? ?? 0, due: j['due'] as int? ?? 0);

  final int deliveryFee;
  final int fees;
  final int due;
}

/// الشحنة المحفوظة للتوّ: صفّها في القوائم وما يصله منها
class Created {
  const Created({required this.row, required this.due, this.waybill});

  factory Created.fromJson(Map<String, dynamic> j) {
    final s = (j['shipment'] as Map).cast<String, dynamic>();
    return Created(row: RecentShipment.fromJson(s), due: s['due'] as int? ?? 0, waybill: s['waybill'] as String?);
  }

  final RecentShipment row;
  final int due;
  final String? waybill;
}

// ------------------------------------------------------------------ للمعالجة والمالية (docs/plan/54)

/// شحنةٌ لم تُسلَّم تنتظر قرار التاجر: صفّها، وسبب التعثّر، وكم انتظرت، وهاتف زبونه كاملاً
class ProcessingItem {
  ProcessingItem({required this.row, this.reason, this.attempts = 1, this.waiting = 0, this.phone = ''});

  factory ProcessingItem.fromJson(Map<String, dynamic> j) => ProcessingItem(
    row: RecentShipment.fromJson(j),
    reason: j['reason'] as String?,
    attempts: j['attempts'] as int? ?? 1,
    waiting: j['waiting'] as int? ?? 0,
    phone: j['phone'] as String? ?? '',
  );

  final RecentShipment row;
  final String? reason;
  final int attempts;

  /// ساعات الانتظار منذ تعثّرت
  final int waiting;
  final String phone;
}

class ProcessingPage {
  ProcessingPage({required this.allowed, required this.total, required this.items, this.page = 1, this.last = 1});

  factory ProcessingPage.fromJson(Map<String, dynamic> j) => ProcessingPage(
    allowed: j['allowed'] as bool? ?? false,
    total: j['total'] as int? ?? 0,
    page: j['page'] as int? ?? 1,
    last: j['last'] as int? ?? 1,
    items: [for (final i in j['data'] as List) ProcessingItem.fromJson((i as Map).cast())],
  );

  /// أفعّلت الشركة للتاجر المعالجة من التطبيق؟
  final bool allowed;
  final int total;
  final int page;
  final int last;
  final List<ProcessingItem> items;
}

class PayoutMethod {
  const PayoutMethod({required this.value, required this.label, this.details = false, this.hint});

  factory PayoutMethod.fromJson(Map<String, dynamic> j) => PayoutMethod(
    value: j['value'] as String,
    label: j['label'] as String,
    details: j['details'] as bool? ?? false,
    hint: j['hint'] as String?,
  );

  final String value;
  final String label;

  /// بطاقةٌ أو محفظة: يُكتب رقمها واسم صاحبها
  final bool details;
  final String? hint;
}

class Statement {
  const Statement({
    required this.id,
    required this.code,
    required this.status,
    required this.paid,
    required this.net,
    required this.count,
    this.advance = 0,
    this.at,
    this.reference,
    this.confirmed = false,
  });

  factory Statement.fromJson(Map<String, dynamic> j) => Statement(
    id: j['id'] as int,
    code: j['code'] as String,
    status: j['status'] as String,
    paid: j['paid'] as bool? ?? false,
    net: j['net'] as int? ?? 0,
    count: j['count'] as int? ?? 0,
    advance: j['advance'] as int? ?? 0,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
    reference: j['reference'] as String?,
    confirmed: j['confirmed'] as bool? ?? false,
  );

  final int id;
  final String code;
  final String status;
  final bool paid;
  final int net;
  final int count;
  final int advance;
  final DateTime? at;
  final String? reference;

  /// أكّد التاجر أن الدفعة وصلته
  final bool confirmed;
}

class Movement {
  const Movement({required this.label, required this.amount, this.shipment, this.at});

  factory Movement.fromJson(Map<String, dynamic> j) => Movement(
    label: j['label'] as String? ?? '',
    amount: j['amount'] as int? ?? 0,
    shipment: j['shipment'] as String?,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
  );

  final String label;

  /// بإشارته: له موجب، وعليه سالب
  final int amount;
  final String? shipment;
  final DateTime? at;
}

/// طلب محاسبةٍ مفتوح: رقمه ومبلغه وطريقته
class PaymentRequest {
  const PaymentRequest({required this.number, required this.amount, required this.method, this.at});

  factory PaymentRequest.fromJson(Map<String, dynamic> j) => PaymentRequest(
    number: j['number'] as String,
    amount: j['amount'] as int? ?? 0,
    method: j['method'] as String? ?? '',
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
  );

  final String number;
  final int amount;
  final String method;
  final DateTime? at;
}

/// «المالية»: GET /merchant/finance
class Finance {
  Finance({
    required this.total,
    required this.owed,
    required this.available,
    required this.pending,
    this.pendingReason,
    this.awaitingPayment = 0,
    this.advances = 0,
    this.request,
    required this.methods,
    this.method,
    this.account,
    required this.statements,
    required this.movements,
  });

  factory Finance.fromJson(Map<String, dynamic> j) {
    final b = (j['balance'] as Map).cast<String, dynamic>();
    return Finance(
      total: b['total'] as int? ?? 0,
      owed: b['owed'] as bool? ?? true,
      available: b['available'] as int? ?? 0,
      pending: b['pending'] as int? ?? 0,
      pendingReason: b['reason'] as String?,
      awaitingPayment: b['awaiting_payment'] as int? ?? 0,
      advances: b['advances'] as int? ?? 0,
      request: j['request'] == null ? null : PaymentRequest.fromJson((j['request'] as Map).cast()),
      methods: [for (final m in j['methods'] as List) PayoutMethod.fromJson((m as Map).cast())],
      method: j['method'] as String?,
      account: j['account'] as String?,
      statements: [for (final s in j['statements'] as List) Statement.fromJson((s as Map).cast())],
      movements: [for (final m in j['movements'] as List) Movement.fromJson((m as Map).cast())],
    );
  }

  final int total;
  final bool owed;
  final int available;
  final int pending;
  final String? pendingReason;
  final int awaitingPayment;
  final int advances;
  final PaymentRequest? request;
  final List<PayoutMethod> methods;

  /// طريقة الدفع المحفوظة للتاجر، وآخر أرقام حسابه المحفوظ
  final String? method;
  final String? account;
  final List<Statement> statements;
  final List<Movement> movements;
}

/// ما قرأه النظام من رسالةٍ أو لقطة شاشةٍ أو تسجيل (docs/plan/55): يملأ «طلب جديد» ولا يحفظ
class OrderReading {
  OrderReading({
    required this.fields,
    this.found = const {},
    this.missing = const [],
    this.warnings = const [],
    this.transcript,
    this.ai = false,
  });

  factory OrderReading.fromJson(Map<String, dynamic> j) => OrderReading(
    fields: (j['fields'] as Map? ?? const {}).cast<String, dynamic>(),
    found: {for (final e in (j['found'] as Map? ?? const {}).entries) '${e.key}': '${e.value}'},
    // «المنطقة»، «المبلغ» — بأسمائها كما يكتبها النظام
    missing: [for (final m in j['missing'] as List? ?? const []) '$m'],
    warnings: [for (final w in j['warnings'] as List? ?? const []) '$w'],
    transcript: j['transcript'] as String?,
    ai: j['engine'] == 'ai',
  );

  /// recipient_name · recipient_phone · recipient_phone_alt · governorate_id · city_id · landmark
  /// · cod_amount · pieces_count · notes
  final Map<String, dynamic> fields;

  /// اسم المحافظة والمنطقة كما قُرئتا: {governorate_id: «بغداد»، city_id: «الكرادة»}
  final Map<String, String> found;
  final List<String> missing;
  final List<String> warnings;

  /// ما سُمع من التسجيل نصّاً
  final String? transcript;
  final bool ai;
}
