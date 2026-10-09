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

  static DateTime _today(int h, int m) {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day, h, m);
  }
}
