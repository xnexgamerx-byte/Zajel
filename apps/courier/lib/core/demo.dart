import 'api.dart';
import 'models.dart';
import 'palette.dart';

/// نسخة العرض (config/demo.json): بياناتٌ وهميّة للاختبارات ومطابقة التصميم — لا نظام ولا زبائن حقيقيّون.
///
/// تتبع قواعد النظام نفسها حتى تُختبر الشاشات عليها: الشحنة بكودٍ لا تُسلَّم بلا كودها، والسبب
/// إلزامي، وبعض الأسباب تحتاج توضيحاً، والواصل الجزئي بعد اعتماد الكول سنتر وحده.
class Demo {
  static final session = Session(name: 'مندوب تجريبي', companyName: 'الزاجل', initial: 'ز');

  static const _code = '4821';
  static int _tickets = 0;

  static List<_Parcel> _parcels = _seed();
  static int _cash = 2340000;
  static int _commission = 180000;
  static int _delivered = 9;
  static int _failed = 2;
  static int _postponed = 0;
  static final List<Movement> _moves = _seedMoves();

  /// يعيد العرض إلى أوّله — بين اختبارٍ وآخر
  /// «مسح الشحنة عند الاستلام والتسليم» (docs/plan/72): مطفأةٌ في العرض إلّا حين يفتحها اختبار
  static bool scanParcels = false;

  static void reset() {
    scanParcels = false;
    _parcels = _seed();
    _cash = 2340000;
    _commission = 180000;
    _delivered = 9;
    _failed = 2;
    _postponed = 0;
    _tickets = 0;
    _moves
      ..clear()
      ..addAll(_seedMoves());
    _pickups = _seedPickups();
    _payouts = _seedPayouts();
    _shares = _seedShares();
    _chat = _seedChat();
    _chatUnread = true;
  }

  static List<_Parcel> _seed() => [
    _Parcel(
      180201,
      'زينب علي',
      'الكرادة',
      'شارع 62',
      25000,
      code: true,
      fragile: true,
      note: 'اتصل قبل الوصول بربع ساعة',
    ),
    _Parcel(180203, 'حسين كاظم', 'الكرادة', 'الكرادة داخل', 30000)
      ..ticket = Ticket(
        number: 'TK-000101',
        kind: 'price',
        kindLabel: 'السعر تغيّر',
        status: 'open',
        statusLabel: 'بانتظار الكول سنتر',
        requested: 25000,
      ),
    _Parcel(180207, 'مصطفى عادل', 'الكرادة', 'بجانب الصيدلية', 40000, code: true),
    _Parcel(180209, 'نور الهدى', 'الكرادة', 'الكرادة خارج', 15000),
    _Parcel(180212, 'علي حسن', 'المنصور', 'شارع 14', 20000),
    _Parcel(180215, 'رنا جاسم', 'المنصور', 'قرب الجامع', 35000)
      ..ticket = Ticket(
        number: 'TK-000102',
        kind: 'partial',
        kindLabel: 'أخذ جزءاً وأرجع الباقي',
        status: 'approved',
        statusLabel: 'اعتُمد',
        requested: 20000,
        approved: 20000,
      ),
    _Parcel(180190, 'سارة محمد', 'الأعظمية', 'شارع عشرين', 18000, status: 'returning'),
  ];

  static List<Movement> _seedMoves() => [
    Movement(
      id: 4,
      category: 'cod_collected',
      label: 'تحصيل',
      credit: false,
      amount: 25000,
      shipment: '180198',
      at: _ago(1),
    ),
    Movement(
      id: 3,
      category: 'commission',
      label: 'عمولة',
      credit: true,
      amount: 5000,
      shipment: '180198',
      at: _ago(1),
    ),
    Movement(
      id: 2,
      category: 'cash_handover',
      label: 'تسليم نقد',
      credit: true,
      amount: 500000,
      description: 'تسليم نقد للفرع',
      at: _ago(26),
    ),
    Movement(
      id: 1,
      category: 'cod_collected',
      label: 'تحصيل',
      credit: false,
      amount: 30000,
      shipment: '180195',
      at: _ago(29),
    ),
  ];

  static DateTime _ago(int hours) => DateTime.now().subtract(Duration(hours: hours));

  static Iterable<_Parcel> get _out => _parcels.where((p) => p.status == 'out_for_delivery');
  static Iterable<_Parcel> get _returns =>
      _parcels.where((p) => p.status == 'returning' || p.status == 'partially_delivered');

  static _Parcel _get(int id) =>
      _parcels.firstWhere((p) => p.id == id, orElse: () => throw ApiError('الشحنة ليست بيدك.', notFound: true));

  static CashSummary get _summary => CashSummary(
    inHand: _cash,
    limit: 3000000,
    percent: (_cash * 100 ~/ 3000000).clamp(0, 100),
    limitReached: _cash >= 3000000,
    commission: _commission,
    netDue: _cash - _commission,
  );

  static Future<HomeData> home() async => HomeData(
    greeting: DateTime.now().hour < 12 ? 'صباح الخير' : 'مساء الخير',
    name: session.name,
    unread: 2,
    inHand: _out.length,
    toCollect: _out.fold(0, (s, p) => s + p.amount),
    cash: _summary,
    delivered: _delivered,
    failed: _failed,
    postponed: _postponed,
    returns: _returns.length,
    collected: _collectedToday,
    // مندوب توصيلٍ واستلامٍ معاً: تظهر الشاشات كلّها في العرض
    delivers: true,
    picks: true,
    pickups: _pickups.length,
    chat: true,
    chatUnread: _chatUnread,
    attention: [
      for (final p in _out.where((p) => p.ticket != null && p.ticket!.status != 'rejected'))
        Attention(
          kind: 'ticket',
          id: p.id,
          number: '${p.id}',
          text: p.ticket!.open
              ? 'طلب تغيير المبلغ ينتظر جواب الكول سنتر'
              : 'اعتُمد واصلٌ جزئي بمبلغ ${money(p.ticket!.approved!)}',
        ),
      for (final p in _out.where((p) => p.code))
        Attention(kind: 'code', id: p.id, number: '${p.id}', text: 'يُسلَّم بكود من الزبون'),
      for (final p in _returns)
        Attention(kind: 'return', id: p.id, number: '${p.id}', text: 'راجعٌ بيدك: سلّمه للمخزن'),
    ],
  );

  static Future<TaskList> tasks(String kind, String q) async {
    final source = kind == 'returns' ? _returns : _out;
    final term = q.trim();
    final found = source.where((p) => term.isEmpty || '${p.id}'.contains(term) || p.name.contains(term)).toList();
    final groups = <String, List<_Parcel>>{};
    for (final p in found) {
      groups.putIfAbsent(p.city, () => []).add(p);
    }
    return TaskList(
      kind: kind,
      count: found.length,
      toCollect: kind == 'returns' ? 0 : found.fold(0, (s, p) => s + p.amount),
      groups: [
        for (final e in groups.entries)
          TaskGroup(
            governorate: 'بغداد',
            city: e.key,
            items: [for (final p in e.value) p.row(returning: kind == 'returns')],
          ),
      ],
    );
  }

  static Future<ShipmentDetail> shipment(int id) async => _get(id).detail();

  static Future<int> find(String code) async {
    final digits = RegExp(r'\d{4,}').firstMatch(code)?.group(0) ?? '';
    final hit = _parcels.where((p) => '${p.id}' == digits).firstOrNull;
    if (hit == null) throw ApiError('لا توجد شحنة بيدك بالرقم «$code».', notFound: true);
    return hit.id;
  }

  static final reasons = [
    Reason(id: 1, name: 'لا يرد على الهاتف'),
    Reason(id: 2, name: 'الهاتف مغلق'),
    Reason(id: 3, name: 'الزبون غير موجود بالعنوان'),
    Reason(id: 4, name: 'الزبون طلب التأجيل', requiresNote: true, forPostponed: true),
    Reason(id: 5, name: 'الزبون رفض الاستلام', requiresNote: true),
  ];

  static Future<Outcome> act(int id, String action, {int? reasonId, String? note, String? code, String? scan}) async {
    final p = _get(id);
    if (p.status != 'out_for_delivery') {
      throw _field('action', 'هذه الشحنة لم تعد بيدك.');
    }
    if (scanParcels && (scan ?? '').trim() != '${p.id}') {
      throw _field(
        'scan',
        (scan ?? '').isEmpty
            ? 'امسح باركود الشحنة ${p.id} أو اكتب رقمها قبل التسجيل.'
            : 'ما مسحته ليس الشحنة ${p.id}. امسح الطرد الذي في يدك.',
      );
    }

    if (action == 'failed_attempt' || action == 'postponed') {
      final reason = reasons.where((r) => r.id == reasonId).firstOrNull;
      if (reason == null) {
        throw _field('failure_reason_id', 'اختر السبب — بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.');
      }
      if (reason.requiresNote && (note ?? '').trim().isEmpty) {
        throw _field('note', 'السبب «${reason.name}» يحتاج توضيحاً.');
      }
      p
        ..status = action
        ..failure = reason.name;
      action == 'failed_attempt' ? _failed++ : _postponed++;
    } else {
      final partial = action == 'partially_delivered';
      if (partial && p.ticket?.status != 'approved') {
        throw _field('action', 'الواصل الجزئي بموافقة الكول سنتر: اطلب تغيير المبلغ وانتظر اعتماده.');
      }
      if (p.code && code != _code) {
        throw _field(
          'delivery_code',
          (code ?? '').isEmpty ? 'هذه الشحنة تُسلَّم بكود: اطلبه من الزبون.' : 'كود التسليم غير صحيح.',
        );
      }
      final collected = partial ? p.ticket!.approved! : p.amount;
      p.status = partial ? 'partially_delivered' : 'delivered';
      if (partial) p.ticket = null;
      _cash += collected;
      _commission += 5000;
      _delivered++;
      _moves.insert(
        0,
        Movement(
          id: _moves.length + 1,
          category: 'cod_collected',
          label: 'تحصيل',
          credit: false,
          amount: collected,
          shipment: '${p.id}',
          at: DateTime.now(),
        ),
      );
    }
    return Outcome('سُجِّلت الشحنة ${p.id}: ${p.label}.', p.detail());
  }

  static Future<(String, Ticket)> openTicket(int id, String kind, int amount, String reason) async {
    final p = _get(id);
    if (p.ticket != null) {
      throw _field('requested_amount', 'طلبك ${p.ticket!.number} بانتظار الكول سنتر — انتظر جوابه.');
    }
    if (amount == p.amount) throw _field('requested_amount', 'هذا هو مبلغ الشحنة نفسه.');
    final ticket = Ticket(
      number: 'TK-${(200 + ++_tickets).toString().padLeft(6, '0')}',
      kind: kind,
      kindLabel: kind == 'price' ? 'السعر تغيّر' : 'أخذ جزءاً وأرجع الباقي',
      status: 'open',
      statusLabel: 'بانتظار الكول سنتر',
      requested: amount,
    );
    p.ticket = ticket;
    return ('أُرسل طلبك ${ticket.number} إلى الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.', ticket);
  }

  static Future<CashPage> cash() async => CashPage(
    summary: _summary,
    movements: List.of(_moves),
    settlements: [
      Settlement(
        code: 'CS-000031',
        status: 'paid',
        statusLabel: 'مدفوع',
        shipments: 42,
        net: 1150000,
        date: '2026-10-14',
      ),
    ],
  );

  // ------------------------------------------- «اليوم» والاستلام والحصص والجرس والمكتب (docs/plan/71)

  /// ما سُجّل اليوم قبل فتح العرض: ثلاثٌ سُلّمت وواحدةٌ لم تُسلَّم
  static final _earlier = [
    TodayRow(
      row: TaskRow(
        id: 180180,
        number: '180180',
        status: 'delivered',
        statusLabel: 'واصل',
        recipient: 'هدى كريم',
        city: 'الكرادة',
        amount: 35000,
      ),
      governorate: 'بغداد',
      collected: 35000,
      at: _ago(3),
    ),
    TodayRow(
      row: TaskRow(
        id: 180181,
        number: '180181',
        status: 'delivered',
        statusLabel: 'واصل',
        recipient: 'سيف علي',
        city: 'المنصور',
        amount: 50000,
      ),
      governorate: 'بغداد',
      collected: 50000,
      at: _ago(4),
    ),
    TodayRow(
      row: TaskRow(
        id: 180182,
        number: '180182',
        status: 'failed_attempt',
        statusLabel: 'لم يُسلَّم',
        recipient: 'منى جاسم',
        city: 'الكرادة',
        amount: 20000,
        failure: 'لا يرد على الهاتف',
      ),
      governorate: 'بغداد',
      at: _ago(5),
    ),
  ];

  static int get _collectedToday =>
      _earlier.fold(0, (s, r) => s + r.collected) +
      _parcels.where((p) => p.status == 'delivered').fold(0, (s, p) => s + p.amount);

  static Future<TodayPage> today() async {
    final now = [
      for (final p in _parcels.where((p) => p.status != 'out_for_delivery' && p.status != 'returning'))
        TodayRow(
          row: p.row(),
          governorate: 'بغداد',
          collected: p.status == 'delivered' ? p.amount : 0,
          at: DateTime.now(),
        ),
    ];
    final rows = [...now, ..._earlier];
    return TodayPage(
      delivered: rows.where((r) => r.row.status == 'delivered' || r.row.status == 'partially_delivered').length,
      notDelivered: rows.where((r) => r.row.status == 'failed_attempt').length,
      postponed: rows.where((r) => r.row.status == 'postponed').length,
      collected: rows.fold(0, (s, r) => s + r.collected),
      rows: rows,
    );
  }

  static List<PickupItem> _pickups = _seedPickups();

  static List<PickupItem> _seedPickups() => [
    PickupItem(
      id: 1,
      number: 'PU000119',
      merchant: 'متجر الوردة',
      expected: 12,
      address: 'الكرادة داخل، شارع 62',
      landmark: 'مقابل مطعم الساعة',
      phone: '07700000001',
      at: DateTime.now().add(const Duration(hours: 1)),
    ),
    PickupItem(
      id: 2,
      number: 'PU000121',
      merchant: 'بيت العطور',
      expected: 5,
      address: 'المنصور، شارع 14 رمضان',
      landmark: 'قرب جامع الرحمن',
      notes: 'الطرود في المخزن الخلفي',
      phone: '07700000002',
      at: DateTime.now().add(const Duration(hours: 3)),
    ),
  ];

  static Future<PickupsPage> pickups() async => PickupsPage(items: List.of(_pickups), scanRequired: scanParcels);

  /// شحنات تاجر كلّ طلب: 1901xx للطلب الأوّل، و1902xx للثاني — والـ180xxx شحنات توصيلٍ لا تاجرَ هنا
  static Future<ScannedParcel> pickupScan(int pickupId, String code) async {
    final p = _pickups.firstWhere((p) => p.id == pickupId);
    final n = int.tryParse(RegExp(r'\d+').firstMatch(code)?.group(0) ?? '');
    if (n == null) throw ApiError('امسح باركود الشحنة أو اكتب رقمها.');
    final base = 190000 + pickupId * 100;
    if (n > base && n <= base + p.expected) return ScannedParcel(id: n, number: '$n', recipient: 'زبون ${n - base}');
    if (n >= 190100 && n < 190300) throw ApiError('الشحنة $n ليست لهذا التاجر.');
    throw ApiError('لا شحنة بالرقم «$n».');
  }

  static Future<String> completePickup(int id, int count) async {
    final p = _pickups.firstWhere((p) => p.id == id, orElse: () => throw ApiError('هذا الطلب مُغلق بالفعل.'));
    _pickups.remove(p);
    return 'استُلم ${parcels(count)} من ${p.merchant}.';
  }

  static List<Payout> _payouts = _seedPayouts();

  static List<Payout> _seedPayouts() => [
    Payout(id: 12, number: 'PP-000012', paid: 30000, earned: 30000, date: '2026-10-20'),
    Payout(id: 9, number: 'PP-000009', paid: 22000, earned: 24000, centre: 2000, confirmed: true, date: '2026-10-12'),
  ];

  static List<Share> _shares = _seedShares();

  static List<Share> _seedShares() => [
    Share(
      id: 31,
      pickup: 'PU000118',
      count: 9,
      rate: 1000,
      amount: 9000,
      status: 'accrued',
      statusLabel: 'مستحقّة',
      canObject: true,
      date: '2026-10-21',
    ),
    Share(
      id: 30,
      pickup: 'PU000112',
      count: 6,
      rate: 1000,
      amount: 7000,
      status: 'accepted',
      statusLabel: 'قُبل الاعتراض',
      note: 'طردٌ إضافي سُجّل بعد المراجعة',
      date: '2026-10-19',
    ),
    Share(
      id: 29,
      pickup: 'PU000108',
      count: 11,
      rate: 1000,
      amount: 11000,
      status: 'paid',
      statusLabel: 'مدفوعة',
      date: '2026-10-15',
    ),
  ];

  static Future<SharesPage> shares() async =>
      SharesPage(balance: 42000, payouts: List.of(_payouts), shares: List.of(_shares));

  static Future<String> objectShare(int id, int count, String reason) async {
    if (reason.trim().isEmpty) throw _field('reason', 'حقل السبب مطلوب.');
    final i = _shares.indexWhere((s) => s.id == id);
    final old = _shares[i];
    _shares[i] = Share(
      id: old.id,
      pickup: old.pickup,
      count: old.count,
      rate: old.rate,
      amount: old.amount,
      status: 'objected',
      statusLabel: 'معترَض عليها',
      date: old.date,
    );
    return 'سُجّل اعتراضك. سيُبتّ فيه من إدارة الفرع.';
  }

  static Future<String> confirmPayout(int id) async {
    final p = _payouts.firstWhere((p) => p.id == id);
    if (p.confirmed) throw ApiError('الدفعة ${p.number} مؤكَّدة سلفاً.');
    p.confirmed = true;
    return 'أكّدت استلام الدفعة ${p.number}.';
  }

  static Future<List<Notice>> notifications() async => [
    Notice(
      id: 2,
      title: 'تغيير وقت الاستلام',
      body: 'يبدأ استلام الرواجع في المخزن من الساعة 4 عصراً بدل 5.',
      fresh: true,
      at: _ago(2),
    ),
    Notice(id: 1, title: 'عطلة الجمعة', body: 'لا توصيل يوم الجمعة القادم.', fresh: true, at: _ago(26)),
  ];

  static List<ChatMessage> _chat = _seedChat();
  static bool _chatUnread = true;

  static List<ChatMessage> _seedChat() => [
    ChatMessage(
      id: 1,
      mine: true,
      author: 'أنت',
      body: 'الزبون لا يردّ على الهاتف، أنا عند البيت.',
      shipmentId: 180209,
      shipmentNumber: '180209',
      recipient: 'نور الهدى',
      at: _ago(2),
    ),
    ChatMessage(id: 2, mine: false, author: 'الكول سنتر', body: 'اتصلنا به: ينزل بعد عشر دقائق.', at: _ago(1)),
  ];

  static Future<List<ChatMessage>> chat() async {
    _chatUnread = false;
    return List.of(_chat);
  }

  static Future<void> sendChat(String text, {int? shipmentId}) async {
    if (text.trim().isEmpty) throw _field('body', 'اكتب رسالتك.');
    _chat.add(ChatMessage(id: _chat.length + 1, mine: true, author: 'أنت', body: text.trim(), at: DateTime.now()));
  }

  static ApiError _field(String field, String message) => ApiError(message, fields: {field: message});
}

class _Parcel {
  _Parcel(
    this.id,
    this.name,
    this.city,
    this.landmark,
    this.amount, {
    this.code = false,
    this.fragile = false,
    this.note,
    this.status = 'out_for_delivery',
  });

  final int id;
  final String name;
  final String city;
  final String landmark;
  final int amount;
  final bool code;
  final bool fragile;
  final String? note;
  String status;
  String? failure;
  Ticket? ticket;

  String get label => switch (status) {
    'out_for_delivery' => 'قيد التوصيل',
    'delivered' => 'واصل',
    'partially_delivered' => 'واصل جزئي',
    'failed_attempt' => 'لم يُسلَّم',
    'postponed' => 'مؤجل',
    'returning' => 'راجع',
    _ => status,
  };

  TaskRow row({bool returning = false}) => TaskRow(
    id: id,
    number: '$id',
    status: status,
    statusLabel: label,
    recipient: name,
    city: city,
    landmark: landmark,
    amount: returning ? 0 : amount,
    needsCode: code,
    fragile: fragile,
    hasNote: note != null,
    ticketStatus: ticket?.status,
    ticketLabel: ticket?.statusLabel,
    failure: failure,
  );

  ShipmentDetail detail() {
    final canAct = status == 'out_for_delivery';
    final partial = canAct && ticket?.status == 'approved' ? ticket!.approved : null;
    return ShipmentDetail(
      id: id,
      number: '$id',
      status: status,
      statusLabel: label,
      recipient: name,
      phone: '07700000${id % 1000}',
      governorate: 'بغداد',
      city: city,
      address: landmark,
      landmark: 'قرب مدرسة النور',
      lat: 33.3,
      lng: 44.4,
      merchant: 'متجر تجريبي',
      merchantPhone: '07800000000',
      amount: amount,
      needsCode: code,
      needsScan: canAct && Demo.scanParcels,
      pieces: 1,
      description: 'ملابس',
      notes: note,
      fragile: fragile,
      attempts: failure == null ? 0 : 1,
      failure: failure,
      ticket: canAct ? ticket : null,
      actions: canAct
          ? ['delivered', if (partial != null) 'partially_delivered', 'failed_attempt', 'postponed']
          : const [],
      partialAmount: partial,
    );
  }
}
