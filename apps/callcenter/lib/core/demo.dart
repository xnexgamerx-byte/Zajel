import 'api.dart';
import 'models.dart';

/// نسخة العرض (config/demo.json): بياناتٌ وهميّة للاختبارات ومطابقة التصميم — لا نظام ولا زبائن حقيقيّون.
///
/// تتبع قاعدة النظام نفسها (docs/plan/73): من فتحت الطلب تعالجه وحدها. وفي العرض طلباتٌ تعالجها
/// زميلاتٌ («زينب» و«نور») حتى يُرى كيف يُقفل الطلب على غير صاحبته.
class Demo {
  static final session = Session(name: 'سارة', companyName: 'الزاجل', initial: 'ز');

  static List<_Ticket> _tickets = _seedTickets();
  static List<_Case> _cases = _seedCases();
  static List<_Conv> _convs = _seedConvs();

  /// ما تعالجه هي: «ticket:1»، «processing:101»…
  static final Map<String, DateTime> _mine = {};
  static int _doneTickets = 4;
  static int _doneCases = 7;

  /// «حالة الشحنات» بخاناتها، و«أنجزتِ اليوم» بتفاصيله (docs/plan/80)
  static Map<String, List<FoundShipment>> _board = _seedBoard();
  static List<DoneItem> _done = _seedDone();

  /// يعيد العرض إلى أوّله — بين اختبارٍ وآخر
  static void reset() {
    _tickets = _seedTickets();
    _cases = _seedCases();
    _convs = _seedConvs();
    _mine.clear();
    _doneTickets = 4;
    _doneCases = 7;
    _board = _seedBoard();
    _done = _seedDone();
  }

  static DateTime _ago(int minutes) => DateTime.now().subtract(Duration(minutes: minutes));

  static ClaimInfo? _claim(String key, String? other, DateTime? otherSince) {
    if (_mine.containsKey(key)) return ClaimInfo(by: session.name, mine: true, text: 'تعالجها أنت', since: _mine[key]);
    if (other == null) return null;
    final minutes = DateTime.now().difference(otherSince ?? DateTime.now()).inMinutes.clamp(1, 999);
    return ClaimInfo(
      by: other,
      mine: false,
      text: 'تعالجها $other منذ $minutes دقيقة — لا تُعالَج مرّتين.',
      since: otherSince,
    );
  }

  /// تأخذه إن لم يكن لزميلتها — كـClaims::take
  static void _take(String key, String? other, DateTime? since) {
    if (!_mine.containsKey(key) && other != null) {
      final text = _claim(key, other, since)!.text;
      throw ApiError(text, fields: {'claim': text});
    }
    _mine.putIfAbsent(key, DateTime.now);
  }

  // ------------------------------------------------------------------ الرئيسية

  static Future<HomeData> home() async => HomeData(
    greeting: DateTime.now().hour < 12 ? 'صباح الخير' : 'مساء الخير',
    name: session.name,
    governorates: const ['بغداد', 'بابل'],
    tickets: QueueInfo(
      count: _tickets.length,
      oldestMinutes: _tickets.isEmpty ? 0 : _tickets.map((t) => t.waiting).reduce((a, b) => a > b ? a : b),
      late: _tickets.where((t) => t.waiting >= 15).length,
    ),
    processing: QueueInfo(
      count: _cases.length,
      oldestMinutes: _cases.isEmpty ? 0 : _cases.map((c) => c.waiting).reduce((a, b) => a > b ? a : b),
      late: _cases.where((c) => c.waiting >= 24 * 60).length,
      free: _cases.where((c) => c.other == null && !_mine.containsKey('processing:${c.id}')).length,
    ),
    conversations: QueueInfo(
      count: _convs.where((c) => c.waiting).length,
      oldestMinutes: _convs.where((c) => c.waiting).fold(0, (m, c) => c.minutes > m ? c.minutes : m),
    ),
    couriers: QueueInfo(count: 2),
    mine: [
      for (final e in _mine.entries)
        MyClaim(
          subject: e.key.split(':').first,
          id: int.parse(e.key.split(':').last),
          title: _title(e.key),
          since: e.value,
        ),
    ],
    todayTickets: _doneTickets,
    todayProcessed: _doneCases,
    todayActions: {
      for (final a in const ['redeliver', 'postpone', 'return']) a: _done.where((d) => d.action == a).length,
    },
  );

  static String _title(String key) {
    final id = int.parse(key.split(':').last);
    return switch (key.split(':').first) {
      'ticket' => 'تذكرة ${_tickets.where((t) => t.id == id).firstOrNull?.number ?? ''}',
      'processing' => 'معالجة ${_cases.where((c) => c.id == id).firstOrNull?.number ?? ''}',
      _ => 'محادثة: ${_convs.where((c) => c.id == id).firstOrNull?.subject ?? ''}',
    };
  }

  static Future<String> release(String subject, int id) async {
    _mine.remove('$subject:$id');
    return 'أفلتّها — تعالجها أيّ زميلة.';
  }

  // ------------------------------------------------------------------ تذاكر المبلغ

  static List<_Ticket> _seedTickets() => [
    _Ticket(
      1,
      'TK000214',
      'price',
      50000,
      45000,
      'الزبون يقول اتّفق مع التاجر على 45 ألف',
      22,
      180240,
      'زينب علي',
      'بغداد · الكرادة',
      'أحمد كريم',
      'متجر الوردة',
    ),
    _Ticket(
      2,
      'TK000215',
      'partial',
      60000,
      35000,
      'أخذ الحذاء وأرجع الحقيبة',
      6,
      180241,
      'حسين كاظم',
      'بغداد · المنصور',
      'علي جبار',
      'بيت العطور',
      other: 'زينب',
      otherSince: _ago(4),
    ),
    _Ticket(
      3,
      'TK000216',
      'price',
      25000,
      20000,
      'السعر المكتوب على الوصل غلط',
      3,
      180242,
      'نور الهدى',
      'بابل · الحلة',
      'مصطفى عادل',
      'متجر النخيل',
    ),
  ];

  static _Ticket _ticketOf(int id) =>
      _tickets.firstWhere((t) => t.id == id, orElse: () => throw ApiError('حُسم هذا الطلب.', notFound: true));

  static Future<List<TicketItem>> tickets() async => [for (final t in _tickets) t.item()];

  static Future<TicketItem> ticket(int id) async => _ticketOf(id).item(full: true);

  static Future<TicketItem> claimTicket(int id) async {
    final t = _ticketOf(id);
    _take('ticket:$id', t.other, t.otherSince);
    return t.item(full: true);
  }

  static Future<String> approveTicket(int id, int amount) async {
    final t = _ticketOf(id);
    _take('ticket:$id', t.other, t.otherSince);
    _tickets.remove(t);
    _mine.remove('ticket:$id');
    _doneTickets++;
    return 'اعتُمد الطلب ${t.number} بـ${_money(amount)} د.ع — يراه المندوب الآن في صفحة الشحنة.';
  }

  static Future<String> rejectTicket(int id, String reply) async {
    if (reply.trim().length < 3) {
      throw ApiError('اكتب للمندوب سبب الرفض — يقرؤه عند الباب.', fields: {'reply': 'اكتب للمندوب سبب الرفض.'});
    }
    final t = _ticketOf(id);
    _take('ticket:$id', t.other, t.otherSince);
    _tickets.remove(t);
    _mine.remove('ticket:$id');
    _doneTickets++;
    return 'رُفض الطلب ${t.number} — يسلّم المندوب بالمبلغ الأصلي أو يسجّلها لم تُسلَّم.';
  }

  // ------------------------------------------------------------------ المعالجة

  static List<_Case> _seedCases() => [
    _Case(
      101,
      '180231',
      'منى جاسم',
      'بغداد · الكرادة',
      30000,
      'لا يرد على الهاتف',
      2,
      26 * 60,
      'متجر الوردة',
      'أحمد كريم',
      waitingOnUs: true,
      merchantChat: [
        ChatLine(mine: false, author: 'متجر الوردة', body: 'الزبونة اتصلت بينا، تريد التوصيل بعد العصر', at: _ago(30)),
      ],
    ),
    _Case(
      102,
      '180232',
      'كرار عادل',
      'بغداد · الدورة',
      19000,
      'الزبون غير موجود بالعنوان',
      1,
      5 * 60,
      'بيت العطور',
      'علي جبار',
      other: 'نور',
      otherSince: _ago(6),
    ),
    _Case(
      103,
      '180233',
      'سجى محمد',
      'بابل · الحلة',
      42000,
      'الزبون طلب التأجيل',
      1,
      3 * 60,
      'متجر النخيل',
      'مصطفى عادل',
    ),
    _Case(104, '180234', 'عمر فاضل', 'بغداد · زيونة', 27000, 'الهاتف مغلق', 1, 90, 'متجر الوردة', 'أحمد كريم'),
  ];

  static _Case _caseOf(int id) =>
      _cases.firstWhere((c) => c.id == id, orElse: () => throw ApiError('عولجت هذه الشحنة سلفاً.', notFound: true));

  static Future<List<CaseItem>> cases(String q) async => [
    for (final c in _cases)
      if (q.isEmpty || c.number.contains(q) || c.name.contains(q)) c.item(),
  ];

  static Future<CaseItem> caseDetail(int id) async => _caseOf(id).item(full: true);

  static Future<CaseItem> claimCase(int id, {String? note}) async {
    final c = _caseOf(id);
    _take('processing:$id', c.other, c.otherSince);
    if (note != null) c.note = note;
    return c.item(full: true);
  }

  static Future<String> decide(int id, String action, {DateTime? until}) async {
    final c = _caseOf(id);
    if (action == 'postpone' && until == null) {
      throw ApiError('التأجيل إلى متى؟ اختر اليوم الذي اتُّفق عليه مع الزبون.', fields: {'until': 'اختر اليوم.'});
    }
    _take('processing:$id', c.other, c.otherSince);
    _cases.remove(c);
    _mine.remove('processing:$id');
    _doneCases++;
    final label = switch (action) {
      'redeliver' => 'إعادة توصيل',
      'postpone' => 'مؤجل إلى ${until!.toIso8601String().substring(0, 10)}',
      _ => 'راجع مؤكد',
    };
    final kind = switch (action) {
      'redeliver' => 'redelivery',
      'postpone' => 'postponed',
      _ => 'returns',
    };
    _board[kind]!.insert(
      0,
      _row(
        c.id,
        c.number,
        c.name,
        c.place,
        c.amount,
        switch (action) {
          'redeliver' => ('out_for_delivery', 'إعادة توصيل'),
          'postpone' => ('postponed', 'مؤجل'),
          _ => ('returning', 'راجع مؤكد'),
        },
        until: until,
        withCourier: action == 'return' ? true : null,
      ),
    );
    _log(c.id, c.number, c.name, action);
    return 'عولجت ${c.number}: $label.';
  }

  static Future<void> caseMessage(int id, String to, String body) async {
    final c = _caseOf(id);
    _take('processing:$id', c.other, c.otherSince);
    (to == 'merchant' ? c.merchantChat : c.courierChat).add(
      ChatLine(mine: true, author: session.name, body: body.trim(), at: DateTime.now()),
    );
  }

  // ------------------------------------------------------------------ المحادثات

  static List<_Conv> _seedConvs() => [
    _Conv(201, 'متجر الوردة', 'شحنتي 180240 تأخرت', '180240', 48, true, true, [
      ChatLine(
        mine: false,
        author: 'متجر الوردة',
        body: 'السلام عليكم، الشحنة 180240 من يومين بالمخزن، شنو السبب؟',
        at: _ago(48),
      ),
      ChatLine(
        mine: false,
        author: 'متجر الوردة',
        body: '',
        file: ChatFile(name: 'waybill.jpg', image: true, size: '184 ك.ب'),
        at: _ago(47),
      ),
    ]),
    _Conv(
      202,
      'بيت العطور',
      'تغيير عنوان الزبون',
      '180241',
      20,
      true,
      true,
      [
        ChatLine(
          mine: false,
          author: 'بيت العطور',
          body: 'الزبون غيّر عنوانه إلى المنصور، شارع 14 رمضان',
          at: _ago(20),
        ),
      ],
      other: 'زينب',
      otherSince: _ago(9),
    ),
    _Conv(203, 'متجر النخيل', 'كشف الحساب', null, 180, false, false, [
      ChatLine(mine: false, author: 'متجر النخيل', body: 'متى ينزل كشف هذا الأسبوع؟', at: _ago(240)),
      ChatLine(mine: true, author: 'سارة', body: 'ينزل الخميس إن شاء الله.', at: _ago(180)),
    ]),
  ];

  static _Conv _convOf(int id) => _convs.firstWhere((c) => c.id == id);

  static Future<List<ConversationItem>> conversations({bool waiting = true}) async => [
    for (final c in _convs)
      if (!waiting || c.waiting) c.item(),
  ];

  static Future<ConversationItem> conversation(int id) async {
    final c = _convOf(id);
    c.unread = false;
    if (c.other == null) _mine.putIfAbsent('conversation:$id', DateTime.now);
    return c.item(full: true);
  }

  static Future<void> reply(int id, String body) async {
    final c = _convOf(id);
    _take('conversation:$id', c.other, c.otherSince);
    c.messages.add(ChatLine(mine: true, author: session.name, body: body.trim(), at: DateTime.now()));
    c.waiting = false;
  }

  static Future<String> closeConversation(int id) async {
    final c = _convOf(id);
    _take('conversation:$id', c.other, c.otherSince);
    _convs.remove(c);
    _mine.remove('conversation:$id');
    return 'أُغلقت المحادثة.';
  }

  // ------------------------------------------------------------------ البحث

  static List<FoundShipment> get _all => [
    for (final t in _tickets)
      FoundShipment(
        id: t.shipmentId,
        number: '${t.shipmentId}',
        recipient: t.recipient,
        phone: '07801234567',
        place: t.place,
        merchant: t.merchant,
        status: 'out_for_delivery',
        statusLabel: 'قيد التوصيل',
        amount: t.current,
      ),
    for (final c in _cases)
      FoundShipment(
        id: c.id,
        number: c.number,
        recipient: c.name,
        phone: '07801234567',
        place: c.place,
        merchant: c.merchant,
        status: 'failed_attempt',
        statusLabel: 'لم يُسلَّم',
        amount: c.amount,
      ),
    for (final rows in _board.values) ...rows,
  ];

  static Future<List<FoundShipment>> search(String q) async {
    final term = RegExp(r'\d{4,}').firstMatch(q)?.group(0) ?? q.trim();
    return [
      for (final s in _all)
        if (term.isNotEmpty &&
            (s.number.contains(term) || s.recipient.contains(term) || (s.phone ?? '').contains(term)))
          s,
    ];
  }

  static Future<ShipmentDetail> shipment(int id) async {
    final s = _all.lastWhere((s) => s.id == id);
    final processing = s.status == 'failed_attempt';
    final failed = processing || s.status == 'postponed';
    return ShipmentDetail(
      found: s,
      address: 'شارع 62، قرب المدرسة',
      landmark: 'مقابل مطعم الساعة',
      merchant: Party(name: s.merchant, phone: '07700000001'),
      courier: Party(name: 'أحمد كريم', phone: '07720000001'),
      processing: processing,
      reason: failed ? (s.reason ?? 'لا يرد على الهاتف') : null,
      reasonNote: failed ? 'اتّصل مرّتين، والزبونة طلبت تغيير العنوان إلى الكرادة قرب الجسر' : null,
      until: s.until,
      attempts: failed ? 1 : 0,
      pieces: 2,
      description: 'ملابس نسائية',
      canReopen: s.status == 'returning' && (s.withCourier ?? false),
      transitions: switch (s.status) {
        'out_for_delivery' => [
          Transition(value: 'delivered', label: 'واصل'),
          Transition(value: 'partially_delivered', label: 'واصل جزئي', needs: const ['amount']),
          Transition(value: 'failed_attempt', label: 'لم يُسلَّم', needs: const ['reason']),
          Transition(value: 'postponed', label: 'مؤجل'),
          Transition(value: 'at_hub', label: 'بالمخزن'),
        ],
        'postponed' => [
          Transition(value: 'redeliver', label: 'إعادة توصيل'),
          Transition(value: 'returning', label: 'راجع مؤكد'),
        ],
        _ => const [],
      },
      reasons: [
        ReasonOption(id: 1, name: 'لا يرد على الهاتف'),
        ReasonOption(id: 2, name: 'تغيير عنوان', note: true),
        ReasonOption(id: 3, name: 'الزبون رفض الاستلام', note: true),
      ],
      events: [
        EventLine(status: s.statusLabel, note: failed ? 'لا يرد على الهاتف' : null, by: 'أحمد كريم', at: _ago(90)),
        EventLine(
          status: 'تعديل بيانات',
          type: 'edited',
          note: 'العنوان: شارع 52 ← شارع 62 — طلبت الزبونة تغيير العنوان',
          by: 'سارة',
          at: _ago(200),
        ),
        EventLine(status: 'قيد التوصيل', by: 'المخزن', at: _ago(300)),
        EventLine(status: 'بالمخزن', by: 'المخزن', at: _ago(1500)),
        EventLine(status: 'تم الاستلام', by: 'مندوب الاستلام', at: _ago(1700)),
      ],
    );
  }

  /// «حدّثي الحالة» في العرض: يُرفض ما يحتاج سبباً أو مبلغاً بلا ذلك، كالنظام
  static Future<String> updateStatus(int id, String status, {int? reasonId, String? note, int? amount}) async {
    if (status == 'failed_attempt' && reasonId == null) {
      throw ApiError('سبب الفشل مطلوب — بلا سبب مصنَّف لا تقرير ولا محاسبة.', fields: {'failure_reason_id': '…'});
    }
    if (status == 'failed_attempt' && reasonId != 1 && (note ?? '').trim().isEmpty) {
      throw ApiError('السبب يتطلّب ملاحظة توضيحية.', fields: {'note': '…'});
    }
    if (status == 'partially_delivered' && amount == null) {
      throw ApiError('أدخل المبلغ المحصَّل فعلاً في التسليم الجزئي.', fields: {'collected_amount': '…'});
    }
    final s = _all.lastWhere((s) => s.id == id);
    return 'تم تحديث الشحنة ${s.number}.';
  }

  static Future<String> reopen(int id, String note) async {
    if (note.trim().isEmpty) {
      throw ApiError('اكتبي لماذا تعود للتوصيل: من اتّفق مع الزبون وعلى ماذا.', fields: {'note': '…'});
    }
    final rows = _board['returns']!;
    final s = rows.firstWhere(
      (r) => r.id == id && (r.withCourier ?? false),
      orElse: () => throw ApiError('لا تعود للتوصيل من هنا.', fields: {'note': '…'}),
    );
    rows.remove(s);
    _board['redelivery']!.insert(
      0,
      _row(s.id, s.number, s.recipient, s.place, s.amount, ('out_for_delivery', 'إعادة توصيل')),
    );
    _doneCases++;
    _log(s.id, s.number, s.recipient, 'redeliver', note: note);
    return 'عادت ${s.number} للتوصيل مع مندوبها — «إعادة توصيل».';
  }

  // ------------------------------------------------------------------ حالة الشحنات (docs/plan/80)

  static const _kinds = {
    'in_delivery': 'قيد التوصيل',
    'postponed': 'مؤجل',
    'redelivery': 'إعادة توصيل',
    'returns': 'راجع مؤكد',
  };

  static FoundShipment _row(
    int id,
    String number,
    String name,
    String place,
    int amount,
    (String, String) status, {
    DateTime? until,
    bool? withCourier,
    String? reason,
    int minutes = 120,
  }) => FoundShipment(
    id: id,
    number: number,
    recipient: name,
    phone: '07801234567',
    place: place,
    merchant: 'متجر الوردة',
    status: status.$1,
    statusLabel: status.$2,
    amount: amount,
    courier: 'أحمد كريم',
    reason: reason,
    until: until,
    withCourier: withCourier,
    since: _ago(minutes),
  );

  static Map<String, List<FoundShipment>> _seedBoard() {
    final today = DateTime.now();
    return {
      'in_delivery': [
        _row(301, '180301', 'علي حسين', 'بغداد · المنصور', 35000, ('out_for_delivery', 'قيد التوصيل'), minutes: 40),
        _row(302, '180302', 'هدى كريم', 'بغداد · الأعظمية', 48000, ('out_for_delivery', 'قيد التوصيل'), minutes: 75),
        _row(303, '180303', 'مصطفى جبار', 'بابل · الحلة', 22000, ('out_for_delivery', 'قيد التوصيل'), minutes: 160),
      ],
      'postponed': [
        _row(
          311,
          '180311',
          'زهراء كريم',
          'بغداد · الكاظمية',
          25000,
          ('postponed', 'مؤجل'),
          until: today,
          reason: 'الزبون طلب التأجيل',
        ),
        _row(
          312,
          '180312',
          'نبأ سالم',
          'بغداد · حي الجهاد',
          33000,
          ('postponed', 'مؤجل'),
          until: today.add(const Duration(days: 2)),
          reason: 'خارج المحافظة',
        ),
      ],
      'redelivery': [
        _row(321, '180321', 'سيف علي', 'بغداد · الشعب', 41000, ('out_for_delivery', 'إعادة توصيل'), minutes: 300),
        _row(322, '180322', 'رنا فاضل', 'بغداد · زيونة', 27000, ('at_hub', 'إعادة توصيل — بالمخزن'), minutes: 500),
      ],
      'returns': [
        _row(
          331,
          '180331',
          'أحمد ناصر',
          'بغداد · الشعب',
          41000,
          ('returning', 'راجع مؤكد'),
          withCourier: true,
          reason: 'الزبون رفض الاستلام',
        ),
        _row(
          332,
          '180332',
          'سجى محمد',
          'بابل · الحلة',
          42000,
          ('returning', 'راجع'),
          withCourier: false,
          reason: 'لا يرد على الهاتف',
        ),
      ],
    };
  }

  static Future<List<BoardKind>> board() async => [
    for (final e in _kinds.entries) BoardKind(kind: e.key, label: e.value, count: _board[e.key]!.length),
  ];

  static Future<BoardPage> boardList(String kind, String q) async {
    final rows = _board[kind] ?? const <FoundShipment>[];
    return BoardPage(
      kind: kind,
      label: _kinds[kind] ?? '',
      count: rows.length,
      items: [
        for (final r in rows)
          if (q.trim().isEmpty || r.number.contains(q.trim()) || r.recipient.contains(q.trim())) r,
      ],
    );
  }

  static void _log(int id, String number, String name, String action, {String? note}) => _done.insert(
    0,
    DoneItem(
      id: _done.length + 1,
      shipmentId: id,
      number: number,
      recipient: name,
      title: _labels[action] ?? 'معالجة',
      action: action,
      label: _labels[action] ?? 'معالجة',
      note: note,
      at: DateTime.now(),
    ),
  );

  static const _labels = {'redeliver': 'إعادة توصيل', 'postpone': 'مؤجل', 'return': 'راجع مؤكد'};

  static List<DoneItem> _seedDone() => [
    for (final (i, (action, number, name, note)) in const [
      ('redeliver', '180321', 'سيف علي', 'يستلم بعد العصر'),
      ('redeliver', '180322', 'رنا فاضل', null),
      ('redeliver', '180340', 'مريم سعد', 'غيّر رقمه'),
      ('postpone', '180311', 'زهراء كريم', 'مسافرة حتى الخميس'),
      ('postpone', '180312', 'نبأ سالم', null),
      ('return', '180331', 'أحمد ناصر', 'رفض نهائياً'),
      ('return', '180332', 'سجى محمد', null),
    ].indexed)
      DoneItem(
        id: i + 1,
        shipmentId: int.parse(number.substring(3)),
        number: number,
        recipient: name,
        title: _labels[action]!,
        action: action,
        label: _labels[action]!,
        note: note,
        at: _ago(30 + i * 25),
      ),
  ];

  static Future<List<DoneItem>> done(String kind) async => kind == 'tickets'
      ? [
          DoneItem(
            id: 1,
            shipmentId: 301,
            number: '180301',
            recipient: 'علي حسين',
            title: 'تذكرة TK-000188 — السعر تغيّر',
            action: 'approved',
            label: 'اعتُمد',
            note: '50,000 ← 45,000 د.ع',
            by: 'أحمد كريم',
            at: _ago(50),
          ),
          DoneItem(
            id: 2,
            shipmentId: 302,
            number: '180302',
            recipient: 'هدى كريم',
            title: 'تذكرة TK-000190 — أخذ جزءاً وأرجع الباقي',
            action: 'rejected',
            label: 'رُفض',
            note: 'التاجر لا يقبل البيع الجزئي',
            by: 'علي جبار',
            at: _ago(95),
          ),
        ]
      : List.of(_done);

  static String _money(int v) {
    final d = v.toString();
    final b = StringBuffer();
    for (var i = 0; i < d.length; i++) {
      if (i > 0 && (d.length - i) % 3 == 0) b.write(',');
      b.write(d[i]);
    }
    return b.toString();
  }
}

class _Ticket {
  _Ticket(
    this.id,
    this.number,
    this.kind,
    this.current,
    this.requested,
    this.reason,
    this.waiting,
    this.shipmentId,
    this.recipient,
    this.place,
    this.courier,
    this.merchant, {
    this.other,
    this.otherSince,
  });

  final int id;
  final String number;
  final String kind;
  final int current;
  final int requested;
  final String reason;
  final int waiting;
  final int shipmentId;
  final String recipient;
  final String place;
  final String courier;
  final String merchant;
  final String? other;
  final DateTime? otherSince;

  TicketItem item({bool full = false}) => TicketItem(
    id: id,
    number: number,
    kind: kind,
    kindLabel: kind == 'price' ? 'السعر تغيّر' : 'أخذ جزءاً وأرجع الباقي',
    current: current,
    requested: requested,
    reason: reason,
    waitingMinutes: waiting,
    shipmentId: shipmentId,
    shipmentNumber: '$shipmentId',
    recipient: recipient,
    phone: '07801234567',
    place: place,
    courier: Party(name: courier, phone: '07720000001'),
    merchant: Party(name: merchant, phone: '07700000001'),
    claim: Demo._claim('ticket:$id', other, otherSince),
    // الجزئيّ في العرض سلّمه المندوب وينتظر (docs/plan/80)
    finalize: kind == 'partial',
    returnedPieces: kind == 'partial' ? 1 : null,
    effect: full
        ? (kind == 'price'
              ? 'الاعتماد يغيّر مبلغ الشحنة الآن (ويُكتب في سجلّها)، ويسلّم المندوب «واصل» بالمبلغ الجديد.'
              : 'الاعتماد يفتح للمندوب «واصل جزئي» بهذا المبلغ، وباقي الطلب يرجع لتاجره راجعاً بلا أجرة راجع.')
        : null,
  );
}

class _Case {
  _Case(
    this.id,
    this.number,
    this.name,
    this.place,
    this.amount,
    this.reason,
    this.attempts,
    this.waiting,
    this.merchant,
    this.courier, {
    this.waitingOnUs = false,
    this.other,
    this.otherSince,
    List<ChatLine>? merchantChat,
  }) : merchantChat = merchantChat ?? [];

  final int id;
  final String number;
  final String name;
  final String place;
  final int amount;
  final String reason;
  final int attempts;
  final int waiting;
  final String merchant;
  final String courier;
  final bool waitingOnUs;
  final String? other;
  final DateTime? otherSince;
  final List<ChatLine> merchantChat;
  final List<ChatLine> courierChat = [];
  String? note;

  CaseItem item({bool full = false}) => CaseItem(
    id: id,
    number: number,
    recipient: name,
    phone: '07801234567',
    place: place,
    amount: amount,
    reason: reason,
    attempts: attempts,
    waitingMinutes: waiting,
    waitingOnUs: waitingOnUs,
    merchant: Party(name: merchant, phone: '07700000001'),
    courier: Party(name: courier, phone: '07720000001'),
    claim: Demo._claim('processing:$id', other, otherSince),
    reasonNote: full ? 'اتّصلت مرّتين ولم يرد، ورسالة الواتساب وصلت ولم تُقرأ' : null,
    description: full ? 'ملابس نسائية' : null,
    pieces: 2,
    address: full ? 'شارع 62، بيت 14' : null,
    landmark: full ? 'قرب جامع الرحمن' : null,
    myNote: note,
    canChat: full,
    canAsk: full,
    merchantChat: full ? List.of(merchantChat) : const [],
    courierChat: full ? List.of(courierChat) : const [],
  );
}

class _Conv {
  _Conv(
    this.id,
    this.merchant,
    this.subject,
    this.shipment,
    this.minutes,
    this.waiting,
    this.unread,
    List<ChatLine> messages, {
    this.other,
    this.otherSince,
  }) : messages = List.of(messages);

  final int id;
  final String merchant;
  final String subject;
  final String? shipment;
  final int minutes;
  bool waiting;
  bool unread;
  final List<ChatLine> messages;
  final String? other;
  final DateTime? otherSince;

  ConversationItem item({bool full = false}) => ConversationItem(
    id: id,
    subject: subject,
    merchant: Party(name: merchant, phone: '07700000001'),
    shipment: shipment,
    waiting: waiting,
    unread: unread,
    waitingMinutes: minutes,
    claim: Demo._claim('conversation:$id', other, otherSince),
    messages: full ? List.of(messages) : const [],
  );
}
