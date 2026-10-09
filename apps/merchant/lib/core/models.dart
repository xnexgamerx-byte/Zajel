/// ما يعيده النظام لرئيسية التاجر: GET /api/v1/merchant/home
class HomeData {
  HomeData({
    required this.greeting,
    required this.name,
    required this.unread,
    required this.banners,
    required this.balance,
    required this.owed,
    required this.stats,
    required this.processing,
    required this.canProcess,
    required this.attention,
    required this.recent,
  });

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final stats = (j['stats'] as Map).cast<String, dynamic>();
    return HomeData(
      greeting: j['greeting'] as String,
      name: j['name'] as String? ?? '',
      unread: j['unread'] as int? ?? 0,
      banners: [for (final b in j['banners'] as List) AdBanner.fromJson((b as Map).cast())],
      balance: j['balance']['amount'] as int,
      owed: j['balance']['owed'] as bool,
      stats: Stats(
        total: stats['total'] as int,
        delivered: stats['delivered'] as int,
        inDelivery: stats['in_delivery'] as int,
        returns: stats['returns'] as int,
      ),
      processing: j['processing']['count'] as int,
      canProcess: j['processing']['allowed'] as bool,
      attention: j['attention']['count'] as int,
      recent: [for (final r in j['recent'] as List) RecentShipment.fromJson((r as Map).cast())],
    );
  }

  final String greeting;
  final String name;
  final int unread;
  final List<AdBanner> banners;
  final int balance;

  /// لك عند الشركة (true) أو عليك لها
  final bool owed;
  final Stats stats;
  final int processing;
  final bool canProcess;
  final int attention;
  final List<RecentShipment> recent;
}

class Stats {
  const Stats({required this.total, required this.delivered, required this.inDelivery, required this.returns});

  final int total;
  final int delivered;
  final int inDelivery;
  final int returns;
}

class AdBanner {
  AdBanner({required this.title, required this.image, this.link});

  factory AdBanner.fromJson(Map<String, dynamic> j) =>
      AdBanner(title: j['title'] as String? ?? '', image: j['image'] as String, link: j['link'] as String?);

  final String title;
  final String image;
  final String? link;
}

class RecentShipment {
  RecentShipment({
    required this.number,
    required this.name,
    required this.area,
    required this.amount,
    required this.at,
    required this.status,
    required this.urgent,
  });

  factory RecentShipment.fromJson(Map<String, dynamic> j) => RecentShipment(
    number: j['number'] as String,
    name: j['name'] as String? ?? '',
    area: j['area'] as String? ?? '',
    amount: j['amount'] as int? ?? 0,
    at: DateTime.tryParse(j['at'] as String? ?? '')?.toLocal(),
    status: j['status'] as String,
    urgent: j['urgent'] as bool? ?? false,
  );

  final String number;
  final String name;
  final String area;
  final int amount;
  final DateTime? at;
  final String status;
  final bool urgent;
}

/// الحساب بعد الدخول: من هو، ولأيّ شركة — اسمها ولونها وشعارها
class Session {
  Session({required this.name, required this.companyName, this.companyColor, this.companyLogo, this.companyInitial});

  factory Session.fromJson(Map<String, dynamic> j) => Session(
    name: j['user']['name'] as String,
    companyName: j['company']['name'] as String,
    companyColor: j['company']['color'] as String?,
    companyLogo: j['company']['logo'] as String?,
    companyInitial: j['company']['initial'] as String?,
  );

  final String name;
  final String companyName;
  final String? companyColor;
  final String? companyLogo;
  final String? companyInitial;
}
