import 'config.dart';

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

/// «ملاحظة للموظّفين» على الشحنة (docs/plan/83): «اتّصلتُ ولم يردّ» — من كتبها ومتى، وتراها الزميلات
class StaffNoteInfo {
  StaffNoteInfo({required this.text, this.by, this.at});

  static StaffNoteInfo? fromJson(Object? v) {
    if (v is! Map) return null;
    final j = v.cast<String, dynamic>();
    final text = j['text'] as String? ?? '';
    return text.isEmpty ? null : StaffNoteInfo(text: text, by: j['by'] as String?, at: _at(j['at']));
  }

  final String text;
  final String? by;
  final DateTime? at;
}

/// «رسالتي الثابتة للتاجر» (docs/plan/83): نصّها نفسه في الموقع، والقالب، وخاناته
class MyMessage {
  MyMessage({required this.text, this.custom = false, this.template = '', this.fields = const []});

  factory MyMessage.fromJson(Map<String, dynamic> j) => MyMessage(
    text: j['text'] as String? ?? '',
    custom: j['custom'] as bool? ?? false,
    template: j['template'] as String? ?? '',
    fields: [for (final f in _list(j['fields'])) (f['key'] as String? ?? '', f['meaning'] as String? ?? '')],
  );

  final String text;

  /// كتبت نصّها هي — لا القالب
  final bool custom;
  final String template;

  /// الخانة وما تُملأ به: ({التاجر}، اسم التاجر)…
  final List<(String, String)> fields;
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
    this.todayActions = const {},
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
      todayActions: {for (final e in _map(today['actions']).entries) e.key: _int(e.value)},
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

  /// ما عالجته اليوم بأنواعه (docs/plan/80): redeliver · postpone · return
  final Map<String, int> todayActions;
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
    this.finalize = false,
    this.returnedPieces,
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
      finalize: j['finalize'] as bool? ?? false,
      returnedPieces: j['returned_pieces'] == null ? null : _int(j['returned_pieces']),
    );
  }

  /// سلّمها المندوب وينتظر (docs/plan/80): اعتمادها يسجّلها واصلة
  final bool finalize;

  /// القطع التي رجعت معه في الواصل الجزئي
  final int? returnedPieces;

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
  ChatLine({required this.mine, required this.author, required this.body, this.at, this.file, this.shipment});

  factory ChatLine.fromJson(Map<String, dynamic> j) => ChatLine(
    mine: j['mine'] as bool? ?? false,
    author: j['author'] as String? ?? '',
    body: j['body'] as String? ?? '',
    at: _at(j['at']),
    file: j['file'] is Map ? ChatFile.fromJson((j['file'] as Map).cast()) : null,
    shipment: j['shipment'] as String?,
  );

  /// رسالة المندوب عن شحنةٍ برقمها (docs/plan/81)
  final String? shipment;

  final bool mine;
  final String author;
  final String body;
  final DateTime? at;
  final ChatFile? file;
}

/// صورة التاجر أو ملفّه في المحادثة (docs/plan/74): تُفتح برمز الموظّفة
class ChatFile {
  ChatFile({required this.name, this.image = false, this.size = '', this.url = ''});

  factory ChatFile.fromJson(Map<String, dynamic> j) => ChatFile(
    name: j['name'] as String? ?? 'ملف',
    image: j['image'] as bool? ?? false,
    size: j['size'] as String? ?? '',
    url: media(j['url'] as String? ?? ''),
  );

  final String name;
  final bool image;
  final String size;
  final String url;
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
    this.reasonNote,
    this.description,
    this.pieces = 1,
    this.staffNote,
    this.merchantMessage,
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
    reasonNote: j['reason_note'] as String?,
    description: j['description'] as String?,
    pieces: j['pieces'] == null ? 1 : _int(j['pieces']),
    staffNote: StaffNoteInfo.fromJson(j['staff_note']),
    merchantMessage: j['merchant_message'] as String?,
  );

  /// ملاحظة للموظّفين، في السطر كـ«تعالجها أنت» (docs/plan/83)
  final StaffNoteInfo? staffNote;

  /// رسالتي الثابتة مملوءةً منها، تُدرَج فوق حقل التاجر (docs/plan/83)
  final String? merchantMessage;

  /// التوضيح المكتوب مع السبب (docs/plan/80): «تغيير عنوان — إلى الكرادة…»
  final String? reasonNote;
  final String? description;
  final int pieces;

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
    this.merchantMessage,
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
    merchantMessage: j['merchant_message'] as String?,
  );

  /// رسالتي الثابتة مملوءةً من التاجر وشحنة المحادثة (docs/plan/83)
  final String? merchantMessage;

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
    this.courier,
    this.reason,
    this.until,
    this.withCourier,
    this.since,
    this.staffNote,
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
    courier: j['courier'] is String ? j['courier'] as String : null,
    reason: j['reason'] as String?,
    until: _at(j['until']),
    withCourier: j['with_courier'] as bool?,
    since: _at(j['since']),
    staffNote: StaffNoteInfo.fromJson(j['staff_note']),
  );

  /// ملاحظة للموظّفين (docs/plan/83): من اتّصل قبلها وماذا قيل
  final StaffNoteInfo? staffNote;

  final int id;
  final String number;
  final String recipient;
  final String? phone;
  final String place;
  final String? merchant;
  final String status;
  final String statusLabel;
  final int amount;

  /// في خانات «حالة الشحنات» (docs/plan/80): مندوبها، وآخر سبب، وموعد المؤجّلة، والراجع بيد المندوب أم بالمخزن
  final String? courier;
  final String? reason;
  final DateTime? until;
  final bool? withCourier;
  final DateTime? since;
}

/// خانةٌ من «حالة الشحنات» (docs/plan/80): in_delivery · postponed · redelivery · returns
class BoardKind {
  BoardKind({required this.kind, required this.label, required this.count});

  factory BoardKind.fromJson(Map<String, dynamic> j) =>
      BoardKind(kind: j['kind'] as String? ?? '', label: j['label'] as String? ?? '', count: _int(j['count']));

  final String kind;
  final String label;
  final int count;
}

/// ما في الخانة، وعددها كلّها
class BoardPage {
  BoardPage({required this.kind, required this.label, required this.count, required this.items});

  factory BoardPage.fromJson(Map<String, dynamic> j) => BoardPage(
    kind: j['kind'] as String? ?? '',
    label: j['label'] as String? ?? '',
    count: _int(j['count']),
    items: [for (final s in _list(j['data'])) FoundShipment.fromJson(s)],
  );

  final String kind;
  final String label;
  final int count;
  final List<FoundShipment> items;
}

/// سطرٌ في «أنجزتِ اليوم» (docs/plan/80): قرار معالجة، أو تذكرة مبلغٍ حسمتها
class DoneItem {
  DoneItem({
    required this.id,
    this.shipmentId,
    this.number,
    this.recipient,
    required this.title,
    this.action,
    this.label = '',
    this.note,
    this.by,
    this.at,
  });

  factory DoneItem.fromJson(Map<String, dynamic> j) => DoneItem(
    id: _int(j['id']),
    shipmentId: j['shipment_id'] == null ? null : _int(j['shipment_id']),
    number: j['number'] as String?,
    recipient: j['recipient'] as String?,
    title: j['title'] as String? ?? '',
    action: j['action'] as String?,
    label: j['label'] as String? ?? '',
    note: j['note'] as String?,
    by: j['by'] as String?,
    at: _at(j['at']),
  );

  final int id;
  final int? shipmentId;
  final String? number;
  final String? recipient;
  final String title;

  /// redeliver · postpone · return — أو approved · rejected للتذاكر
  final String? action;
  final String label;
  final String? note;
  final String? by;
  final DateTime? at;
}

/// تحديثٌ تختاره الموظّفة لشحنة: الحالة وما تحتاجه — سبب، أو مبلغ، أو مندوب
class Transition {
  Transition({required this.value, required this.label, this.needs = const []});

  factory Transition.fromJson(Map<String, dynamic> j) => Transition(
    value: j['value'] as String? ?? '',
    label: j['label'] as String? ?? '',
    needs: [for (final n in (j['needs'] as List?) ?? const []) '$n'],
  );

  final String value;
  final String label;

  /// reason · amount · courier
  final List<String> needs;
}

/// سبب عدم التسليم، وهل يحتاج توضيحاً
class ReasonOption {
  ReasonOption({required this.id, required this.name, this.note = false});

  factory ReasonOption.fromJson(Map<String, dynamic> j) =>
      ReasonOption(id: _int(j['id']), name: j['name'] as String? ?? '', note: j['note'] as bool? ?? false);

  final int id;
  final String name;
  final bool note;
}

/// مندوبٌ يُختار للتوصيل
class CourierOption {
  CourierOption({required this.id, required this.name});

  factory CourierOption.fromJson(Map<String, dynamic> j) =>
      CourierOption(id: _int(j['id']), name: j['name'] as String? ?? '');

  final int id;
  final String name;
}

/// سطرٌ في سجلّ الشحنة
class EventLine {
  EventLine({required this.status, this.note, this.by, this.at, this.type = 'status_change'});

  factory EventLine.fromJson(Map<String, dynamic> j) => EventLine(
    status: j['status'] as String? ?? '',
    note: j['note'] as String?,
    by: j['by'] as String?,
    at: _at(j['at']),
    type: j['type'] as String? ?? 'status_change',
  );

  /// الحالة، أو نوع الحدث لما لا يغيّرها: «تعديل بيانات»، «طلب تغيير المبلغ»…
  final String status;

  /// status_change · edited · ticket · processed…
  final String type;
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
    this.reasonNote,
    this.until,
    this.pieces = 1,
    this.description,
    this.transitions = const [],
    this.reasons = const [],
    this.couriers = const [],
    this.canReopen = false,
    this.canReschedule = false,
    this.canChat = false,
    this.canAsk = false,
    this.hasCourier = false,
    this.merchantChat = const [],
    this.courierChat = const [],
    this.merchantMessage,
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
    reasonNote: j['reason_note'] as String?,
    until: _at(j['until']),
    pieces: j['pieces'] == null ? 1 : _int(j['pieces']),
    description: j['description'] as String?,
    transitions: [for (final t in _list(j['transitions'])) Transition.fromJson(t)],
    reasons: [for (final r in _list(j['reasons'])) ReasonOption.fromJson(r)],
    couriers: [for (final c in _list(j['couriers'])) CourierOption.fromJson(c)],
    canReopen: j['can_reopen'] as bool? ?? false,
    canReschedule: j['can_reschedule'] as bool? ?? false,
    canChat: j['can_chat'] as bool? ?? false,
    canAsk: j['can_ask'] as bool? ?? false,
    hasCourier: j['has_courier'] as bool? ?? false,
    merchantChat: [for (final m in _list(j['merchant_chat'])) ChatLine.fromJson(m)],
    courierChat: [for (final m in _list(j['courier_chat'])) ChatLine.fromJson(m)],
    merchantMessage: j['merchant_message'] as String?,
  );

  /// رسالتي الثابتة مملوءةً منها، تُدرَج فوق حقل التاجر (docs/plan/83)
  final String? merchantMessage;

  /// ملاحظة للموظّفين (docs/plan/83)
  StaffNoteInfo? get staffNote => found.staffNote;

  /// محادثتا التاجر والمندوب عنها، في كلّ شحنة (docs/plan/81)
  final bool canChat;
  final bool canAsk;
  final bool hasCourier;
  final List<ChatLine> merchantChat;
  final List<ChatLine> courierChat;

  /// التوضيح المكتوب مع السبب (docs/plan/80)
  final String? reasonNote;

  /// موعد المؤجّلة
  final DateTime? until;
  final int pieces;
  final String? description;

  /// ما تحدّثها إليه الموظّفة من التطبيق، وما يحتاجه كلٌّ منها
  final List<Transition> transitions;
  final List<ReasonOption> reasons;
  final List<CourierOption> couriers;

  /// راجعٌ مؤكَّد يعود للتوصيل بسببٍ تكتبه
  final bool canReopen;

  /// المؤجّلة: يتغيّر موعدها وتبقى «مؤجل» (docs/plan/83)
  final bool canReschedule;

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

/// محادثة مندوبٍ في «رسائل المناديب» (docs/plan/81)
class CourierThreadRow {
  CourierThreadRow({
    required this.id,
    required this.name,
    this.phone,
    this.unread = false,
    this.waiting = false,
    this.last,
    this.waitingMinutes = 0,
  });

  factory CourierThreadRow.fromJson(Map<String, dynamic> j) => CourierThreadRow(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String?,
    unread: j['unread'] as bool? ?? false,
    waiting: j['waiting'] as bool? ?? false,
    last: j['last'] as String?,
    waitingMinutes: _int(j['waiting_minutes']),
  );

  /// رقم المندوب
  final int id;
  final String name;
  final String? phone;
  final bool unread;

  /// آخر ما فيها منه: ينتظر ردّنا
  final bool waiting;
  final String? last;
  final int waitingMinutes;
}

class CourierThread {
  CourierThread({required this.id, required this.name, this.phone, this.messages = const []});

  factory CourierThread.fromJson(Map<String, dynamic> j) => CourierThread(
    id: _int(j['id']),
    name: j['name'] as String? ?? '',
    phone: j['phone'] as String?,
    messages: [for (final m in _list(j['messages'])) ChatLine.fromJson(m)],
  );

  final int id;
  final String name;
  final String? phone;
  final List<ChatLine> messages;
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

  Map<String, dynamic> toJson() => {'name': name, 'initial': initial, 'color': color, 'logo': logo};
}
