// ما تعيده واجهة المندوب (/api/v1/courier/…، docs/plan/66) — بأسماء حقولها في النظام نفسها.

int _int(Object? v) => v is int ? v : (v is num ? v.toInt() : int.tryParse('$v') ?? 0);

List<Map<String, dynamic>> _list(Object? v) => [for (final e in (v as List?) ?? const []) (e as Map).cast()];

/// المندوب الداخل وشركته (/me و/login)
class Session {
  Session({required this.name, required this.companyName, this.companyColor, this.initial = ''});

  factory Session.fromJson(Map<String, dynamic> j) {
    final company = (j['company'] as Map?)?.cast<String, dynamic>() ?? const {};
    return Session(
      name: (j['user'] as Map?)?['name'] as String? ?? '',
      companyName: company['name'] as String? ?? '',
      companyColor: company['color'] as String?,
      initial: company['initial'] as String? ?? '',
    );
  }

  final String name;
  final String companyName;
  final String? companyColor;
  final String initial;
}

/// النقد بيده وسقفه وعمولته والواجب تسليمه — كما تحسبه الشركة (Courier::netDue)
class CashSummary {
  CashSummary({
    required this.inHand,
    required this.limit,
    required this.percent,
    required this.limitReached,
    required this.commission,
    required this.netDue,
  });

  factory CashSummary.fromJson(Map<String, dynamic> j) => CashSummary(
    inHand: _int(j['in_hand']),
    limit: _int(j['limit']),
    percent: j['percent'] == null ? null : _int(j['percent']),
    limitReached: j['limit_reached'] as bool? ?? false,
    commission: _int(j['commission']),
    netDue: _int(j['net_due']),
  );

  final int inHand;

  /// صفر: بلا سقف
  final int limit;
  final int? percent;
  final bool limitReached;
  final int commission;
  final int netDue;

  /// قرب السقف (٧٥٪ فأكثر): الشريط كهرمانيّ والنصيحة تظهر
  bool get near => limitReached || (percent ?? 0) >= 75;
}

/// «تحتاج انتباهك»: تذكرة · كود · راجع
class Attention {
  Attention({required this.kind, required this.id, required this.number, required this.text});

  factory Attention.fromJson(Map<String, dynamic> j) => Attention(
    kind: j['kind'] as String? ?? '',
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    text: j['text'] as String? ?? '',
  );

  final String kind;
  final int id;
  final String number;
  final String text;
}

class HomeData {
  HomeData({
    required this.greeting,
    required this.name,
    required this.unread,
    required this.inHand,
    required this.toCollect,
    required this.cash,
    required this.delivered,
    required this.failed,
    required this.postponed,
    required this.returns,
    required this.attention,
  });

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final hand = (j['in_hand'] as Map).cast<String, dynamic>();
    final today = (j['today'] as Map).cast<String, dynamic>();
    return HomeData(
      greeting: j['greeting'] as String? ?? '',
      name: j['name'] as String? ?? '',
      unread: _int(j['unread']),
      inHand: _int(hand['count']),
      toCollect: _int(hand['to_collect']),
      cash: CashSummary.fromJson((j['cash'] as Map).cast()),
      delivered: _int(today['delivered']),
      failed: _int(today['failed']),
      postponed: _int(today['postponed']),
      returns: _int(j['returns']),
      attention: [for (final a in _list(j['attention'])) Attention.fromJson(a)],
    );
  }

  final String greeting;
  final String name;
  final int unread;
  final int inHand;
  final int toCollect;
  final CashSummary cash;
  final int delivered;
  final int failed;
  final int postponed;
  final int returns;
  final List<Attention> attention;
}

/// سطر الشحنة في «مهامي»
class TaskRow {
  TaskRow({
    required this.id,
    required this.number,
    required this.status,
    required this.statusLabel,
    required this.recipient,
    this.city,
    this.landmark,
    required this.amount,
    this.needsCode = false,
    this.fragile = false,
    this.hasNote = false,
    this.ticketStatus,
    this.ticketLabel,
    this.failure,
  });

  factory TaskRow.fromJson(Map<String, dynamic> j) {
    final ticket = (j['ticket'] as Map?)?.cast<String, dynamic>();
    return TaskRow(
      id: _int(j['id']),
      number: j['number'] as String? ?? '',
      status: j['status'] as String? ?? '',
      statusLabel: j['status_label'] as String? ?? '',
      recipient: j['recipient'] as String? ?? '',
      city: j['city'] as String?,
      landmark: j['landmark'] as String?,
      amount: _int(j['amount']),
      needsCode: j['needs_code'] as bool? ?? false,
      fragile: j['fragile'] as bool? ?? false,
      hasNote: j['has_note'] as bool? ?? false,
      ticketStatus: ticket?['status'] as String?,
      ticketLabel: ticket?['label'] as String?,
      failure: j['failure'] as String?,
    );
  }

  final int id;
  final String number;
  final String status;
  final String statusLabel;
  final String recipient;
  final String? city;
  final String? landmark;
  final int amount;
  final bool needsCode;
  final bool fragile;
  final bool hasNote;
  final String? ticketStatus;
  final String? ticketLabel;
  final String? failure;

  /// «زينب علي · شارع 62»
  String get place => [recipient, if ((landmark ?? '').isNotEmpty) landmark!].join(' · ');
}

class TaskGroup {
  TaskGroup({required this.governorate, this.city, required this.items});

  factory TaskGroup.fromJson(Map<String, dynamic> j) => TaskGroup(
    governorate: j['governorate'] as String? ?? '—',
    city: j['city'] as String?,
    items: [for (final r in _list(j['items'])) TaskRow.fromJson(r)],
  );

  final String governorate;
  final String? city;
  final List<TaskRow> items;

  String get title => city == null ? governorate : '$governorate — $city';
}

class TaskList {
  TaskList({required this.kind, required this.count, required this.toCollect, required this.groups});

  factory TaskList.fromJson(Map<String, dynamic> j) => TaskList(
    kind: j['kind'] as String? ?? 'delivery',
    count: _int(j['count']),
    toCollect: _int(j['to_collect']),
    groups: [for (final g in _list(j['groups'])) TaskGroup.fromJson(g)],
  );

  final String kind;
  final int count;
  final int toCollect;
  final List<TaskGroup> groups;
}

/// طلب تغيير المبلغ إلى الكول سنتر
class Ticket {
  Ticket({
    required this.number,
    required this.kind,
    required this.kindLabel,
    required this.status,
    required this.statusLabel,
    required this.requested,
    this.approved,
  });

  factory Ticket.fromJson(Map<String, dynamic> j) => Ticket(
    number: j['number'] as String? ?? '',
    kind: j['kind'] as String? ?? '',
    kindLabel: j['kind_label'] as String? ?? '',
    status: j['status'] as String? ?? '',
    statusLabel: j['status_label'] as String? ?? '',
    requested: _int(j['requested_amount']),
    approved: j['approved_amount'] == null ? null : _int(j['approved_amount']),
  );

  final String number;
  final String kind;
  final String kindLabel;
  final String status;
  final String statusLabel;
  final int requested;
  final int? approved;

  bool get open => status == 'open';
}

/// الشحنة كاملةً كما في شاشة المندوب — بلا كود التسليم: يُطلب من الزبون فقط
class ShipmentDetail {
  ShipmentDetail({
    required this.id,
    required this.number,
    required this.status,
    required this.statusLabel,
    required this.recipient,
    required this.phone,
    this.phoneAlt,
    this.governorate,
    this.city,
    this.address,
    this.landmark,
    this.lat,
    this.lng,
    this.merchant,
    this.merchantPhone,
    required this.amount,
    this.needsCode = false,
    this.pieces = 1,
    this.description,
    this.notes,
    this.fragile = false,
    this.allowOpen = false,
    this.attempts = 0,
    this.failure,
    this.ticket,
    this.actions = const [],
    this.partialAmount,
    this.type = 'delivery',
  });

  factory ShipmentDetail.fromJson(Map<String, dynamic> j) {
    final r = (j['recipient'] as Map?)?.cast<String, dynamic>() ?? const {};
    final a = (j['address'] as Map?)?.cast<String, dynamic>() ?? const {};
    final loc = (j['location'] as Map?)?.cast<String, dynamic>();
    final m = (j['merchant'] as Map?)?.cast<String, dynamic>() ?? const {};
    final t = (j['ticket'] as Map?)?.cast<String, dynamic>();
    return ShipmentDetail(
      id: _int(j['id']),
      number: j['number'] as String? ?? '',
      type: j['type'] as String? ?? 'delivery',
      status: j['status'] as String? ?? '',
      statusLabel: j['status_label'] as String? ?? '',
      recipient: r['name'] as String? ?? '',
      phone: r['phone'] as String? ?? '',
      phoneAlt: r['phone_alt'] as String?,
      governorate: a['governorate'] as String?,
      city: a['city'] as String?,
      address: a['text'] as String?,
      landmark: a['landmark'] as String?,
      lat: (loc?['lat'] as num?)?.toDouble(),
      lng: (loc?['lng'] as num?)?.toDouble(),
      merchant: m['name'] as String?,
      merchantPhone: m['phone'] as String?,
      amount: _int(j['amount']),
      needsCode: j['needs_code'] as bool? ?? false,
      pieces: _int(j['pieces']),
      description: j['description'] as String?,
      notes: j['notes'] as String?,
      fragile: j['fragile'] as bool? ?? false,
      allowOpen: j['allow_open'] as bool? ?? false,
      attempts: _int(j['attempts']),
      failure: j['failure'] as String?,
      ticket: t == null ? null : Ticket.fromJson(t),
      actions: [for (final x in (j['actions'] as List?) ?? const []) '$x'],
      partialAmount: j['partial_amount'] == null ? null : _int(j['partial_amount']),
    );
  }

  final int id;
  final String number;
  final String type;
  final String status;
  final String statusLabel;
  final String recipient;
  final String phone;
  final String? phoneAlt;
  final String? governorate;
  final String? city;
  final String? address;
  final String? landmark;
  final double? lat;
  final double? lng;
  final String? merchant;
  final String? merchantPhone;
  final int amount;
  final bool needsCode;
  final int pieces;
  final String? description;
  final String? notes;
  final bool fragile;
  final bool allowOpen;
  final int attempts;
  final String? failure;
  final Ticket? ticket;

  /// ما يُسجَّل عليها الآن — فارغةٌ إن لم تعد بيده أو لم تكن «قيد التوصيل»
  final List<String> actions;

  /// الواصل الجزئي بما اعتمده الكول سنتر وحده
  final int? partialAmount;

  bool get canAct => actions.isNotEmpty;

  /// «بغداد · الكرادة · شارع 62»
  String get place => [governorate, city, address].whereType<String>().where((s) => s.isNotEmpty).join(' · ');
}

/// سبب عدم التسليم أو التأجيل، بترتيب الشركة
class Reason {
  Reason({required this.id, required this.name, this.requiresNote = false, this.forPostponed = false});

  factory Reason.fromJson(Map<String, dynamic> j) => Reason(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    requiresNote: j['requires_note'] as bool? ?? false,
    forPostponed: j['for_postponed'] as bool? ?? false,
  );

  final int id;
  final String name;
  final bool requiresNote;
  final bool forPostponed;
}

/// حركةٌ في دفتر المندوب
class Movement {
  Movement({
    required this.id,
    required this.category,
    required this.label,
    required this.credit,
    required this.amount,
    this.shipment,
    this.description,
    this.at,
  });

  factory Movement.fromJson(Map<String, dynamic> j) => Movement(
    id: _int(j['id']),
    category: j['category'] as String? ?? '',
    label: j['label'] as String? ?? '',
    credit: j['direction'] == 'credit',
    amount: _int(j['amount']),
    shipment: j['shipment'] as String?,
    description: j['description'] as String?,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
  );

  final int id;
  final String category;
  final String label;
  final bool credit;
  final int amount;
  final String? shipment;
  final String? description;
  final DateTime? at;

  /// الأسماء القصيرة نفسها في شاشة المندوب بالموقع
  String get short => switch (category) {
    'cod_collected' => 'تحصيل',
    'commission' => 'عمولة',
    'commission_paid' => 'قبض عمولة',
    'cash_handover' => 'تسليم نقد',
    'deduction' => 'خصم',
    _ => label,
  };
}

class Settlement {
  Settlement({
    required this.code,
    required this.status,
    required this.statusLabel,
    required this.shipments,
    required this.net,
    this.date,
  });

  factory Settlement.fromJson(Map<String, dynamic> j) => Settlement(
    code: j['code'] as String? ?? '',
    status: j['status'] as String? ?? '',
    statusLabel: j['status_label'] as String? ?? '',
    shipments: _int(j['shipments']),
    net: _int(j['net']),
    date: j['date'] as String?,
  );

  final String code;
  final String status;
  final String statusLabel;
  final int shipments;
  final int net;
  final String? date;
}

class CashPage {
  CashPage({required this.summary, required this.movements, required this.settlements});

  factory CashPage.fromJson(Map<String, dynamic> j) => CashPage(
    summary: CashSummary.fromJson(j),
    movements: [for (final m in _list(j['movements'])) Movement.fromJson(m)],
    settlements: [for (final s in _list(j['settlements'])) Settlement.fromJson(s)],
  );

  final CashSummary summary;
  final List<Movement> movements;
  final List<Settlement> settlements;
}

/// ما يعود بعد التسجيل: نصّ النظام والشحنة بحالها الجديدة
class Outcome {
  Outcome(this.message, this.shipment);

  final String message;
  final ShipmentDetail shipment;
}
