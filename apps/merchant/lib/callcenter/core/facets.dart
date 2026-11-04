// شرائح الفرز في قوائم الكول سنتر (docs/plan/93): ما في القائمة بأعداده، وما اختير منه.

/// عنوان السطر (docs/plan/93): اسم المتجر — به تُعرف الشحنة عند الاتصال — وإلّا الزبون
String storeTitle(String? merchant, String? recipient) {
  final store = (merchant ?? '').trim();
  return store.isNotEmpty ? store : ((recipient ?? '').trim().isEmpty ? '—' : recipient!.trim());
}

/// الزبون باسمه، لا «الزبون» الذي يُكتب حين لا اسم
String? namedRecipient(String? recipient) {
  final r = (recipient ?? '').trim();
  return r.isEmpty || r == 'الزبون' ? null : r;
}

/// المحافظة من «بغداد · الكرادة»
String? governorateOf(String place) {
  final g = place.split('·').first.trim();
  return g.isEmpty ? null : g;
}

/// شريحةٌ في الفرز: مفتاحها، واسمها، وكم فيها
class Facet {
  const Facet(this.key, this.label, this.count);

  factory Facet.fromJson(Map<String, dynamic> j) =>
      Facet('${j['key']}', j['label'] as String? ?? '—', (j['count'] as num?)?.toInt() ?? 0);

  final String key;
  final String label;
  final int count;
}

/// ما في القائمة بأعداده: المحافظات والمناديب والتجّار
class Facets {
  const Facets({this.governorates = const [], this.couriers = const [], this.merchants = const []});

  factory Facets.fromJson(Object? v) {
    final j = v is Map ? v.cast<String, dynamic>() : const <String, dynamic>{};
    List<Facet> list(String key) => [for (final f in (j[key] as List? ?? const [])) Facet.fromJson((f as Map).cast())];
    return Facets(governorates: list('governorates'), couriers: list('couriers'), merchants: list('merchants'));
  }

  /// من القائمة نفسها حين لا يرسلها النظام: المفتاح هو الاسم
  static Facets of<T>(
    Iterable<T> items, {
    String? Function(T)? governorate,
    String? Function(T)? courier,
    String? Function(T)? merchant,
  }) {
    List<Facet> count(String? Function(T)? read) {
      if (read == null) return const [];
      final n = <String, int>{};
      for (final item in items) {
        final v = read(item)?.trim();
        if (v != null && v.isNotEmpty) n[v] = (n[v] ?? 0) + 1;
      }
      final keys = n.keys.toList()..sort((a, b) => n[b]!.compareTo(n[a]!));
      return [for (final k in keys) Facet(k, k, n[k]!)];
    }

    return Facets(governorates: count(governorate), couriers: count(courier), merchants: count(merchant));
  }

  final List<Facet> governorates;
  final List<Facet> couriers;
  final List<Facet> merchants;
}

/// ما اختير: محافظةٌ أو مندوبٌ أو تاجر — بمفاتيح شرائحها
class FilterPick {
  const FilterPick({this.governorate, this.courier, this.merchant});

  final String? governorate;
  final String? courier;
  final String? merchant;

  bool get any => governorate != null || courier != null || merchant != null;

  /// يطابق سطراً قيمه بأسمائها (الفرز في القائمة نفسها)
  bool matches({String? governorate, String? courier, String? merchant}) =>
      (this.governorate == null || this.governorate == governorate?.trim()) &&
      (this.courier == null || this.courier == courier?.trim()) &&
      (this.merchant == null || this.merchant == merchant?.trim());

  FilterPick withGovernorate(String? v) => FilterPick(governorate: v, courier: courier, merchant: merchant);
  FilterPick withCourier(String? v) => FilterPick(governorate: governorate, courier: v, merchant: merchant);
  FilterPick withMerchant(String? v) => FilterPick(governorate: governorate, courier: courier, merchant: v);
}
