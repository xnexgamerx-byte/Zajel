import 'api.dart';
import 'models.dart';

/// بيانات التصميم نفسها — لنسخة العرض (DEMO) ومطابقة الشاشة بملف التصميم.
class Demo {
  static final session = Session(name: 'أحمد', companyName: 'الزاجل');

  static final home = HomeData(
    greeting: 'صباح الخير',
    name: 'أحمد',
    unread: 3,
    banners: const [],
    balance: 1750000,
    owed: true,
    available: 1250000,
    pending: 500000,
    pendingReason: 'عن 12 شحنة واصلة، نقدها ما زال مع المندوب — يصير متاحاً حين تحاسبه الشركة.',
    stats: const Stats(total: 124, delivered: 86, inDelivery: 28, returns: 6),
    processing: 3,
    canProcess: true,
    attention: 3,
    recent: [
      RecentShipment(
        number: 'ZA-20260124',
        name: 'محمد علي',
        area: 'المنصور',
        amount: 45000,
        at: _today(10, 45),
        status: 'مسلمة',
        urgent: false,
      ),
      RecentShipment(
        number: 'ZA-20260123',
        name: 'نور خالد',
        area: 'الكرادة',
        amount: 32000,
        at: _today(9, 20),
        status: 'قيد التوصيل',
        urgent: false,
      ),
      RecentShipment(
        number: 'ZA-20260122',
        name: 'سارة أحمد',
        area: 'زيونة',
        amount: 28000,
        at: _today(16, 15).subtract(const Duration(days: 1)),
        status: 'للمعالجة',
        urgent: true,
      ),
    ],
  );

  /// شحنات العرض: الثلاث التي في التصميم وما يكمل شرائحها
  static final List<RecentShipment> _all = [
    ...home.recent.asMap().entries.map((e) => _with(e.value, id: e.key + 1)),
    _row(4, 'علي حسين', 'الأعظمية', 60000, 'قيد التوصيل', 'blue', hours: 3),
    _row(5, 'زهراء كريم', 'الكاظمية', 25000, 'مؤجل', 'amber', urgent: true, hours: 20),
    _row(6, 'حسن جبار', 'البياع', 38000, 'واصل', 'green', hours: 26),
    _row(7, 'مريم سعد', 'المنصور', 52000, 'راجع للتاجر', 'red', hours: 50),
    _row(8, 'كرار عادل', 'الدورة', 19000, 'بالمخزن', 'slate', hours: 52),
  ];

  static const _filters = {
    'all': 'الكل',
    'open': 'قيد التوصيل',
    'delivered': 'مسلمة',
    'processing': 'للمعالجة',
    'attention': 'تحتاج انتباهك',
    'returns': 'راجع مؤكدة',
  };

  static bool _in(RecentShipment s, String f) => switch (f) {
    'open' => const ['قيد التوصيل', 'للمعالجة', 'مؤجل', 'بالمخزن'].contains(s.status),
    'delivered' => s.tone == 'green',
    'processing' => s.status == 'للمعالجة',
    'attention' => s.status == 'مؤجل',
    'returns' => s.status == 'راجع للتاجر',
    _ => true,
  };

  static ShipmentPage shipments(String filter, String q, int page) {
    final items = _all
        .where((s) => _in(s, filter) && (q.isEmpty || s.name.contains(q) || s.number.contains(q)))
        .toList();
    return ShipmentPage(
      filters: [
        for (final f in _filters.entries)
          ShipmentFilter(key: f.key, label: f.value, count: _all.where((s) => _in(s, f.key)).length),
      ],
      items: items,
      page: 1,
      lastPage: 1,
      total: items.length,
    );
  }

  static ShipmentDetail shipment(int id) {
    final row = _all.firstWhere((s) => s.id == id, orElse: () => _all.first);
    final start = (row.at ?? DateTime.now()).subtract(const Duration(hours: 30));
    final settled = row.tone == 'green' || row.status == 'للمعالجة';
    return ShipmentDetail(
      row: row,
      createdAt: start,
      deliveryCode: row.tone == 'green' ? null : '4821',
      failureReason: row.status == 'للمعالجة' ? 'الزبون لا يرد' : null,
      attempts: row.status == 'للمعالجة' ? 1 : 0,
      trackingUrl: 'https://example.com/t/${row.number}',
      courier: row.tone == 'green' || row.tone == 'red' ? null : 'حيدر كريم',
      lastStatus: row.status,
      lastAt: settled ? row.at : start.add(const Duration(hours: 22)),
      timeline: [
        TimelineStep(title: 'جديد', at: start),
        TimelineStep(title: 'استلمه المندوب', at: start.add(const Duration(hours: 3))),
        TimelineStep(title: 'بالمخزن', at: start.add(const Duration(hours: 6))),
        TimelineStep(title: 'قيد التوصيل', at: start.add(const Duration(hours: 22))),
        if (row.tone == 'green') TimelineStep(title: 'واصل', at: row.at),
        if (row.status == 'للمعالجة') TimelineStep(title: 'لم يُسلَّم', note: 'الزبون لا يرد', at: row.at),
      ],
      recipient: {
        'name': row.name,
        'phone': '07801234567',
        'phone_alt': null,
        'governorate': 'بغداد',
        'city': row.area,
        'address': null,
        'landmark': 'قرب الجامع',
        'pieces': 1,
        'type': 'طلب جديد',
        'size': 'عادي',
        'goods': 'ملابس',
      },
      money: {
        'cod': row.amount,
        'collected': row.tone == 'green' ? row.amount : 0,
        'delivery_fee': 5000,
        'cod_fee': 0,
        'return_fee': 0,
        'due': row.amount - 5000,
        'owed': true,
      },
    );
  }

  static RecentShipment _row(
    int id,
    String name,
    String area,
    int amount,
    String status,
    String tone, {
    bool urgent = false,
    int hours = 1,
  }) => RecentShipment(
    id: id,
    number: 'ZA-${20260121 + id}',
    name: name,
    area: area,
    amount: amount,
    at: DateTime.now().subtract(Duration(hours: hours)),
    status: status,
    urgent: urgent,
    tone: tone,
  );

  static RecentShipment _with(RecentShipment s, {required int id}) => RecentShipment(
    id: id,
    number: s.number,
    name: s.name,
    area: s.area,
    amount: s.amount,
    at: s.at,
    status: s.status,
    urgent: s.urgent,
    tone: s.urgent ? 'urgent' : (s.status == 'مسلمة' ? 'green' : 'blue'),
  );

  static DateTime _today(int h, int m) {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day, h, m);
  }

  // ---------------------------------------------------------------- طلب جديد

  static final form = CreateForm(
    governorates: const [
      Choice('1', 'بغداد'),
      Choice('2', 'البصرة'),
      Choice('3', 'نينوى'),
      Choice('4', 'أربيل'),
      Choice('5', 'النجف'),
    ],
    home: '1',
    sizes: const [
      Choice('normal', 'عادي'),
      Choice('medium', 'متوسط'),
      Choice('large', 'كبير'),
      Choice('special', 'خاص'),
    ],
    types: const [Choice('delivery', 'طلب جديد'), Choice('exchange', 'استبدال')],
    required: const [],
    waybills: true,
    goods: 'ملابس',
    reading: true,
    listening: true,
  );

  static List<Choice> areas(String governorate) => [
    for (final (i, name)
        in (governorate == '1'
                ? ['المنصور', 'الكرادة', 'زيونة', 'الأعظمية', 'الكاظمية', 'البياع', 'الدورة', 'اليرموك']
                : ['المركز', 'الأطراف'])
            .indexed)
      Choice('${int.parse(governorate) * 100 + i}', name),
  ];

  static Quote quote(int cod) => Quote(deliveryFee: 5000, fees: 5000, due: cod - 5000);

  static Created create(Map<String, dynamic> data) => Created(
    row: RecentShipment(
      id: 1,
      number: 'ZA-20260125',
      name: (data['recipient_name'] as String?)?.isNotEmpty == true ? data['recipient_name'] as String : 'الزبون',
      area: 'المنصور',
      amount: data['cod_amount'] as int? ?? 0,
      at: DateTime.now(),
      status: 'جديد',
      urgent: false,
    ),
    due: (data['cod_amount'] as int? ?? 0) - 5000,
  );

  /// ما يقرؤه العرض: رسالة زبونٍ وهميّة، أو كلامٌ مسموع
  static Future<OrderReading> read({bool spoken = false, bool heard = false}) async {
    await Future<void>.delayed(const Duration(milliseconds: 600));
    return OrderReading(
      fields: {
        'recipient_name': heard ? 'مصطفى عادل' : 'علي حسين',
        'recipient_phone': heard ? '07741077999' : '07712345678',
        'governorate_id': 1,
        'city_id': 101,
        'landmark': heard ? 'قرب الجامع' : 'قرب ساحة كهرمانة',
        'cod_amount': 25000,
      },
      found: const {'governorate_id': 'بغداد', 'city_id': 'الكرادة'},
      transcript: heard
          ? 'مصطفى عادل صفر سبعة سبعة أربعة واحد صفر سبعة سبعة تسعة تسعة تسعة بغداد الكرادة قرب الجامع خمسة وعشرين ألف'
          : null,
      ai: true,
    );
  }

  static String waybill(String code) {
    if (!RegExp(r'^9\d{7}$').hasMatch(code)) {
      throw ApiError('هذا ليس رقم وصلٍ مطبوع. امسح الباركود الذي على الوصل.');
    }
    return code;
  }

  // ---------------------------------------------------------------- للمعالجة والمالية

  static ProcessingPage processing() => ProcessingPage(
    allowed: true,
    total: 3,
    items: [
      ProcessingItem(row: _all[2], reason: 'الزبون لا يرد', attempts: 1, waiting: 5, phone: '07801234567'),
      ProcessingItem(
        row: _row(9, 'حسين علي', 'الكرادة', 41000, 'للمعالجة', 'urgent', urgent: true, hours: 26),
        reason: 'الهاتف مغلق',
        attempts: 2,
        waiting: 26,
        phone: '07709876543',
      ),
      ProcessingItem(
        row: _row(10, 'فاطمة محمد', 'زيونة', 33000, 'للمعالجة', 'urgent', urgent: true, hours: 8),
        reason: 'الزبون رفض الاستلام',
        attempts: 1,
        waiting: 8,
        phone: '07501112233',
      ),
    ],
  );

  static String process(String action) => switch (action) {
    'redeliver' => 'سُجّل قرارك: إعادة توصيل.',
    'postpone' => 'سُجّل قرارك: مؤجل.',
    _ => 'سُجّل قرارك: راجع مؤكد.',
  };

  /// ما فعله التاجر في العرض: طلبه المفتوح والكشوف التي أكّد استلامها
  static PaymentRequest? _request;
  static final _confirmed = <int>{};

  static Finance get finance => Finance(
    total: 1750000,
    owed: true,
    available: 1250000,
    pending: 500000,
    pendingReason: 'عن 12 شحنة واصلة، نقدها ما زال مع المندوب — يصير متاحاً حين تحاسبه الشركة.',
    methods: const [
      PayoutMethod(value: 'cash', label: 'نقد'),
      PayoutMethod(value: 'zaincash', label: 'زين كاش', details: true, hint: 'رقم محفظة زين كاش واسم صاحبها'),
      PayoutMethod(value: 'qi', label: 'Qi كارد', details: true, hint: 'رقم بطاقة Qi (ماستر كارد) واسم صاحبها'),
      PayoutMethod(value: 'fib', label: 'FIB', details: true, hint: 'رقم حساب FIB واسم صاحبه'),
    ],
    method: 'zaincash',
    account: '…2222',
    request: _request,
    statements: [
      Statement(
        id: 1,
        code: 'MS000041',
        status: 'مدفوع',
        paid: true,
        net: 870000,
        count: 23,
        at: DateTime.now().subtract(const Duration(days: 2)),
        reference: 'ZC-55120',
        confirmed: _confirmed.contains(1),
      ),
      Statement(
        id: 2,
        code: 'MS000037',
        status: 'مدفوع',
        paid: true,
        net: 640000,
        count: 17,
        at: DateTime.now().subtract(const Duration(days: 9)),
        confirmed: true,
      ),
    ],
    movements: [
      Movement(label: 'مستحقّ الشحنة ZA-20260124', amount: 40000, shipment: 'ZA-20260124', at: _today(10, 45)),
      Movement(
        label: 'دفعة للتاجر — كشف MS000041',
        amount: -870000,
        at: DateTime.now().subtract(const Duration(days: 2)),
      ),
      Movement(
        label: 'أجرة راجع الشحنة ZA-20260119',
        amount: -5000,
        shipment: 'ZA-20260119',
        at: DateTime.now().subtract(const Duration(days: 3)),
      ),
    ],
  );

  static PaymentRequest requestPayment(String method) =>
      _request = PaymentRequest(number: 'REQ-261010-7', amount: finance.available, method: method, at: DateTime.now());

  static String confirmStatement(int id) {
    _confirmed.add(id);
    return 'أكّدت استلام الدفعة.';
  }

  // ---------------------------------------------------------------- الأدوات السريعة (docs/plan/56)

  static DateTime _ago(int hours) => DateTime.now().subtract(Duration(hours: hours));

  static final _pickups = <Pickup>[
    Pickup(
      id: 2,
      number: 'PU000118',
      status: 'completed',
      label: 'تمّ الاستلام',
      expected: 15,
      actual: 14,
      courier: 'حيدر كريم',
      courierPhone: '07720000002',
      at: _ago(28),
    ),
    Pickup(id: 1, number: 'PU000097', status: 'completed', label: 'تمّ الاستلام', expected: 9, actual: 9, at: _ago(75)),
  ];

  static PickupsPage pickups() => PickupsPage(
    items: List.of(_pickups),
    address: 'بغداد، المنصور — شارع ١٤ رمضان',
    phone: '07790000001',
    open: _pickups.any((p) => p.status == 'pending' || p.status == 'assigned'),
  );

  static String requestPickup(int count, String? day, String? notes) {
    if (_pickups.any((p) => p.status == 'pending' || p.status == 'assigned')) {
      throw ApiError('عندك طلب استلام مفتوح. انتظر وصول المندوب، أو اتّصل بالشركة لتعديل عدده.');
    }
    final number = 'PU000${119 + _pickups.length - 2}';
    _pickups.insert(
      0,
      Pickup(
        id: _pickups.length + 1,
        number: number,
        status: 'pending',
        label: 'بانتظار مندوب',
        expected: count,
        at: DateTime.now(),
        day: day,
        notes: notes,
      ),
    );
    return 'أُرسل طلب استلام برقم $number.';
  }

  static const _returning = 3;

  static final _requests = <MerchantRequestRow>[
    MerchantRequestRow(
      id: 2,
      number: 'REQ-261008-3',
      type: 'payment',
      label: 'طلب دفع',
      status: 'handled',
      state: 'تمّت معالجته',
      amount: 870000,
      method: 'زين كاش',
      result: 'كشف MS000041',
      at: _ago(50),
    ),
    MerchantRequestRow(
      id: 1,
      number: 'RET-261004-1',
      type: 'returns',
      label: 'طلب كشف راجع',
      status: 'handled',
      state: 'تمّت معالجته',
      courier: true,
      result: 'إيصال RB000031',
      at: _ago(150),
    ),
  ];

  static final _batches = <ReturnReceipt>[
    ReturnReceipt(id: 2, number: 'RB000034', count: 2, fees: 5000, via: 'مع حيدر كريم', at: _ago(5)),
    ReturnReceipt(
      id: 1,
      number: 'RB000031',
      count: 4,
      fees: 10000,
      via: 'من المخزن',
      at: _ago(140),
      received: _ago(140),
    ),
  ];

  static RequestsPage requests() =>
      RequestsPage(returning: _returning, requests: List.of(_requests), batches: List.of(_batches));

  static String requestReturns(bool courier, String? note) {
    if (_returning == 0) throw ApiError('لا راجع لك عندنا الآن.');
    if (_requests.any((r) => r.type == 'returns' && r.status == 'open')) {
      throw ApiError('عندك طلب كشف راجع مفتوح.');
    }
    final number = 'RET-261010-${_requests.length + 1}';
    _requests.insert(
      0,
      MerchantRequestRow(
        id: _requests.length + 1,
        number: number,
        type: 'returns',
        label: 'طلب كشف راجع',
        status: 'open',
        state: 'بانتظار المعالجة',
        courier: courier,
        note: note,
        at: DateTime.now(),
      ),
    );
    return 'أُرسل طلب كشف راجع برقم $number.';
  }

  static String cancelRequest(int id) {
    final r = _requests.firstWhere((r) => r.id == id);
    r
      ..status = 'cancelled'
      ..state = 'ملغى';
    return 'أُلغي الطلب ${r.number}.';
  }

  static String confirmReturns(int id) {
    final b = _batches.firstWhere((b) => b.id == id)..received = DateTime.now();
    return 'أكّدت استلام رواجع الإيصال ${b.number}.';
  }

  static final _threads = <Thread>[
    Thread(
      id: 1,
      subject: 'تأخّر طرد الكرادة',
      shipment: 'ZA-20260122',
      messages: [
        Message(id: 1, mine: true, body: 'السلام عليكم، الطرد صار له يومين ما وصل. شنو السبب؟', at: _ago(6)),
        Message(
          id: 2,
          mine: false,
          author: 'سارة — خدمة التجّار',
          body: 'وعليكم السلام، الزبون ما يرد. نحاول اليوم مرة ثانية ونبلغك.',
          at: _ago(5),
        ),
      ],
    ),
  ];

  static final _unread = <int>{1};

  static SupportPage support() => SupportPage(
    whatsapp: 'https://wa.me/9647701234567',
    complaints: '07801112222',
    items: [
      for (final t in _threads.reversed)
        Conversation(
          id: t.id,
          subject: t.subject,
          shipment: t.shipment,
          unread: _unread.contains(t.id),
          staff: !t.messages.last.mine,
          at: t.messages.last.at,
        ),
    ],
  );

  static int startConversation(String subject, String body, String? shipment) {
    final id = _threads.length + 1;
    _threads.add(
      Thread(
        id: id,
        subject: subject,
        shipment: shipment?.isNotEmpty == true ? shipment : null,
        messages: [Message(id: 100 + id, mine: true, body: body, at: DateTime.now())],
      ),
    );
    return id;
  }

  static Thread thread(int id) {
    _unread.remove(id);
    return _threads.firstWhere((t) => t.id == id);
  }

  static void reply(int id, String body, {bool image = false}) => _threads
      .firstWhere((t) => t.id == id)
      .messages
      .add(
        Message(
          id: DateTime.now().millisecondsSinceEpoch,
          mine: true,
          body: image && body.isEmpty ? '📎 صورة' : body,
          at: DateTime.now(),
        ),
      );

  // ---------------------------------------------------------------- الوصولات والرفع من ملف (docs/plan/57)

  static const _print = {'80x120': 'https://example.invalid/print', '100x100': 'https://example.invalid/print'};

  static final _books = <WaybillBookRow>[
    WaybillBookRow(
      id: 1,
      range: '90000001–90000050',
      size: 50,
      label: '50 وصلاً',
      used: 31,
      at: _ago(200),
      print: _print,
    ),
  ];

  static WaybillsPage waybills() => WaybillsPage(
    max: 200,
    sizes: const [Choice('80x120', '80×120 مم'), Choice('100x100', '100×100 مم')],
    books: List.of(_books),
  );

  static IssuedBook issueWaybills(int size, String printSize) {
    final from = 90000051 + (_books.length - 1) * 1000;
    final range = '$from–${from + size - 1}';
    _books.insert(
      0,
      WaybillBookRow(
        id: _books.length + 1,
        range: range,
        size: size,
        label: '$size وصلاً',
        used: 0,
        at: DateTime.now(),
        print: _print,
      ),
    );
    return IssuedBook(range: range, print: _print[printSize]!, message: 'جاهزٌ دفترٌ من $size وصلاً: $range.');
  }

  static const importInfo = (
    columns: [
      (label: 'اسم المستلم', required: false),
      (label: 'هاتف المستلم', required: true),
      (label: 'المحافظة', required: true),
      (label: 'المنطقة', required: true),
      (label: 'أقرب نقطة دالّة', required: false),
      (label: 'المبلغ المطلوب', required: true),
    ],
    template: 'https://example.invalid/template',
  );

  static Future<ImportPreview> previewImport() async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    return ImportPreview(
      path: 'imports/demo.xlsx',
      total: 3,
      good: 2,
      bad: [
        (row: 4, name: 'بلا هاتف', errors: ['هاتف المستلم مطلوب']),
      ],
      rows: [
        (row: 2, name: 'زينب كاظم', phone: '07701112233', place: 'بغداد · الكرادة', amount: 75000),
        (row: 3, name: 'علي حسين', phone: '07801234567', place: 'بغداد · المنصور', amount: 40000),
      ],
    );
  }

  static String confirmImport(bool skipErrors) {
    if (!skipErrors) throw ApiError('في الملف صفوف بأخطاء، عددها 1. صحّحه أو استورد الصفوف الصحيحة وحدها.');
    return 'أُنشئت شحناتك، عددها 2. اطلب استلاماً متى جهّزت الطرود. وتُخطّيت صفوف بأخطاء، عددها 1.';
  }

  // ---------------------------------------------------------------- الجرس (docs/plan/59)

  static bool _noticesRead = false;

  static List<AppNotice> notices() {
    final fresh = !_noticesRead;
    _noticesRead = true;
    return [
      AppNotice(
        kind: 'support',
        id: 1,
        title: 'ردّت الشركة: تأخّر طرد الكرادة',
        body: 'افتح المحادثة لتقرأ الردّ.',
        fresh: true,
        at: _ago(5),
      ),
      AppNotice(
        kind: 'notice',
        id: 2,
        title: 'عطلة العيد',
        body: 'لا توصيل يوم الجمعة، ويعود العمل السبت صباحاً.',
        fresh: fresh,
        at: _ago(20),
      ),
      AppNotice(
        kind: 'notice',
        id: 1,
        title: 'أسعار جديدة للمحافظات',
        body: 'تبدأ من أوّل الشهر — راجع «المالية».',
        at: _ago(170),
      ),
    ];
  }
}
