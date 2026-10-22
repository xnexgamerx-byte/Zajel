// ما تعيده واجهة الكول سنتر (/api/v1/callcenter/…، docs/plan/73) — بأسماء حقولها في النظام نفسها.

int _int(Object? v) => v is int ? v : (v is num ? v.toInt() : int.tryParse('$v') ?? 0);

List<Map<String, dynamic>> _list(Object? v) => [for (final e in (v as List?) ?? const []) (e as Map).cast()];

Map<String, dynamic> _map(Object? v) => (v as Map?)?.cast<String, dynamic>() ?? const {};

DateTime? _at(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

/// الموظّفة الداخلة وشركتها (/me و/login)
class Session {
  Session({required this.name, required this.companyName, this.companyColor, this.initial = ''});

  factory Session.fromJson(Map<String, dynamic> j) {
    final company = _map(j['company']);
    return Session(
      name: _map(j['user'])['name'] as String? ?? '',
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

/// من تعالج الطلب الآن (docs/plan/73) — null: لا أحد، فتأخذه من تفتحه
class ClaimInfo {
  ClaimInfo({required this.by, required this.mine, required this.text, this.since});

  static ClaimInfo? fromJson(Object? v) {
    if (v is! Map) return null;
    final j = v.cast<String, dynamic>();
    return ClaimInfo(
      by: j['by'] as String? ?? '',
      mine: j['mine'] as bool? ?? false,
      text: j['text'] as String? ?? '',
      since: _at(j['since']),
    );
  }

  final String by;
  final bool mine;
  final String text;
  final DateTime? since;

  /// لزميلتها: تُقرأ ولا تُعالَج
  bool get other => !mine;
}

/// اسمٌ وهاتف: المندوب أو التاجر — يُتّصل به من زرّه
class Party {
  Party({this.name, this.phone});

  factory Party.fromJson(Object? v) {
    final j = _map(v);
    return Party(name: j['name'] as String?, phone: j['phone'] as String?);
  }

  final String? name;
  final String? phone;
}

/// طابورٌ في الرئيسية: كم ينتظر، وأقدمه منذ كم، وما تأخّر منه
class QueueInfo {
  QueueInfo({required this.count, this.oldestMinutes = 0, this.late = 0, this.free});

  static QueueInfo? fromJson(Object? v) {
    if (v is! Map) return null;
    final j = v.cast<String, dynamic>();
    return QueueInfo(
      count: _int(j['count']),
      oldestMinutes: _int(j['oldest_minutes']),
      late: _int(j['late']),
      free: j['free'] == null ? null : _int(j['free']),
    );
  }

  final int count;
  final int oldestMinutes;
  final int late;

  /// ما لم تفتحه أيّ زميلة بعد
  final int? free;
}

/// ما تعالجه هي الآن — تعود إليه من الرئيسية
class MyClaim {
  MyClaim({required this.subject, required this.id, required this.title, this.since});

  factory MyClaim.fromJson(Map<String, dynamic> j) => MyClaim(
    subject: j['subject'] as String? ?? '',
    id: _int(j['id']),
    title: j['title'] as String? ?? '',
    since: _at(j['since']),
  );

  /// ticket · processing · conversation
  final String subject;
  final int id;
  final String title;
  final DateTime? since;
}

class HomeData {
  HomeData({
    required this.greeting,
    required this.name,
    this.governorates = const [],
    this.tickets,
    this.processing,
    this.conversations,
    this.couriers,
    this.mine = const [],
    this.todayTickets = 0,
    this.todayProcessed = 0,
  });

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final q = _map(j['queues']);
    final today = _map(j['today']);
    return HomeData(
      greeting: j['greeting'] as String? ?? '',
      name: j['name'] as String? ?? '',
      governorates: [for (final g in (j['governorates'] as List?) ?? const []) '$g'],
      tickets: QueueInfo.fromJson(q['tickets']),
      processing: QueueInfo.fromJson(q['processing']),
      conversations: QueueInfo.fromJson(q['conversations']),
      couriers: QueueInfo.fromJson(q['couriers']),
      mine: [for (final m in _list(j['mine'])) MyClaim.fromJson(m)],
      todayTickets: _int(today['tickets']),
      todayProcessed: _int(today['processed']),
    );
  }

  final String greeting;
  final String name;

  /// محافظات اختصاصها — فارغةٌ: كلّها
  final List<String> governorates;

  /// null: ليس من صلاحيتها
  final QueueInfo? tickets;
  final QueueInfo? processing;
  final QueueInfo? conversations;
  final QueueInfo? couriers;
  final List<MyClaim> mine;
  final int todayTickets;
  final int todayProcessed;
}

/// «طلب تغيير المبلغ» من المندوب عند الباب (docs/plan/30)
class TicketItem {
  TicketItem({
    required this.id,
    required this.number,
    required this.kind,
    required this.kindLabel,
    required this.current,
    required this.requested,
    required this.reason,
    required this.waitingMinutes,
    this.shipmentId,
    this.shipmentNumber,
    this.recipient,
    this.phone,
    this.place = '',
    Party? courier,
    Party? merchant,
    this.claim,
    this.effect,
  }) : courier = courier ?? Party(),
       merchant = merchant ?? Party();

  factory TicketItem.fromJson(Map<String, dynamic> j) {
    final s = _map(j['shipment']);
    return TicketItem(
      id: _int(j['id']),
      number: j['number'] as String? ?? '',
      kind: j['kind'] as String? ?? '',
      kindLabel: j['kind_label'] as String? ?? '',
      current: _int(j['current']),
      requested: _int(j['requested']),
      reason: j['reason'] as String? ?? '',
      waitingMinutes: _int(j['waiting_minutes']),
      shipmentId: s['id'] as int?,
      shipmentNumber: s['number'] as String?,
      recipient: s['recipient'] as String?,
      phone: s['phone'] as String?,
      place: s['place'] as String? ?? '',
      courier: Party.fromJson(j['courier']),
      merchant: Party.fromJson(j['merchant']),
      claim: ClaimInfo.fromJson(j['claim']),
      effect: j['effect'] as String?,
    );
  }

  final int id;
  final String number;

  /// price (السعر تغيّر) · partial (أخذ جزءاً)
  final String kind;
  final String kindLabel;
  final int current;
  final int requested;
  final String reason;
  final int waitingMinutes;
  final int? shipmentId;
  final String? shipmentNumber;
  final String? recipient;
  final String? phone;
  final String place;
  final Party courier;
  final Party merchant;
  final ClaimInfo? claim;
  final String? effect;

  /// المندوب عند الباب: بعد ربع ساعة يحمرّ
  bool get late => waitingMinutes >= 15;
}

/// سطرٌ في محادثة: التاجر أو المندوب
class ChatLine {
  ChatLine({required this.mine, required this.author, required this.body, this.at, this.file});

  factory ChatLine.fromJson(Map<String, dynamic> j) => ChatLine(
    mine: j['mine'] as bool? ?? false,
    author: j['author'] as String? ?? '',
    body: j['body'] as String? ?? '',
    at: _at(j['at']),
    file: j['file'] as String?,
  );

  final bool mine;
  final String author;
  final String body;
  final DateTime? at;
  final String? file;
}

/// شحنةٌ للمعالجة: محاولةٌ فاشلة تنتظر قراراً
class CaseItem {
  CaseItem({
    required this.id,
    required this.number,
    required this.recipient,
    required this.phone,
    this.place = '',
    this.amount = 0,
    this.reason,
    this.attempts = 1,
    this.waitingMinutes = 0,
    this.waitingOnUs = false,
    Party? merchant,
    Party? courier,
    this.claim,
    this.address,
    this.landmark,
    this.phoneAlt,
    this.notes,
    this.myNote,
    this.canChat = false,
    this.canAsk = false,
    this.merchantChat = const [],
    this.courierChat = const [],
  }) : merchant = merchant ?? Party(),
       courier = courier ?? Party();

  factory CaseItem.fromJson(Map<String, dynamic> j) => CaseItem(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    recipient: j['recipient'] as String? ?? '',
    phone: j['phone'] as String? ?? '',
    place: j['place'] as String? ?? '',
    amount: _int(j['amount']),
    reason: j['reason'] as String?,
    attempts: _int(j['attempts']),
    waitingMinutes: _int(j['waiting_minutes']),
    waitingOnUs: j['waiting_on_us'] as bool? ?? false,
    merchant: Party.fromJson(j['merchant']),
    courier: Party.fromJson(j['courier']),
    claim: ClaimInfo.fromJson(j['claim']),
    address: j['address'] as String?,
    landmark: j['landmark'] as String?,
    phoneAlt: j['phone_alt'] as String?,
    notes: j['notes'] as String?,
    myNote: j['my_note'] as String?,
    canChat: j['can_chat'] as bool? ?? false,
    canAsk: j['can_ask'] as bool? ?? false,
    merchantChat: [for (final m in _list(j['merchant_chat'])) ChatLine.fromJson(m)],
    courierChat: [for (final m in _list(j['courier_chat'])) ChatLine.fromJson(m)],
  );

  final int id;
  final String number;
  final String recipient;
  final String phone;
  final String place;
  final int amount;
  final String? reason;
  final int attempts;
  final int waitingMinutes;

  /// كتب التاجر أو المندوب عنها، أو لها تذكرةٌ مفتوحة: تُقدَّم
  final bool waitingOnUs;
  final Party merchant;
  final Party courier;
  final ClaimInfo? claim;
  final String? address;
  final String? landmark;
  final String? phoneAlt;
  final String? notes;
  final String? myNote;
  final bool canChat;
  final bool canAsk;
  final List<ChatLine> merchantChat;
  final List<ChatLine> courierChat;

  /// يومٌ كامل بلا قرار: يحمرّ
  bool get late => waitingMinutes >= 24 * 60;
}

/// محادثة تاجرٍ مع الشركة
class ConversationItem {
  ConversationItem({
    required this.id,
    required this.subject,
    Party? merchant,
    this.shipment,
    this.open = true,
    this.waiting = false,
    this.unread = false,
    this.waitingMinutes = 0,
    this.claim,
    this.messages = const [],
  }) : merchant = merchant ?? Party();

  factory ConversationItem.fromJson(Map<String, dynamic> j) => ConversationItem(
    id: _int(j['id']),
    subject: j['subject'] as String? ?? '',
    merchant: Party.fromJson(j['merchant']),
    shipment: j['shipment'] as String?,
    open: j['open'] as bool? ?? true,
    waiting: j['waiting'] as bool? ?? false,
    unread: j['unread'] as bool? ?? false,
    waitingMinutes: _int(j['waiting_minutes']),
    claim: ClaimInfo.fromJson(j['claim']),
    messages: [for (final m in _list(j['messages'])) ChatLine.fromJson(m)],
  );

  final int id;
  final String subject;
  final Party merchant;
  final String? shipment;
  final bool open;

  /// آخر ما فيها من التاجر: ينتظر ردّنا
  final bool waiting;
  final bool unread;
  final int waitingMinutes;
  final ClaimInfo? claim;
  final List<ChatLine> messages;
}

/// نتيجة البحث: «الزبون اتّصل بخصوص الوصل كذا»
class FoundShipment {
  FoundShipment({
    required this.id,
    required this.number,
    required this.recipient,
    this.phone,
    this.place = '',
    this.merchant,
    required this.status,
    required this.statusLabel,
    this.amount = 0,
  });

  factory FoundShipment.fromJson(Map<String, dynamic> j) => FoundShipment(
    id: _int(j['id']),
    number: j['number'] as String? ?? '',
    recipient: j['recipient'] as String? ?? '',
    phone: j['phone'] as String?,
    place: j['place'] as String? ?? '',
    merchant: j['merchant'] as String?,
    status: j['status'] as String? ?? '',
    statusLabel: j['status_label'] as String? ?? '',
    amount: _int(j['amount']),
  );

  final int id;
  final String number;
  final String recipient;
  final String? phone;
  final String place;
  final String? merchant;
  final String status;
  final String statusLabel;
  final int amount;
}

/// سطرٌ في سجلّ الشحنة
class EventLine {
  EventLine({required this.status, this.note, this.by, this.at});

  factory EventLine.fromJson(Map<String, dynamic> j) => EventLine(
    status: j['status'] as String? ?? '',
    note: j['note'] as String?,
    by: j['by'] as String?,
    at: _at(j['at']),
  );

  final String status;
  final String? note;
  final String? by;
  final DateTime? at;
}

class ShipmentDetail {
  ShipmentDetail({
    required this.found,
    this.phoneAlt,
    this.address,
    this.landmark,
    this.notes,
    this.reason,
    this.attempts = 0,
    Party? merchant,
    Party? courier,
    this.processing = false,
    this.events = const [],
  }) : merchant = merchant ?? Party(),
       courier = courier ?? Party();

  factory ShipmentDetail.fromJson(Map<String, dynamic> j) => ShipmentDetail(
    found: FoundShipment.fromJson({...j, 'merchant': _map(j['merchant'])['name']}),
    phoneAlt: j['phone_alt'] as String?,
    address: j['address'] as String?,
    landmark: j['landmark'] as String?,
    notes: j['notes'] as String?,
    reason: j['reason'] as String?,
    attempts: _int(j['attempts']),
    merchant: Party.fromJson(j['merchant']),
    courier: Party.fromJson(j['courier']),
    processing: j['processing'] as bool? ?? false,
    events: [for (final e in _list(j['events'])) EventLine.fromJson(e)],
  );

  final FoundShipment found;
  final String? phoneAlt;
  final String? address;
  final String? landmark;
  final String? notes;
  final String? reason;
  final int attempts;
  final Party merchant;
  final Party courier;

  /// في المعالجة الآن: تُفتح منها
  final bool processing;
  final List<EventLine> events;
}
