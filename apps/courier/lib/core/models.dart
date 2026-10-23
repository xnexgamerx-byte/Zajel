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
    this.collected = 0,
    this.delivers = true,
    this.picks = false,
    this.pickups = 0,
    this.chat = false,
    this.chatUnread = false,
    this.appointments = const [],
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
      collected: _int(today['collected']),
      delivers: j['delivers'] as bool? ?? true,
      picks: j['picks'] as bool? ?? false,
      pickups: _int(j['pickups']),
      chat: j['chat'] is Map,
      chatUnread: (j['chat'] as Map?)?['unread'] as bool? ?? false,
      appointments: [for (final a in _list(j['appointments'])) Appointment.fromJson(a)],
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

  /// ما حصّله اليوم (docs/plan/71)
  final int collected;

  /// مندوب توصيل، أو استلام، أو كلاهما: تظهر شاشاته وحدها
  final bool delivers;
  final bool picks;

  /// طلبات الاستلام المُسنَدة إليه الآن
  final int pickups;

  /// المحادثة مع المكتب مفتوحةٌ للشركة، وفيها ما لم يُقرأ
  final bool chat;
  final bool chatUnread;

  /// مواعيد المؤجّلة بيده (docs/plan/75): تذكيرٌ في الرئيسية وتنبيهٌ في موعدها
  final List<Appointment> appointments;
}

/// موعد تسليم شحنةٍ مؤجّلة
class Appointment {
  Appointment({
    required this.id,
    required this.number,
    required this.recipient,
    required this.at,
    this.phone,
    this.city,
    this.amount = 0,
  });

  factory Appointment.fromJson(Map<String, dynamic> j) => Appointment(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    recipient: j['recipient'] as String? ?? '',
    phone: j['phone'] as String?,
    city: j['city'] as String?,
    amount: _int(j['amount']),
    at: _at(j['at']) ?? DateTime.now(),
  );

  final int id;
  final String number;
  final String recipient;
  final String? phone;
  final String? city;
  final int amount;
  final DateTime at;

  /// موعدٌ بساعته — وما كان منتصف الليل فموعده اليوم كلّه
  bool get hasTime => at.hour != 0 || at.minute != 0;
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
    this.until,
    this.waiting = false,
    this.redelivery = false,
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
      until: _at(j['until']),
      waiting: j['waiting'] as bool? ?? false,
      redelivery: j['redelivery'] as bool? ?? false,
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

  /// موعد المؤجّلة، وما لم يُسلَّم ينتظر قرار المعالجة، وما أُعيد إليه بعد قرار (docs/plan/75)
  final DateTime? until;
  final bool waiting;
  final bool redelivery;

  /// «زينب علي · شارع 62»
  String get place => [recipient, if ((landmark ?? '').isNotEmpty) landmark!].join(' · ');
}

class TaskGroup {
  TaskGroup({this.governorate = '—', this.city, this.label, required this.items});

  factory TaskGroup.fromJson(Map<String, dynamic> j) => TaskGroup(
    governorate: j['governorate'] as String? ?? '—',
    city: j['city'] as String?,
    label: j['title'] as String?,
    items: [for (final r in _list(j['items'])) TaskRow.fromJson(r)],
  );

  final String governorate;
  final String? city;

  /// المؤجل بموعده والراجع بحاله: عنوانٌ من النظام بدل المحافظة
  final String? label;
  final List<TaskRow> items;

  String get title => label ?? (city == null ? governorate : '$governorate — $city');
}

class TaskList {
  TaskList({
    required this.kind,
    required this.count,
    required this.toCollect,
    required this.groups,
    this.counts = const {},
  });

  factory TaskList.fromJson(Map<String, dynamic> j) => TaskList(
    kind: j['kind'] as String? ?? 'delivery',
    count: _int(j['count']),
    toCollect: _int(j['to_collect']),
    groups: [for (final g in _list(j['groups'])) TaskGroup.fromJson(g)],
    counts: {for (final e in ((j['counts'] as Map?) ?? const {}).entries) '${e.key}': _int(e.value)},
  );

  final String kind;
  final int count;
  final int toCollect;
  final List<TaskGroup> groups;

  /// عدد كلّ خانة: التوصيل، وإعادة التوصيل، والمؤجل، والراجع
  final Map<String, int> counts;
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
    this.needsScan = false,
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
    this.until,
    this.canResume = false,
    this.waiting = false,
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
      needsScan: j['needs_scan'] as bool? ?? false,
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
      until: _at(j['until']),
      canResume: j['can_resume'] as bool? ?? false,
      waiting: j['waiting'] as bool? ?? false,
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

  /// يُمسح الطرد قبل أيّ تسجيلٍ عند الباب (docs/plan/72)
  final bool needsScan;
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

  /// المؤجّلة بموعدها، و«أوصلها الآن» لها وهي بيده، وما لم يُسلَّم ينتظر المعالجة (docs/plan/75)
  final DateTime? until;
  final bool canResume;
  final bool waiting;

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

// ------------------------------------------------------------- «اليوم» والاستلام والحصص والجرس والمكتب (docs/plan/71)

DateTime? _at(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

/// سطرٌ في «اليوم»: الشحنة بحالها، وما حصّله منها، ومتى
class TodayRow {
  TodayRow({required this.row, this.governorate, this.collected = 0, this.at});

  factory TodayRow.fromJson(Map<String, dynamic> j) => TodayRow(
    row: TaskRow.fromJson(j),
    governorate: j['governorate'] as String?,
    collected: _int(j['collected']),
    at: _at(j['at']),
  );

  final TaskRow row;
  final String? governorate;
  final int collected;
  final DateTime? at;
}

class TodayPage {
  TodayPage({
    required this.delivered,
    required this.notDelivered,
    required this.postponed,
    required this.collected,
    required this.rows,
  });

  factory TodayPage.fromJson(Map<String, dynamic> j) => TodayPage(
    delivered: _int(j['delivered']),
    notDelivered: _int(j['not_delivered']),
    postponed: _int(j['postponed']),
    collected: _int(j['collected']),
    rows: [for (final r in _list(j['data'])) TodayRow.fromJson(r)],
  );

  final int delivered;
  final int notDelivered;
  final int postponed;
  final int collected;
  final List<TodayRow> rows;
}

/// طلب استلامٍ مُسنَدٌ لمندوب الاستلام
class PickupItem {
  PickupItem({
    required this.id,
    required this.number,
    required this.merchant,
    required this.expected,
    this.address,
    this.landmark,
    this.notes,
    this.phone,
    this.at,
  });

  factory PickupItem.fromJson(Map<String, dynamic> j) => PickupItem(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    merchant: j['merchant'] as String? ?? '',
    expected: _int(j['expected']),
    address: j['address'] as String?,
    landmark: j['landmark'] as String?,
    notes: j['notes'] as String?,
    phone: j['phone'] as String?,
    at: _at(j['at']),
  );

  final int id;
  final String number;
  final String merchant;
  final int expected;
  final String? address;
  final String? landmark;
  final String? notes;
  final String? phone;
  final DateTime? at;
}

/// دفعة ربحٍ لمندوب الاستلام: يؤكّد وصولها
class Payout {
  Payout({
    required this.id,
    required this.number,
    required this.paid,
    this.earned = 0,
    this.centre = 0,
    this.confirmed = false,
    this.date,
  });

  factory Payout.fromJson(Map<String, dynamic> j) => Payout(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    paid: _int(j['paid']),
    earned: _int(j['earned']),
    centre: _int(j['centre']),
    confirmed: j['confirmed'] as bool? ?? false,
    date: j['date'] as String?,
  );

  final int id;
  final String number;
  final int paid;
  final int earned;
  final int centre;
  bool confirmed;
  final String? date;
}

/// حصّته من طلب استلام: العدد × السعر، ويعترض على العدد ما لم يُبتّ فيها
class Share {
  Share({
    required this.id,
    this.pickup,
    required this.count,
    required this.rate,
    required this.amount,
    required this.status,
    required this.statusLabel,
    this.note,
    this.canObject = false,
    this.date,
  });

  factory Share.fromJson(Map<String, dynamic> j) => Share(
    id: _int(j['id']),
    pickup: j['pickup'] as String?,
    count: _int(j['count']),
    rate: _int(j['rate']),
    amount: _int(j['amount']),
    status: j['status'] as String? ?? '',
    statusLabel: j['status_label'] as String? ?? '',
    note: j['note'] as String?,
    canObject: j['can_object'] as bool? ?? false,
    date: j['date'] as String?,
  );

  final int id;
  final String? pickup;
  final int count;
  final int rate;
  final int amount;
  final String status;
  final String statusLabel;
  final String? note;
  final bool canObject;
  final String? date;
}

class SharesPage {
  SharesPage({required this.balance, required this.payouts, required this.shares});

  factory SharesPage.fromJson(Map<String, dynamic> j) => SharesPage(
    balance: _int(j['balance']),
    payouts: [for (final p in _list(j['payouts'])) Payout.fromJson(p)],
    shares: [for (final s in _list(j['shares'])) Share.fromJson(s)],
  );

  final int balance;
  final List<Payout> payouts;
  final List<Share> shares;
}

/// إعلانٌ من الشركة في الجرس
class Notice {
  Notice({required this.id, required this.title, required this.body, this.fresh = false, this.at});

  factory Notice.fromJson(Map<String, dynamic> j) => Notice(
    id: _int(j['id']),
    title: j['title'] as String? ?? '',
    body: j['body'] as String? ?? '',
    fresh: j['fresh'] as bool? ?? false,
    at: _at(j['at']),
  );

  final int id;
  final String title;
  final String body;
  final bool fresh;
  final DateTime? at;
}

/// رسالةٌ في المحادثة مع المكتب — ورقم الشحنة يفتحها إن كانت شحنته
class ChatMessage {
  ChatMessage({
    required this.id,
    required this.mine,
    required this.author,
    required this.body,
    this.shipmentId,
    this.shipmentNumber,
    this.recipient,
    this.at,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> j) {
    final shipment = (j['shipment'] as Map?)?.cast<String, dynamic>();
    return ChatMessage(
      id: _int(j['id']),
      mine: j['mine'] as bool? ?? false,
      author: j['author'] as String? ?? '',
      body: j['body'] as String? ?? '',
      shipmentId: shipment?['id'] as int?,
      shipmentNumber: shipment?['number'] as String?,
      recipient: shipment?['recipient'] as String?,
      at: _at(j['at']),
    );
  }

  final int id;
  final bool mine;
  final String author;
  final String body;
  final int? shipmentId;
  final String? shipmentNumber;
  final String? recipient;
  final DateTime? at;
}

// ------------------------------------------------------- مسح الشحنة عند الاستلام والتسليم (docs/plan/72)

class PickupsPage {
  PickupsPage({required this.items, this.scanRequired = false});

  factory PickupsPage.fromJson(Map<String, dynamic> j) => PickupsPage(
    items: [for (final p in _list(j['data'])) PickupItem.fromJson(p)],
    scanRequired: j['scan_required'] as bool? ?? false,
  );

  final List<PickupItem> items;

  /// يُستلم بمسح كلّ شحنة: ما مُسح وحده
  final bool scanRequired;
}

/// شحنةٌ مسحها مندوب الاستلام عند التاجر
class ScannedParcel {
  ScannedParcel({required this.id, required this.number, this.recipient, this.amount = 0});

  factory ScannedParcel.fromJson(Map<String, dynamic> j) => ScannedParcel(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    recipient: j['recipient'] as String?,
    amount: _int(j['amount']),
  );

  final int id;
  final String number;
  final String? recipient;
  final int amount;
}
