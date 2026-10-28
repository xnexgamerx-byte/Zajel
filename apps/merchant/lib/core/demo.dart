import 'package:flutter/material.dart';

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
    stats: const Stats(
      total: 124,
      delivered: 86,
      inDelivery: 28,
      returns: 6,
      waiting: 6,
      warehouse: 11,
      courier: 28,
      deliveredToday: 14,
      returning: 3,
    ),
    processing: 3,
    canProcess: true,
    toPrint: 4,
    printed: 2,
    attention: 4,
    // «موظّفو المتجر» مفتوحةٌ في العرض (docs/plan/87)
    staffTool: true,
    // «تحتاج انتباهك» بتفاصيلها (docs/plan/61)
    attentionItems: [
      AttentionItem(
        row: _row(5, 'زهراء كريم', 'الكاظمية', 25000, 'مؤجل', 'yellow', urgent: true, hours: 20),
        reason: 'الزبون طلب التأجيل',
        attempts: 1,
        until: DateTime.now().add(const Duration(days: 1)),
      ),
      AttentionItem(
        row: _row(
          9,
          'أحمد ناصر',
          'الشعب',
          41000,
          'راجع',
          'red',
          urgent: true,
          hours: 30,
          returnPlace: 'راجع عند المندوب',
        ),
        reason: 'الزبون رفض الاستلام',
        attempts: 2,
      ),
      AttentionItem(
        row: _row(10, 'نبأ سالم', 'حي الجهاد', 33000, 'مؤجل', 'yellow', urgent: true, hours: 44),
        reason: 'الهاتف مغلق',
        attempts: 2,
        until: DateTime.now().add(const Duration(days: 2)),
      ),
      // الرابعة في «عرض الكل»: الرئيسية تعرض ثلاثاً (docs/plan/87)
      AttentionItem(
        row: _row(
          12,
          'رسل حيدر',
          'الحلة',
          27000,
          'راجع مؤكد',
          'red',
          urgent: true,
          hours: 28,
          governorate: 'بابل',
          returnPlace: 'راجع عند مركز محافظة بابل',
        ),
        reason: 'الزبون رفض الاستلام',
        attempts: 2,
      ),
    ],
    recent: [
      RecentShipment(
        number: 'ZA-20260124',
        name: 'محمد علي',
        area: 'المنصور',
        governorate: 'بغداد',
        city: 'المنصور',
        amount: 45000,
        at: _today(10, 45),
        status: 'مسلمة',
        urgent: false,
      ),
      RecentShipment(
        number: 'ZA-20260123',
        name: 'نور خالد',
        area: 'الكرادة',
        governorate: 'بغداد',
        city: 'الكرادة',
        amount: 32000,
        at: _today(9, 20),
        status: 'قيد التوصيل',
        urgent: false,
      ),
      RecentShipment(
        number: 'ZA-20260122',
        name: 'سارة أحمد',
        area: 'زيونة',
        governorate: 'بغداد',
        city: 'زيونة',
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
    _row(4, 'علي حسين', 'الحلة', 60000, 'قيد التوصيل', 'blue', hours: 3, governorate: 'بابل'),
    _row(
      5,
      'زهراء كريم',
      'الكاظمية',
      25000,
      'مؤجل',
      'yellow',
      urgent: true,
      hours: 20,
      until: _today(21, 0).add(const Duration(days: 1)),
    ),
    _row(6, 'حسن جبار', 'البياع', 38000, 'واصل', 'green', hours: 26),
    _row(7, 'مريم سعد', 'المنصور', 52000, 'راجع للتاجر', 'red', hours: 50),
    _row(8, 'كرار عادل', 'الدورة', 19000, 'بالمخزن', 'slate', hours: 52),
    _row(11, 'مريم سعد', 'المنصور', 52000, 'جديد', 'slate', hours: 1),
    // أين الراجع الآن (docs/plan/87)
    _row(
      12,
      'رسل حيدر',
      'الحلة',
      27000,
      'راجع مؤكد',
      'red',
      urgent: true,
      hours: 28,
      governorate: 'بابل',
      returnPlace: 'راجع عند مركز محافظة بابل',
    ),
    _row(
      13,
      'عباس كاظم',
      'الأعظمية',
      36000,
      'راجع مؤكد',
      'red',
      urgent: true,
      hours: 40,
      returnPlace: 'راجع في المخزن',
    ),
  ];

  static const _filters = {
    'all': 'الكل',
    'new': 'جديدة',
    'open': 'قيد التوصيل',
    'warehouse': 'بالمخزن',
    'delivered': 'مسلمة',
    'processing': 'للمعالجة',
    'attention': 'تحتاج انتباهك',
    'returns': 'راجع مؤكدة',
  };

  /// محافظات العرض بأرقامها في النظام
  static const _governorateIds = {'بغداد': 1, 'بابل': 2};

  static bool _in(RecentShipment s, String f) => switch (f) {
    'new' => s.status == 'جديد',
    'open' => const ['قيد التوصيل', 'بالمخزن'].contains(s.status),
    'warehouse' || 'courier' => s.status == (f == 'warehouse' ? 'بالمخزن' : 'قيد التوصيل'),
    'created' => s.status == 'جديد',
    'pickup' || 'redelivery' => false,
    'delivered' => s.tone == 'green',
    'processing' => s.status == 'للمعالجة',
    'postponed' => s.status == 'مؤجل',
    'attention' => s.status == 'مؤجل' || s.status.startsWith('راجع') && s.status != 'راجع للتاجر',
    // ما سُلِّم للتاجر خارج التطبيق، في كشوفه (docs/plan/86)
    'returns' => s.status == 'راجع مؤكد',
    'return_courier' => s.returnPlace == 'راجع عند المندوب',
    'return_hub' => s.returnPlace != null && s.returnPlace != 'راجع عند المندوب',
    _ => s.status != 'راجع للتاجر',
  };

  static ShipmentPage shipments(String filter, String q, int page, {int? governorateId}) {
    final inFilter = _all
        .where((s) => _in(s, filter) && (q.isEmpty || s.name.contains(q) || s.number.contains(q)))
        .toList();
    final items = [
      for (final s in inFilter)
        if (governorateId == null || _governorateIds[s.governorate] == governorateId) s,
    ];
    final byGovernorate = <String, int>{};
    for (final s in inFilter) {
      if (s.governorate != null) byGovernorate[s.governorate!] = (byGovernorate[s.governorate!] ?? 0) + 1;
    }
    return ShipmentPage(
      governorates: filter == 'all'
          ? const []
          : [
              for (final e in byGovernorate.entries)
                GovernorateCount(id: _governorateIds[e.key] ?? 0, name: e.key, count: e.value),
            ],
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
      // على الخريطة ما دامت لم تُحسم: دقيقٌ وهي مع المندوب، وإلّا تقريبيٌّ بمحافظتها (docs/plan/61)
      location: row.tone == 'green' || row.tone == 'red'
          ? null
          : ShipmentLocation(lat: 33.3128, lng: 44.4345, exact: row.status == 'قيد التوصيل', at: row.at),
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
    String governorate = 'بغداد',
    DateTime? until,
    String? returnPlace,
  }) => RecentShipment(
    id: id,
    returnPlace: returnPlace,
    number: 'ZA-${20260121 + id}',
    name: name,
    area: area,
    governorate: governorate,
    city: area,
    until: until,
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
    governorate: s.governorate,
    city: s.city,
    amount: s.amount,
    at: s.at,
    status: s.status,
    urgent: s.urgent,
    tone: s.urgent ? 'urgent' : (s.status == 'مسلمة' ? 'green' : 'blue'),
  );

  // ---------------------------------------------------------------- رحلة شحناتك وتقويمها (docs/plan/87)

  static const _states = {
    'all': 'كل الشحنات',
    'created': 'جديدة',
    'pickup': 'بانتظار المندوب',
    'warehouse': 'بالمخزن',
    'courier': 'مع المندوب',
    'redelivery': 'إعادة توصيل',
    'postponed': 'مؤجلة',
    'processing': 'للمعالجة',
    'delivered': 'مسلمة',
    'return_courier': 'راجع عند المندوب',
    'return_hub': 'راجع في المخزن',
    'returns': 'راجع مؤكد',
  };

  static StatesData states() {
    final total = _all.where((s) => _in(s, 'all')).length;
    return StatesData(
      total: total,
      tiles: [for (final e in _states.entries) StateTile(e.key, e.value, _all.where((s) => _in(s, e.key)).length)],
    );
  }

  static CalendarData calendar({DateTime? from, DateTime? to}) {
    final now = DateTime.now();
    final end = DateUtils.dateOnly(to ?? now);
    final start = DateUtils.dateOnly(from ?? end.subtract(const Duration(days: 6)));
    final days = <CalendarDay>[];
    for (var d = start; !d.isAfter(end); d = DateUtils.addDaysToDate(d, 1)) {
      // أرقامٌ ثابتةٌ لكلّ يوم: لقطة الشاشة لا تتبدّل
      final seed = d.day * 7 + d.month;
      final counts = {
        'delivered': 6 + seed % 9,
        'open': d == end ? 9 : (d.difference(end).inDays > -3 ? 3 : 0),
        'postponed': seed % 3,
        'processing': seed % 2,
        'returned': 1 + seed % 3,
      };
      days.add(CalendarDay(date: d, total: counts.values.fold(0, (a, b) => a + b), counts: counts));
    }
    int sum(String k) => days.fold(0, (a, d) => a + d.counts[k]!);
    final delivered = sum('delivered');
    final returned = sum('returned');
    return CalendarData(
      from: start,
      to: end,
      total: days.fold(0, (a, d) => a + d.total),
      rate: delivered + returned == 0 ? null : (delivered * 100 / (delivered + returned)).round(),
      buckets: [
        StateTile('delivered', 'تم التسليم', delivered),
        StateTile('open', 'قيد التوصيل', sum('open')),
        StateTile('postponed', 'مؤجل', sum('postponed')),
        StateTile('processing', 'للمعالجة', sum('processing')),
        StateTile('returned', 'راجع', returned),
      ],
      days: days,
      byUser: [('أحمد', 31), ('سجى — إدخال', 24)],
    );
  }

  // ---------------------------------------------------------------- موظّفو المتجر (docs/plan/87)

  static const _permissions = [
    StaffPermission('shipments', 'عرض الشحنات', 'قوائم الشحنات وتتبّعها وتقويمها، والبحث فيها'),
    StaffPermission('create', 'إدخال الشحنات', 'شحنةٌ جديدة باليد أو من صورة أو بالصوت، ومن ملف Excel'),
    StaffPermission('print', 'الطابعة', 'طباعة وصولات الشحنات الجديدة'),
    StaffPermission('pickups', 'طلب مندوب', 'طلب مندوبٍ يستلم الشحنات'),
    StaffPermission('processing', 'المعالجة', 'قرار الشحنات التي لم تُسلَّم: إعادة توصيل، تأجيل، إرجاع'),
    StaffPermission('finance', 'المالية', 'الرصيد والكشوفات، وطلب الحساب، واستلام الحساب والراجع'),
    StaffPermission('support', 'الدعم', 'المحادثات مع الشركة'),
  ];

  static const _roles = [
    StaffRole('entry', 'موظّف إدخال', ['create', 'print', 'pickups', 'shipments']),
    StaffRole('accountant', 'محاسب', ['finance', 'shipments']),
    StaffRole('follow', 'متابعة', ['shipments', 'processing', 'support']),
  ];

  static final List<StaffMember> _staff = [
    StaffMember(
      id: 501,
      name: 'سجى علي',
      phone: '07701112233',
      username: '07701112233',
      permissions: const ['shipments', 'create', 'print', 'pickups'],
      role: 'entry',
      active: true,
      lastLogin: DateTime.now().subtract(const Duration(hours: 2)),
    ),
  ];

  static StaffPage staff() => StaffPage(staff: List.of(_staff), permissions: _permissions, roles: _roles);

  static String _role(List<String> perms) {
    for (final r in _roles) {
      if (r.permissions.length == perms.length && r.permissions.every(perms.contains)) return r.key;
    }
    return 'custom';
  }

  static StaffMember saveStaff(Map<String, dynamic> data, {int? id}) {
    final perms = [for (final p in data['permissions'] as List) '$p'];
    if ((data['name'] as String? ?? '').trim().isEmpty) {
      throw ApiError('اكتب اسم الموظّف.', fields: {'name': 'اكتب اسم الموظّف.'});
    }
    if (perms.isEmpty) throw ApiError('اختر صلاحيةً واحدةً على الأقلّ.');
    final i = _staff.indexWhere((s) => s.id == id);
    final member = StaffMember(
      id: id ?? 500 + _staff.length + 2,
      name: '${data['name']}'.trim(),
      phone: '${data['phone']}',
      username: '${data['username'] ?? data['phone']}',
      permissions: perms,
      role: _role(perms),
      active: data['active'] as bool? ?? true,
    );
    if (i >= 0) {
      _staff[i] = member;
    } else {
      _staff.add(member);
    }
    return member;
  }

  static void deleteStaff(int id) => _staff.removeWhere((s) => s.id == id);

  // ---------------------------------------------------------------- اختيار الإشعارات (docs/plan/86)

  static final Map<String, bool> _notify = {};

  static const _notifyKinds = {
    'delivered': 'وصلت شحنتك',
    'failed': 'للمعالجة',
    'processed': 'تمت معالجتها',
    'amount': 'تغيّر مبلغ الوصل',
  };

  static NotifySettings notifySettings() => NotifySettings(
    kinds: [for (final e in _notifyKinds.entries) (key: e.key, label: e.value, on: _notify[e.key] ?? true)],
    sound: _notify['sound'] ?? true,
    devices: 1,
  );

  static NotifySettings saveNotifySettings(Map<String, bool> changes) {
    _notify.addAll(changes);
    return notifySettings();
  }

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
    batchMax: 30,
    batchParallel: 4,
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

  /// دفعة صورٍ في العرض: زبائن مختلفون، وواحدٌ بلا منطقة ليُرى كيف يُكمَل
  static OrderReading readBatch(int i) {
    const people = [
      ('علي حسين', '07712345678', '101', 'الكرادة', 25000),
      ('زينب علي', '07809876543', '102', 'زيونة', 30000),
      ('مصطفى عادل', '07741077999', null, null, 41000),
      ('نور الهدى', '07711112222', '106', 'الدورة', 35000),
      ('سجاد كريم', '07501234567', '100', 'المنصور', 20000),
    ];
    final (name, phone, city, area, amount) = people[i % people.length];
    return OrderReading(
      fields: {
        'recipient_name': name,
        'recipient_phone': phone,
        'governorate_id': 1,
        'city_id': ?city,
        'cod_amount': amount,
      },
      found: {'governorate_id': 'بغداد', 'city_id': ?area},
      missing: [if (city == null) 'المنطقة'],
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
    // نسخة العرض تُري الاستلام بالباركود (docs/plan/69): رمز الكشف K7M2P-QX9RT ورمز الإيصال R3TRN-2BX7Q
    scanRequired: true,
    month: const MonthStats(
      label: 'تشرين الأول',
      delivered: 43,
      returned: 5,
      rate: 90,
      returnRate: 10,
      collected: 1792000,
      growth: 12,
      average: 41700,
      shipments: 50,
      weeks: [380000, 455000, 512000, 445000],
    ),
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

  static StatementDetail statement(int id) {
    final s = finance.statements.firstWhere((s) => s.id == id, orElse: () => finance.statements.first);
    final rows = _all.where((r) => r.tone == 'green' || r.status == 'مسلمة').toList();
    final lines = [
      for (final r in rows)
        StatementLine(
          shipmentId: r.id,
          number: r.number,
          name: r.name,
          returned: false,
          collected: r.amount,
          delivery: 5000,
          returnFee: 0,
          net: r.amount - 5000,
        ),
    ];
    return StatementDetail(
      scanRequired: true,
      statement: s,
      totals: {
        'count': s.count,
        'collected': lines.fold(0, (a, l) => a + l.collected),
        'delivery': lines.fold(0, (a, l) => a + l.delivery),
        'returns': 0,
        'advance': s.advance,
        'net': s.net,
      },
      lines: lines,
    );
  }

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
      RequestsPage(returning: _returning, requests: List.of(_requests), batches: List.of(_batches), scanRequired: true);

  /// رموز الاستلام المطبوعة على أوراق العرض: وصل الكشف MS000041 وإيصال الراجع RB000034
  static const _codes = {'K7M2PQX9RT': ('statement', 1), 'R3TRN2BX7Q': ('returns', 2)};

  static Received receive(String raw) {
    final match = RegExp(r'/rc/([A-Za-z0-9-]+)').firstMatch(raw);
    final code = (match?.group(1) ?? raw).replaceAll(RegExp(r'[\s-]'), '').toUpperCase();
    final doc = _codes[code];
    if (doc == null) {
      throw ApiError(
        'هذا الرمز ليس لورقةٍ من أوراقك. امسح الباركود المطبوع على الوصل الذي وصلك، أو اكتب الرمز الذي تحته.',
      );
    }
    if (doc.$1 == 'statement') {
      if (_confirmed.contains(doc.$2)) throw ApiError('الكشف MS000041 لم يُدفع بعد أو أكّدته سلفاً.');
      _confirmed.add(doc.$2);
      return Received(message: 'أكّدت استلام دفعة الكشف MS000041.', type: 'statement', id: doc.$2);
    }
    final b = _batches.firstWhere((b) => b.id == doc.$2);
    if (b.received != null) throw ApiError('الإيصال ${b.number} مؤكَّدٌ سلفاً.');
    b.received = DateTime.now();
    return Received(message: 'أكّدت استلام رواجع الإيصال ${b.number}.', type: 'returns', id: b.id);
  }

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

  /// «الطابعة»: أربعٌ للطباعة، واثنتان طُبعتا تنتظران المندوب
  static const List<(String, String, int)> _toPrint = [
    ('مريم سعد', 'بغداد · المنصور', 52000),
    ('أحمد ناصر', 'بغداد · الشعب', 41000),
    ('زينب علي', 'النجف · حي الأمير', 30000),
    ('حسين كاظم', 'بغداد · الدورة', 25000),
  ];
  static const List<(String, String, int)> _printed = [
    ('علي حسين', 'بغداد · الأعظمية', 60000),
    ('هدى كريم', 'البصرة · العشار', 35000),
  ];

  static LabelsPage labels([String state = 'to_print']) => LabelsPage(
    state: state,
    toPrint: _toPrint.length,
    printed: _printed.length,
    shipments: [
      for (final (i, (name, place, amount)) in (state == 'printed' ? _printed : _toPrint).indexed)
        LabelRow(
          id: (state == 'printed' ? 950 : 900) + i,
          number: 'ZA-202601${state == 'printed' ? 4 : 3}${i + 1}',
          name: name,
          phone: '0780123456$i',
          place: place,
          amount: amount,
          at: _ago(30 + i * 15),
          printedAt: state == 'printed' ? _ago(10 + i * 5) : null,
        ),
    ],
    // مقاسا التطبيق (docs/plan/87)
    sizes: const [Choice('100x100', 'ملصق ١٠٠×١٠٠ ملم'), Choice('80x120', 'ملصق ١٢٠×٨٠ ملم')],
  );

  static ReturnReceiptDetail returnReceipt(int id) {
    final b = _batches.firstWhere((b) => b.id == id, orElse: () => throw ApiError('الإيصال غير موجود.'));
    const people = [
      ('مريم سعد', 'بغداد · المنصور', 52000, 'الزبون رفض الاستلام'),
      ('أحمد ناصر', 'بغداد · الشعب', 41000, 'الهاتف مغلق'),
      ('زينب علي', 'بغداد · الكرادة', 30000, 'الزبون غير موجود'),
      ('حسين كاظم', 'بغداد · الدورة', 25000, 'العنوان غير صحيح'),
    ];
    return ReturnReceiptDetail(
      scanRequired: true,
      receipt: b,
      lines: [
        for (var i = 0; i < b.count; i++)
          ReturnLine(
            id: 0,
            number: 'ZA-2026011${i + 1}',
            name: people[i % people.length].$1,
            place: people[i % people.length].$2,
            amount: people[i % people.length].$3,
            reason: people[i % people.length].$4,
            fee: b.fees ~/ b.count,
          ),
      ],
    );
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
