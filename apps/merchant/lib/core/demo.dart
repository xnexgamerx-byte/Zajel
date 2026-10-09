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
    stats: const Stats(total: 124, delivered: 86, inDelivery: 28, returns: 6),
    processing: 5,
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
    _row(7, 'مريم سعد', 'المنصور', 52000, 'راجع للتاجر', 'gray', hours: 50),
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
    return ShipmentDetail(
      row: row,
      createdAt: start,
      deliveryCode: row.tone == 'green' ? null : '4821',
      failureReason: row.status == 'للمعالجة' ? 'الزبون لا يرد' : null,
      attempts: row.status == 'للمعالجة' ? 1 : 0,
      trackingUrl: 'https://example.com/t/${row.number}',
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
    number: 'ZA-2026012${id + 1}',
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
    tone: s.urgent ? 'red' : (s.status == 'مسلمة' ? 'green' : 'blue'),
  );

  static DateTime _today(int h, int m) {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day, h, m);
  }
}
