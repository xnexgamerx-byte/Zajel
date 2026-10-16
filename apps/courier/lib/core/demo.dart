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
  static void reset() {
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

  static Future<Outcome> act(int id, String action, {int? reasonId, String? note, String? code}) async {
    final p = _get(id);
    if (p.status != 'out_for_delivery') {
      throw _field('action', 'هذه الشحنة لم تعد بيدك.');
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
