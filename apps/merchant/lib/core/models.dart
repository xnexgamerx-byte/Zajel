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
  });

  factory CreateForm.fromJson(Map<String, dynamic> j) => CreateForm(
    governorates: [for (final g in j['governorates'] as List) Choice.fromJson((g as Map).cast())],
    home: j['home'] == null ? null : '${j['home']}',
    sizes: [for (final s in j['sizes'] as List) Choice.fromJson((s as Map).cast())],
    types: [for (final t in j['types'] as List) Choice.fromJson((t as Map).cast())],
    required: [for (final r in j['required'] as List) '$r'],
    waybills: j['waybills'] as bool? ?? false,
    goods: j['goods'] as String? ?? 'ملابس',
  );

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
