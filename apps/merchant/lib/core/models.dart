import 'config.dart';

/// ما يعيده النظام لرئيسية التاجر: GET /api/v1/merchant/home
class HomeData {
  HomeData({
    required this.greeting,
    required this.name,
    required this.unread,
    required this.banners,
    this.logo,
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
    this.attentionItems = const [],
    this.toPrint = 0,
    this.printed = 0,
    this.permissions,
    this.staffTool = false,
  });

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final stats = (j['stats'] as Map?)?.cast<String, dynamic>();
    final balance = (j['balance'] as Map?)?.cast<String, dynamic>();
    return HomeData(
      greeting: j['greeting'] as String,
      name: j['name'] as String? ?? '',
      unread: j['unread'] as int? ?? 0,
      logo: (j['logo'] as String?) == null ? null : media(j['logo'] as String),
      banners: [for (final b in j['banners'] as List) AdBanner.fromJson((b as Map).cast())],
      balance: balance?['total'] as int?,
      owed: balance?['owed'] as bool? ?? true,
      available: balance?['available'] as int? ?? 0,
      pending: balance?['pending'] as int? ?? 0,
      pendingReason: balance?['reason'] as String?,
      stats: stats == null
          ? null
          : Stats(
              total: stats['total'] as int,
              delivered: stats['delivered'] as int,
              inDelivery: stats['in_delivery'] as int,
              returns: stats['returns'] as int,
              waiting: stats['waiting'] as int? ?? 0,
              warehouse: stats['warehouse'] as int? ?? 0,
              courier: stats['courier'] as int? ?? 0,
              deliveredToday: stats['delivered_today'] as int? ?? 0,
              returning: stats['returning'] as int? ?? 0,
            ),
      processing: j['processing']['count'] as int,
      canProcess: j['processing']['allowed'] as bool,
      attention: j['attention']['count'] as int,
      recent: [for (final r in j['recent'] as List) RecentShipment.fromJson((r as Map).cast())],
      attentionItems: [
        for (final r in (j['attention']['items'] as List?) ?? const []) AttentionItem.fromJson((r as Map).cast()),
      ],
      toPrint: (j['printer'] as Map?)?['to_print'] as int? ?? 0,
      printed: (j['printer'] as Map?)?['printed'] as int? ?? 0,
      permissions: (j['permissions'] as List?)?.map((p) => '$p').toList(),
      staffTool: (j['tools'] as Map?)?['staff'] as bool? ?? false,
    );
  }

  /// ما يفتحه الحساب (docs/plan/87)، وnull من نظامٍ لا يعرف الموظّفين
  final List<String>? permissions;

  /// «موظّفو المتجر» في «المزيد»: لصاحب المتجر، والميزة مفتوحةٌ لشركته
  final bool staffTool;

  final String greeting;

  /// صورة التاجر أو شعاره (يُفتح برمزه) — فارغةٌ حتى يضعها
  final String? logo;
  final String name;
  final int unread;
  final List<AdBanner> banners;

  /// الرصيد لمن يرى المالية وحده (docs/plan/87)
  final int? balance;

  /// لك عند الشركة (true) أو عليك لها
  final bool owed;

  /// المتاح للسحب: ما حاسبت الشركة مندوبه عليه (docs/plan/49)
  final int available;

  /// قيد المطابقة: واصلٌ نقده ما زال مع المندوب، وسببه بكلام التاجر
  final int pending;
  final String? pendingReason;

  /// الأرقام لمن يرى الشحنات وحده
  final Stats? stats;
  final int processing;
  final bool canProcess;
  final int attention;
  final List<RecentShipment> recent;

  /// «تحتاج انتباهك» بتفاصيلها (docs/plan/61): قبل آخر الشحنات في الرئيسية
  final List<AttentionItem> attentionItems;

  /// «الطابعة» (docs/plan/79): الجديدة التي لم يُطبع وصلها، وما طُبع ينتظر المندوب
  final int toPrint;
  final int printed;
}

class Stats {
  const Stats({
    required this.total,
    required this.delivered,
    required this.inDelivery,
    required this.returns,
    this.waiting = 0,
    this.warehouse = 0,
    this.courier = 0,
    this.deliveredToday = 0,
    this.returning = 0,
  });

  final int total;
  final int delivered;
  final int inDelivery;
  final int returns;

  /// «رحلة شحناتك» (docs/plan/70): بانتظار مندوب ← بالمخزن ← مع المندوب ← سُلّمت اليوم
  final int waiting;
  final int warehouse;
  final int courier;
  final int deliveredToday;

  /// ما يرجع إليه الآن
  final int returning;
}

class AdBanner {
  AdBanner({required this.title, required this.image, this.link});

  factory AdBanner.fromJson(Map<String, dynamic> j) =>
      AdBanner(title: j['title'] as String? ?? '', image: media(j['image'] as String), link: j['link'] as String?);

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
    this.governorate,
    this.city,
    this.until,
    this.returnPlace,
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
    governorate: j['governorate'] as String?,
    city: j['city'] as String?,
    until: DateTime.tryParse(j['until'] as String? ?? '')?.toLocal(),
    returnPlace: j['return_place'] as String?,
  );

  final int id;
  final String number;
  final String name;

  /// أين الراجع الآن (docs/plan/87): «راجع عند المندوب»، «راجع عند مركز محافظة بابل»، «راجع في المخزن»
  final String? returnPlace;

  /// المؤجّلة بموعدها (docs/plan/86): «مؤجل إلى غداً الساعة 9:00 م»
  final DateTime? until;

  /// المنطقة، أو المحافظة إن لم تُحدَّد — ما يرسله النظام القديم وحده
  final String area;
  final String? governorate;
  final String? city;

  /// «المحافظة · المنطقة» كاملين (docs/plan/70)
  String get place {
    final parts = [governorate, city].whereType<String>().where((p) => p.isNotEmpty).toList();
    return parts.isEmpty ? area : parts.join(' · ');
  }

  final int amount;
  final DateTime? at;
  final String status;
  final bool urgent;

  /// لون الحالة كما في النظام: green · blue · amber · yellow (المؤجل) · red · gray · slate
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

/// محافظةٌ في شريحة، بعدد ما فيها (docs/plan/86): «بغداد 12»
class GovernorateCount {
  GovernorateCount({required this.id, required this.name, required this.count});

  factory GovernorateCount.fromJson(Map<String, dynamic> j) =>
      GovernorateCount(id: j['id'] as int, name: j['name'] as String? ?? '', count: j['count'] as int? ?? 0);

  final int id;
  final String name;
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
    this.governorates = const [],
  });

  factory ShipmentPage.fromJson(Map<String, dynamic> j) => ShipmentPage(
    filters: [for (final f in j['filters'] as List) ShipmentFilter.fromJson((f as Map).cast())],
    items: [for (final r in j['data'] as List) RecentShipment.fromJson((r as Map).cast())],
    page: j['meta']['page'] as int,
    lastPage: j['meta']['last_page'] as int,
    total: j['meta']['total'] as int,
    governorates: [
      for (final g in (j['governorates'] as List?) ?? const []) GovernorateCount.fromJson((g as Map).cast()),
    ],
  );

  /// محافظات الشريحة للفرز بها (docs/plan/86)
  final List<GovernorateCount> governorates;
  final List<ShipmentFilter> filters;
  final List<RecentShipment> items;
  final int page;
  final int lastPage;
  final int total;
}

/// ما يصل هاتف التاجر من الإشعارات، وبصوتٍ أم بلا صوت (docs/plan/86)
class NotifySettings {
  NotifySettings({required this.kinds, required this.sound});

  factory NotifySettings.fromJson(Map<String, dynamic> j) => NotifySettings(
    kinds: [
      for (final k in (j['kinds'] as List?) ?? const [])
        (key: (k as Map)['key'] as String, label: k['label'] as String? ?? '', on: k['on'] as bool? ?? true),
    ],
    sound: j['sound'] as bool? ?? true,
  );

  /// delivered · failed · processed · amount
  final List<({String key, String label, bool on})> kinds;
  final bool sound;
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
    this.courier,
    this.lastStatus,
    this.lastAt,
    this.location,
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
      courier: (j['tracking'] as Map?)?['courier'] as String?,
      lastStatus: (j['tracking'] as Map?)?['status'] as String?,
      lastAt: _at((j['tracking'] as Map?)?['at']),
      location: ShipmentLocation.fromJson((j['tracking'] as Map?)?['location'] as Map?),
    );
  }

  /// على الخريطة (docs/plan/61): من هاتف المندوب، أو تقريبيٌّ بمحافظتها
  final ShipmentLocation? location;

  /// تتبّعه الآن (docs/plan/59): المندوب الذي معه الطرد، وآخر حالةٍ ومتى
  final String? courier;
  final String? lastStatus;
  final DateTime? lastAt;

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
  Session({
    required this.name,
    required this.companyName,
    this.companyColor,
    this.companyLogo,
    this.companyInitial,
    this.permissions,
  });

  factory Session.fromJson(Map<String, dynamic> j) => Session(
    name: j['user']['name'] as String,
    companyName: j['company']['name'] as String,
    companyColor: j['company']['color'] as String?,
    companyLogo: (j['company']['logo'] as String?) == null ? null : media(j['company']['logo'] as String),
    companyInitial: j['company']['initial'] as String?,
    permissions: ((j['merchant'] as Map?)?['permissions'] as List?)?.map((p) => '$p').toList(),
  );

  /// ما يفتحه الحساب (docs/plan/87): صاحب المتجر كلّها ومعها owner
  final List<String>? permissions;

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
    this.batchMax = 30,
    this.batchParallel = 4,
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
    batchMax: j['batch_max'] as int? ?? 30,
    batchParallel: j['batch_parallel'] as int? ?? 4,
  );

  /// صورٌ كثيرة مرّةً واحدة (docs/plan/63): أكثرها من «بيانات الشركة»، وكم تُقرأ معاً (نوى الخادم)
  final int batchMax;
  final int batchParallel;

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
    this.scanRequired = false,
    this.month,
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
      scanRequired: j['scan_required'] as bool? ?? false,
      month: j['month'] == null ? null : MonthStats.fromJson((j['month'] as Map).cast()),
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

  /// الشركة تطلب مسح الوصل لتأكيد الاستلام (docs/plan/69): لا «استلمتُها» بزرّ
  final bool scanRequired;

  /// «مؤشراتك هذا الشهر» (docs/plan/70)
  final MonthStats? month;
}

/// «مؤشراتك هذا الشهر» (docs/plan/70): ما سُلّم وما رجع، والمحصَّل أسبوعاً أسبوعاً
class MonthStats {
  const MonthStats({
    required this.label,
    required this.delivered,
    required this.returned,
    this.rate,
    this.returnRate,
    required this.collected,
    this.growth,
    required this.average,
    required this.shipments,
    required this.weeks,
  });

  factory MonthStats.fromJson(Map<String, dynamic> j) => MonthStats(
    label: j['label'] as String? ?? '',
    delivered: j['delivered'] as int? ?? 0,
    returned: j['returned'] as int? ?? 0,
    rate: j['rate'] as int?,
    returnRate: j['return_rate'] as int?,
    collected: j['collected'] as int? ?? 0,
    growth: j['growth'] as int?,
    average: j['average'] as int? ?? 0,
    shipments: j['shipments'] as int? ?? 0,
    weeks: [for (final w in (j['weeks'] as List?) ?? const []) w as int],
  );

  final String label;
  final int delivered;
  final int returned;

  /// نسبة التسليم من المنتهية هذا الشهر — null ولا منتهية بعد
  final int? rate;
  final int? returnRate;
  final int collected;

  /// الفرق عن الشهر الماضي بالمئة — null وليس له شهرٌ ماضٍ
  final int? growth;
  final int average;
  final int shipments;
  final List<int> weeks;
}

/// ما أكّده مسح الورقة: دفعة كشفٍ أو رواجع إيصال
class Received {
  const Received({required this.message, required this.type, required this.id});

  factory Received.fromJson(Map<String, dynamic> j) => Received(
    message: j['message'] as String? ?? 'أكّدت الاستلام.',
    type: j['type'] as String? ?? 'statement',
    id: j['id'] as int? ?? 0,
  );

  final String message;

  /// statement أو returns
  final String type;
  final int id;

  bool get statement => type == 'statement';
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

// ---------------------------------------------------------------- الأدوات السريعة (docs/plan/56)

DateTime? _at(Object? v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();

/// طلب استلام: GET /merchant/pickups
class Pickup {
  Pickup({
    required this.id,
    required this.number,
    required this.status,
    required this.label,
    required this.expected,
    this.actual,
    this.courier,
    this.courierPhone,
    this.at,
    this.day,
    this.notes,
  });

  factory Pickup.fromJson(Map<String, dynamic> j) => Pickup(
    id: j['id'] as int,
    number: '${j['number']}',
    status: j['status'] as String,
    label: j['label'] as String,
    expected: j['expected'] as int? ?? 0,
    actual: j['actual'] as int?,
    courier: (j['courier'] as Map?)?['name'] as String?,
    courierPhone: (j['courier'] as Map?)?['phone'] as String?,
    at: _at(j['at']),
    day: j['day'] as String?,
    notes: j['notes'] as String?,
  );

  final int id;
  final String number;

  /// pending · assigned · in_progress · completed · cancelled
  final String status;
  final String label;
  final int expected;
  final int? actual;
  final String? courier;
  final String? courierPhone;
  final DateTime? at;

  /// اليوم المفضّل للاستلام: 2026-10-11
  final String? day;
  final String? notes;
}

class PickupsPage {
  PickupsPage({required this.items, this.address, this.phone, this.open = false});

  factory PickupsPage.fromJson(Map<String, dynamic> j) => PickupsPage(
    items: [for (final p in j['data'] as List) Pickup.fromJson((p as Map).cast())],
    address: j['address'] as String?,
    phone: j['phone'] as String?,
    open: j['open'] as bool? ?? false,
  );

  final List<Pickup> items;

  /// عنوان الحساب وهاتفه: يُكتبان في الطلب ما لم يكتب غيرهما
  final String? address;
  final String? phone;

  /// طلبٌ مفتوح ينتظر مندوباً — لا يُطلب ثانٍ
  bool open;
}

/// طلبٌ من «طلباتي»: دفعٌ أو كشف راجع
class MerchantRequestRow {
  MerchantRequestRow({
    required this.id,
    required this.number,
    required this.type,
    required this.label,
    required this.status,
    required this.state,
    this.amount,
    this.method,
    this.courier = false,
    this.note,
    this.result,
    this.at,
  });

  factory MerchantRequestRow.fromJson(Map<String, dynamic> j) => MerchantRequestRow(
    id: j['id'] as int,
    number: '${j['number']}',
    type: j['type'] as String,
    label: j['label'] as String,
    status: j['status'] as String,
    state: j['state'] as String,
    amount: j['amount'] as int?,
    method: j['method'] as String?,
    courier: j['courier'] as bool? ?? false,
    note: j['note'] as String?,
    result: j['result'] as String?,
    at: _at(j['at']),
  );

  final int id;
  final String number;

  /// payment · returns
  final String type;
  final String label;

  /// open · handled · cancelled
  String status;
  String state;
  final int? amount;
  final String? method;
  final bool courier;
  final String? note;

  /// ما انتهى إليه: «كشف MS000041» أو «إيصال RB000012»
  final String? result;
  final DateTime? at;
}

/// إيصال راجعٍ سُلّم للتاجر
class ReturnReceipt {
  ReturnReceipt({
    required this.id,
    required this.number,
    required this.count,
    required this.fees,
    required this.via,
    this.at,
    this.received,
  });

  factory ReturnReceipt.fromJson(Map<String, dynamic> j) => ReturnReceipt(
    id: j['id'] as int,
    number: '${j['number']}',
    count: j['count'] as int? ?? 0,
    fees: j['fees'] as int? ?? 0,
    via: j['via'] as String? ?? '',
    at: _at(j['at']),
    received: _at(j['received']),
  );

  final int id;
  final String number;
  final int count;
  final int fees;
  final String via;
  final DateTime? at;

  /// متى أكّد التاجر وصوله — وما لم يؤكَّد فعليه «وصلتني»
  DateTime? received;
}

/// تفاصيل إيصال الراجع (docs/plan/61): شحناته كما طُبعت عليه
class ReturnReceiptDetail {
  ReturnReceiptDetail({required this.receipt, required this.lines, this.note, this.scanRequired = false});

  factory ReturnReceiptDetail.fromJson(Map<String, dynamic> j) => ReturnReceiptDetail(
    receipt: ReturnReceipt.fromJson(j),
    note: j['note'] as String?,
    lines: [for (final l in j['shipments'] as List) ReturnLine.fromJson((l as Map).cast())],
    scanRequired: j['scan_required'] as bool? ?? false,
  );

  /// «وصلتني» بمسح الإيصال لا بزرّ (docs/plan/69)
  final bool scanRequired;
  final ReturnReceipt receipt;
  final String? note;
  final List<ReturnLine> lines;
}

class ReturnLine {
  ReturnLine({
    required this.id,
    required this.number,
    required this.name,
    required this.place,
    required this.amount,
    required this.fee,
    this.reason,
  });

  factory ReturnLine.fromJson(Map<String, dynamic> j) => ReturnLine(
    id: j['id'] as int? ?? 0,
    number: j['number'] as String? ?? '',
    name: j['name'] as String? ?? '',
    place: j['place'] as String? ?? '',
    amount: j['amount'] as int? ?? 0,
    fee: j['fee'] as int? ?? 0,
    reason: j['reason'] as String?,
  );

  final int id;
  final String number;
  final String name;
  final String place;
  final int amount;
  final int fee;
  final String? reason;
}

class RequestsPage {
  RequestsPage({required this.returning, required this.requests, required this.batches, this.scanRequired = false});

  factory RequestsPage.fromJson(Map<String, dynamic> j) => RequestsPage(
    returning: j['returning'] as int? ?? 0,
    requests: [for (final r in j['requests'] as List) MerchantRequestRow.fromJson((r as Map).cast())],
    batches: [for (final b in j['batches'] as List) ReturnReceipt.fromJson((b as Map).cast())],
    scanRequired: j['scan_required'] as bool? ?? false,
  );

  /// «وصلتني» بمسح الإيصال لا بزرّ (docs/plan/69)
  final bool scanRequired;

  /// شحناته الراجعة الآن: ما يجمعه طلب كشف الراجع
  final int returning;
  final List<MerchantRequestRow> requests;
  final List<ReturnReceipt> batches;
}

/// محادثة دعم
class Conversation {
  Conversation({
    required this.id,
    required this.subject,
    this.shipment,
    this.closed = false,
    this.unread = false,
    this.staff = false,
    this.at,
  });

  factory Conversation.fromJson(Map<String, dynamic> j) => Conversation(
    id: j['id'] as int,
    subject: j['subject'] as String,
    shipment: j['shipment'] as String?,
    closed: j['closed'] as bool? ?? false,
    unread: j['unread'] as bool? ?? false,
    staff: j['staff'] as bool? ?? false,
    at: _at(j['at']),
  );

  final int id;
  final String subject;
  final String? shipment;
  final bool closed;

  /// ردّت الشركة ولم يقرأه
  bool unread;

  /// آخر رسالةٍ من الشركة
  final bool staff;
  final DateTime? at;
}

class SupportPage {
  SupportPage({required this.items, this.whatsapp, this.complaints});

  factory SupportPage.fromJson(Map<String, dynamic> j) => SupportPage(
    items: [for (final c in j['data'] as List) Conversation.fromJson((c as Map).cast())],
    whatsapp: j['whatsapp'] as String?,
    complaints: j['complaints'] as String?,
  );

  final List<Conversation> items;

  /// رابط واتساب الدعم (لمحافظته)، وهاتف الشكاوى
  final String? whatsapp;
  final String? complaints;
}

class Attachment {
  Attachment({required this.name, required this.image, required this.size, required this.url});

  factory Attachment.fromJson(Map<String, dynamic> j) => Attachment(
    name: j['name'] as String? ?? 'ملف',
    image: j['image'] as bool? ?? false,
    size: j['size'] as String? ?? '',
    url: media(j['url'] as String? ?? ''),
  );

  final String name;
  final bool image;
  final String size;

  /// يُفتح برمز التاجر — صورٌ تُعرض في المحادثة
  final String url;
}

class Message {
  Message({required this.id, required this.mine, required this.body, this.author, this.file, this.at});

  factory Message.fromJson(Map<String, dynamic> j) => Message(
    id: j['id'] as int,
    mine: j['mine'] as bool? ?? false,
    author: j['author'] as String?,
    body: j['body'] as String? ?? '',
    file: j['file'] == null ? null : Attachment.fromJson((j['file'] as Map).cast()),
    at: _at(j['at']),
  );

  final int id;
  final bool mine;

  /// اسم الموظّف لرسائل الشركة
  final String? author;
  final String body;
  final Attachment? file;
  final DateTime? at;
}

class Thread {
  Thread({required this.id, required this.subject, required this.messages, this.shipment, this.closed = false});

  factory Thread.fromJson(Map<String, dynamic> j) => Thread(
    id: j['id'] as int,
    subject: j['subject'] as String,
    shipment: j['shipment'] as String?,
    closed: j['closed'] as bool? ?? false,
    messages: [for (final m in j['messages'] as List) Message.fromJson((m as Map).cast())],
  );

  final int id;
  final String subject;
  final String? shipment;
  final bool closed;
  final List<Message> messages;
}

/// دفتر وصولاتٍ مطبوعة: GET /merchant/waybills
class WaybillBookRow {
  WaybillBookRow({
    required this.id,
    required this.range,
    required this.size,
    required this.label,
    required this.used,
    this.at,
    this.print = const {},
  });

  factory WaybillBookRow.fromJson(Map<String, dynamic> j) => WaybillBookRow(
    id: j['id'] as int,
    range: j['range'] as String,
    size: j['size'] as int? ?? 0,
    label: j['label'] as String? ?? '',
    used: j['used'] as int? ?? 0,
    at: _at(j['at']),
    print: {for (final e in (j['print'] as Map? ?? const {}).entries) '${e.key}': '${e.value}'},
  );

  final int id;

  /// «90000001–90000050»
  final String range;
  final int size;
  final String label;
  final int used;
  final DateTime? at;

  /// رابط الطباعة لكلّ مقاس — موقَّعٌ لساعة؛ فارغٌ إن استُعمل الدفتر كلّه
  final Map<String, String> print;
}

class WaybillsPage {
  WaybillsPage({required this.max, required this.sizes, required this.books});

  factory WaybillsPage.fromJson(Map<String, dynamic> j) => WaybillsPage(
    max: j['max'] as int? ?? 200,
    sizes: [for (final s in j['sizes'] as List) Choice.fromJson((s as Map).cast())],
    books: [for (final b in j['books'] as List) WaybillBookRow.fromJson((b as Map).cast())],
  );

  final int max;
  final List<Choice> sizes;
  final List<WaybillBookRow> books;
}

/// دفترٌ جديد: رابط طباعته بالمقاس المختار
class IssuedBook {
  IssuedBook({required this.range, required this.print, required this.message});

  factory IssuedBook.fromJson(Map<String, dynamic> j) =>
      IssuedBook(range: j['range'] as String, print: j['print'] as String, message: j['message'] as String);

  final String range;
  final String print;
  final String message;
}

/// معاينة ملف الشحنات قبل إنشائها: POST /merchant/import
class ImportPreview {
  ImportPreview({required this.path, required this.total, required this.good, required this.bad, required this.rows});

  factory ImportPreview.fromJson(Map<String, dynamic> j) => ImportPreview(
    path: j['path'] as String,
    total: j['total'] as int? ?? 0,
    good: j['good'] as int? ?? 0,
    bad: [
      for (final b in j['bad'] as List)
        (
          row: (b as Map)['row'] as int,
          name: b['name'] as String?,
          errors: [for (final e in b['errors'] as List) '$e'],
        ),
    ],
    rows: [
      for (final r in j['rows'] as List)
        (
          row: (r as Map)['row'] as int,
          name: r['name'] as String?,
          phone: r['phone'] as String? ?? '',
          place: r['place'] as String? ?? '',
          amount: r['amount'] as int? ?? 0,
        ),
    ],
  );

  /// الملف على الخادم حتى التأكيد — لرافعه وحده
  final String path;
  final int total;
  final int good;
  final List<({int row, String? name, List<String> errors})> bad;

  /// أوّل عشرين صفّاً صحيحاً
  final List<({int row, String? name, String phone, String place, int amount})> rows;
}

/// ما في الجرس: إعلانٌ من الشركة، أو ردٌّ منها على محادثته (docs/plan/59)
class AppNotice {
  AppNotice({
    required this.kind,
    required this.id,
    required this.title,
    required this.body,
    this.fresh = false,
    this.at,
  });

  factory AppNotice.fromJson(Map<String, dynamic> j) => AppNotice(
    kind: j['kind'] as String? ?? 'notice',
    id: j['id'] as int,
    title: j['title'] as String? ?? '',
    body: j['body'] as String? ?? '',
    fresh: j['fresh'] as bool? ?? false,
    at: _at(j['at']),
  );

  /// notice · support
  final String kind;
  final int id;
  final String title;
  final String body;

  /// لم يُقرأ قبل فتح الجرس هذه المرّة
  final bool fresh;
  final DateTime? at;
}

/// موقع الشحنة على الخريطة: دقيقٌ من هاتف المندوب مع آخر حدث، أو مركز محافظتها
class ShipmentLocation {
  ShipmentLocation({required this.lat, required this.lng, required this.exact, this.at});

  static ShipmentLocation? fromJson(Map? j) {
    if (j == null || j['lat'] == null || j['lng'] == null) return null;
    return ShipmentLocation(
      lat: (j['lat'] as num).toDouble(),
      lng: (j['lng'] as num).toDouble(),
      exact: j['exact'] as bool? ?? false,
      at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
    );
  }

  final double lat;
  final double lng;
  final bool exact;
  final DateTime? at;
}

/// شحنةٌ تحتاج انتباه التاجر: لماذا، وكم محاولة، وإلى متى أُجّلت
class AttentionItem {
  AttentionItem({required this.row, this.reason, this.attempts = 0, this.until});

  factory AttentionItem.fromJson(Map<String, dynamic> j) => AttentionItem(
    row: RecentShipment.fromJson(j),
    reason: j['reason'] as String?,
    attempts: j['attempts'] as int? ?? 0,
    until: DateTime.tryParse(j['until'] as String? ?? '')?.toLocal(),
  );

  final RecentShipment row;
  final String? reason;
  final int attempts;
  final DateTime? until;
}

/// تفاصيل كشف التاجر (docs/plan/61): شحناته سطراً سطراً ومجاميعه
class StatementDetail {
  StatementDetail({required this.statement, required this.totals, required this.lines, this.scanRequired = false});

  factory StatementDetail.fromJson(Map<String, dynamic> j) => StatementDetail(
    statement: Statement(
      id: j['id'] as int,
      code: j['code'] as String,
      status: j['status'] as String,
      paid: j['paid'] as bool? ?? false,
      net: (j['totals'] as Map)['net'] as int? ?? 0,
      count: (j['totals'] as Map)['count'] as int? ?? 0,
      advance: (j['totals'] as Map)['advance'] as int? ?? 0,
      at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
      reference: j['reference'] as String?,
      confirmed: j['confirmed'] as bool? ?? false,
    ),
    totals: (j['totals'] as Map).cast<String, dynamic>(),
    lines: [for (final l in j['lines'] as List) StatementLine.fromJson((l as Map).cast())],
    scanRequired: j['scan_required'] as bool? ?? false,
  );

  /// «استلمتُها» بمسح الوصل لا بزرّ (docs/plan/69)
  final bool scanRequired;
  final Statement statement;
  final Map<String, dynamic> totals;
  final List<StatementLine> lines;

  int total(String key) => totals[key] as int? ?? 0;
}

class StatementLine {
  StatementLine({
    required this.shipmentId,
    required this.number,
    required this.name,
    required this.returned,
    required this.collected,
    required this.delivery,
    required this.returnFee,
    required this.net,
  });

  factory StatementLine.fromJson(Map<String, dynamic> j) => StatementLine(
    shipmentId: j['shipment_id'] as int? ?? 0,
    number: j['number'] as String? ?? '',
    name: j['name'] as String? ?? '',
    returned: j['returned'] as bool? ?? false,
    collected: j['collected'] as int? ?? 0,
    delivery: j['delivery'] as int? ?? 0,
    returnFee: j['return_fee'] as int? ?? 0,
    net: j['net'] as int? ?? 0,
  );

  final int shipmentId;
  final String number;
  final String name;
  final bool returned;
  final int collected;
  final int delivery;
  final int returnFee;
  final int net;
}

// ---------------------------------------------------------------- طباعة الوصولات (docs/plan/63)

/// GET /merchant/labels?state=to_print|printed: «الطابعة» (docs/plan/79) — الجديدة التي لم يستلمها مندوبٌ بعد،
/// «للطباعة» أو «مطبوعة»، وعدد كلٍّ منهما، ومقاسات الطباعة
class LabelsPage {
  LabelsPage({
    required this.shipments,
    required this.sizes,
    this.state = 'to_print',
    this.toPrint = 0,
    this.printed = 0,
  });

  factory LabelsPage.fromJson(Map<String, dynamic> j) => LabelsPage(
    state: j['state'] as String? ?? 'to_print',
    toPrint: (j['counts'] as Map?)?['to_print'] as int? ?? 0,
    printed: (j['counts'] as Map?)?['printed'] as int? ?? 0,
    shipments: [for (final s in j['shipments'] as List) LabelRow.fromJson((s as Map).cast())],
    sizes: [for (final s in j['sizes'] as List) Choice('${(s as Map)['key']}', '${s['label']}')],
  );

  final String state;
  final int toPrint;
  final int printed;
  final List<LabelRow> shipments;
  final List<Choice> sizes;
}

class LabelRow {
  LabelRow({
    required this.id,
    required this.number,
    required this.name,
    required this.place,
    required this.amount,
    this.at,
    this.phone = '',
    this.printedAt,
  });

  factory LabelRow.fromJson(Map<String, dynamic> j) => LabelRow(
    id: j['id'] as int,
    number: '${j['number']}',
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    place: j['place'] as String? ?? '',
    amount: j['amount'] as int? ?? 0,
    at: _at(j['at']),
    printedAt: _at(j['printed_at']),
  );

  final String phone;

  /// أوّل طباعةٍ لوصلها — فارغٌ ما دامت «للطباعة»
  final DateTime? printedAt;

  final int id;
  final String number;
  final String name;
  final String place;
  final int amount;
  final DateTime? at;
}

// ------------------------------------------------------------------ رحلة شحناتك وتقويمها (docs/plan/87)

/// خانةٌ في «رحلة شحناتك اليوم»: حالةٌ بعددها، وتفتح قائمتها
class StateTile {
  const StateTile(this.key, this.label, this.count);

  factory StateTile.fromJson(Map<String, dynamic> j) =>
      StateTile('${j['key']}', j['label'] as String? ?? '', j['count'] as int? ?? 0);

  final String key;
  final String label;
  final int count;
}

class StatesData {
  const StatesData({required this.total, required this.tiles});

  factory StatesData.fromJson(Map<String, dynamic> j) => StatesData(
    total: j['total'] as int? ?? 0,
    tiles: [for (final t in j['data'] as List) StateTile.fromJson((t as Map).cast())],
  );

  final int total;
  final List<StateTile> tiles;
}

/// يومٌ في «تقويم الشحنات»: ما أُدخل فيه، وأين صار
class CalendarDay {
  const CalendarDay({required this.date, required this.total, required this.counts});

  factory CalendarDay.fromJson(Map<String, dynamic> j) => CalendarDay(
    date: DateTime.parse(j['date'] as String),
    total: j['total'] as int? ?? 0,
    counts: {for (final k in CalendarData.keys) k: j[k] as int? ?? 0},
  );

  final DateTime date;
  final int total;
  final Map<String, int> counts;
}

class CalendarData {
  const CalendarData({
    required this.from,
    required this.to,
    required this.total,
    required this.buckets,
    required this.days,
    this.rate,
    this.byUser = const [],
  });

  /// الخانات بترتيبها: تم التسليم، قيد التوصيل، مؤجل، للمعالجة، راجع
  static const keys = ['delivered', 'open', 'postponed', 'processing', 'returned'];

  factory CalendarData.fromJson(Map<String, dynamic> j) => CalendarData(
    from: DateTime.parse(j['from'] as String),
    to: DateTime.parse(j['to'] as String),
    total: j['total'] as int? ?? 0,
    rate: j['rate'] as int?,
    buckets: [for (final b in j['buckets'] as List) StateTile.fromJson((b as Map).cast())],
    days: [for (final d in j['days'] as List) CalendarDay.fromJson((d as Map).cast())],
    byUser: [
      for (final u in (j['by_user'] as List?) ?? const [])
        ((u as Map)['name'] as String? ?? '', u['count'] as int? ?? 0),
    ],
  );

  final DateTime from;
  final DateTime to;
  final int total;

  /// نسبة التسليم ممّا حُسم، وnull قبل أن يُحسم شيء
  final int? rate;
  final List<StateTile> buckets;
  final List<CalendarDay> days;

  /// من أدخلها، حين للمتجر موظّفون
  final List<(String, int)> byUser;
}

// ------------------------------------------------------------------ موظّفو المتجر (docs/plan/87)

class StaffMember {
  const StaffMember({
    required this.id,
    required this.name,
    required this.phone,
    required this.username,
    required this.permissions,
    required this.role,
    required this.active,
    this.lastLogin,
  });

  factory StaffMember.fromJson(Map<String, dynamic> j) => StaffMember(
    id: j['id'] as int,
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    username: j['username'] as String? ?? '',
    permissions: [for (final p in (j['permissions'] as List?) ?? const []) '$p'],
    role: j['role'] as String? ?? 'custom',
    active: j['active'] as bool? ?? true,
    lastLogin: DateTime.tryParse(j['last_login_at'] as String? ?? '')?.toLocal(),
  );

  final int id;
  final String name;
  final String phone;
  final String username;
  final List<String> permissions;

  /// entry · accountant · follow · custom
  final String role;
  final bool active;
  final DateTime? lastLogin;
}

/// صلاحيةٌ يفتحها صاحب المتجر لموظّفه، أو دورٌ جاهزٌ بصلاحياته
class StaffPermission {
  const StaffPermission(this.key, this.label, this.hint);

  final String key;
  final String label;
  final String hint;
}

class StaffRole {
  const StaffRole(this.key, this.label, this.permissions);

  final String key;
  final String label;
  final List<String> permissions;
}

class StaffPage {
  const StaffPage({required this.staff, required this.permissions, required this.roles, this.max = 20});

  factory StaffPage.fromJson(Map<String, dynamic> j) => StaffPage(
    staff: [for (final s in j['data'] as List) StaffMember.fromJson((s as Map).cast())],
    permissions: [
      for (final p in j['permissions'] as List)
        StaffPermission('${(p as Map)['key']}', p['label'] as String? ?? '', p['hint'] as String? ?? ''),
    ],
    roles: [
      for (final r in j['roles'] as List)
        StaffRole('${(r as Map)['key']}', r['label'] as String? ?? '', [
          for (final p in r['permissions'] as List) '$p',
        ]),
    ],
    max: j['max'] as int? ?? 20,
  );

  final List<StaffMember> staff;
  final List<StaffPermission> permissions;
  final List<StaffRole> roles;
  final int max;
}

/// هوية الشركة لشاشة الدخول قبل أن يدخل أحد (GET /company، docs/plan/88): اسمها وحرفها ولونها وشعارها كما
/// رفعته في نظامها — لا شيء منها في الكود
class CompanyBrand {
  const CompanyBrand({required this.name, this.initial = '', this.color, this.logo});

  factory CompanyBrand.fromJson(Map<String, dynamic> j) => CompanyBrand(
    name: j['name'] as String? ?? '',
    initial: j['initial'] as String? ?? '',
    color: j['color'] as String?,
    logo: (j['logo'] as String?) == null ? null : media(j['logo'] as String),
  );

  final String name;
  final String initial;
  final String? color;
  final String? logo;
}
