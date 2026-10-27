import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'shipment_screen.dart';

/// حالةٌ من «حالة الشحنات» في الرئيسية (docs/plan/79): اسمها ولونها وأيقونتها، وما فيها بكلامه
class StatusView {
  const StatusView(this.filter, this.title, this.text, this.icon, this.color);

  /// شريحة «شحناتي» نفسها: الرقم على البطاقة عددُ ما في القائمة
  final String filter;
  final String title;
  final String text;
  final IconData icon;
  final Color color;

  static const blue = Color(0xFF2F6DB5);
  static const slate = Color(0xFF5C4644);

  static StatusView of(String filter) => switch (filter) {
    'delivered' => const StatusView(
      'delivered',
      'مسلّمة',
      'وصلت إلى زبائنك',
      Icons.check_circle_outline_rounded,
      Palette.ok,
    ),
    'attention' => const StatusView(
      'attention',
      'تحتاج انتباهك',
      'مؤجّلةٌ إلى موعدها، أو راجعةٌ لم تصل المخزن بعد',
      Icons.schedule_rounded,
      Palette.postponed,
    ),
    'returns' => const StatusView(
      'returns',
      'راجع مؤكّد',
      'أكّدت الشركة رجوعها، وتُسلَّم لك في إيصال الراجع',
      Icons.assignment_return_outlined,
      Palette.returnRed,
    ),
    // خانات «رحلة شحناتك اليوم» (docs/plan/87)
    'all' => const StatusView('all', 'كل الشحنات', 'كلّ ما لم ينتهِ معك بعد', Icons.inventory_2_outlined, slate),
    'created' => const StatusView('created', 'جديدة', 'أدخلتها ولم يُطلب لها مندوب', Icons.fiber_new_rounded, slate),
    'pickup' => const StatusView('pickup', 'بانتظار المندوب', 'ينتظر مندوب الاستلام', Icons.hail_rounded, slate),
    'new' => const StatusView('new', 'جديدة', 'لم يستلمها مندوبٌ بعد', Icons.fiber_new_rounded, slate),
    'warehouse' => const StatusView(
      'warehouse',
      'بالمخزن',
      'استُلمت منك، وهي في مخزننا أو بين مراكزنا',
      Icons.warehouse_outlined,
      blue,
    ),
    'courier' => const StatusView('courier', 'مع المندوب', 'خرجت للتوصيل اليوم', Icons.two_wheeler_rounded, blue),
    'redelivery' => const StatusView(
      'redelivery',
      'إعادة توصيل',
      'لم تُسلَّم أوّل مرّة، وتُوصَل ثانيةً',
      Icons.replay_rounded,
      blue,
    ),
    'postponed' => const StatusView(
      'postponed',
      'مؤجلة',
      'اتّفقنا مع الزبون على موعدٍ آخر',
      Icons.event_rounded,
      Palette.postponed,
    ),
    'processing' => const StatusView(
      'processing',
      'للمعالجة',
      'لم تُسلَّم، وتنتظر قرار المتابعة',
      Icons.support_agent_rounded,
      Palette.returnRed,
    ),
    'return_courier' => const StatusView(
      'return_courier',
      'راجع عند المندوب',
      'لم يصل مخزننا بعد',
      Icons.two_wheeler_rounded,
      Palette.returnRed,
    ),
    'return_hub' => const StatusView(
      'return_hub',
      'راجع في المخزن',
      'وصل مخزننا، أو في طريقه إلى فرعك',
      Icons.warehouse_outlined,
      Palette.returnRed,
    ),
    _ => const StatusView(
      'open',
      'قيد التوصيل',
      'استلمها المندوب، وهي في طريقها إلى زبائنك',
      Icons.local_shipping_outlined,
      blue,
    ),
  };
}

Future<void> openStatus(BuildContext context, Brand brand, String filter) => Navigator.of(context).push(
  MaterialPageRoute(
    builder: (_) => StatusScreen(brand: brand, view: StatusView.of(filter)),
  ),
);

/// قائمة حالةٍ وحدها (docs/plan/79): رأسٌ بلونها وعددها، وبحثٌ فيها، وبطاقاتٌ واضحة — غير «شحناتي»
/// بشرائحها كلّها. تتحمّل صفحةً بعد صفحة كلّما نزل.
class StatusScreen extends StatefulWidget {
  const StatusScreen({super.key, required this.brand, required this.view});

  final Brand brand;
  final StatusView view;

  @override
  State<StatusScreen> createState() => _StatusScreenState();
}

class _StatusScreenState extends State<StatusScreen> {
  final search = TextEditingController();
  final scroll = ScrollController();
  Timer? debounce;

  List<RecentShipment> items = [];
  int page = 1;
  int lastPage = 1;
  int? total;
  bool loading = false;
  String? error;
  int ticket = 0;

  /// محافظةٌ واحدة من الشريحة (docs/plan/86)، ومحافظاتها بأعدادها
  int? governorate;
  List<GovernorateCount> governorates = [];

  StatusView get view => widget.view;
  Color get color => view.color;

  @override
  void initState() {
    super.initState();
    scroll.addListener(() {
      if (scroll.position.pixels > scroll.position.maxScrollExtent - 300) _more();
    });
    _fetch(1);
  }

  @override
  void dispose() {
    debounce?.cancel();
    search.dispose();
    scroll.dispose();
    super.dispose();
  }

  Future<void> _reload() => _fetch(1);

  Future<void> _more() async {
    if (!loading && page < lastPage) await _fetch(page + 1);
  }

  Future<void> _fetch(int p) async {
    final mine = ++ticket;
    setState(() => (loading = true, error = null));
    try {
      final r = await Api.instance.shipments(
        filter: view.filter,
        q: search.text.trim(),
        page: p,
        governorateId: governorate,
      );
      if (!mounted || mine != ticket) return;
      setState(() {
        items = p == 1 ? r.items : [...items, ...r.items];
        page = r.page;
        lastPage = r.lastPage;
        if (p == 1) governorates = r.governorates;
        // العدد في الرأس عدد الحالة كلّها — أو محافظتها إن اختيرت — لا ما طابق البحث
        final whole = r.filters.where((f) => f.key == view.filter).firstOrNull?.count ?? r.total;
        total = governorate == null
            ? whole
            : governorates.where((g) => g.id == governorate).firstOrNull?.count ?? r.total;
      });
    } on ApiError catch (e) {
      if (mounted && mine == ticket) setState(() => error = e.message);
    } finally {
      if (mounted && mine == ticket) setState(() => loading = false);
    }
  }

  void _typed(String _) {
    debounce?.cancel();
    debounce = Timer(const Duration(milliseconds: 400), _reload);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Palette.bg,
    body: Column(
      children: [
        _hero(context),
        Transform.translate(
          offset: const Offset(0, -22),
          child: Column(
            children: [
              Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: _search()),
              // بمحافظة (docs/plan/86): تظهر حين في الشريحة أكثر من محافظة
              if (governorates.length > 1 || governorate != null) ...[const SizedBox(height: 8), _governorates()],
            ],
          ),
        ),
        Expanded(child: _list()),
      ],
    ),
  );

  /// الرأس بلون الحالة: رجوعٌ، وأيقونتها في دائرة، واسمها وكلامها، وعددها كبيراً
  Widget _hero(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final deep = Color.lerp(color, Colors.black, .28)!;
    return Container(
      padding: EdgeInsets.fromLTRB(16, top + 8, 16, 38),
      decoration: BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [color, deep]),
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Tooltip(
                message: 'رجوع',
                child: Tap(
                  radius: 18,
                  onTap: () => Navigator.of(context).pop(),
                  child: Container(
                    width: 36,
                    height: 36,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .18), shape: BoxShape.circle),
                    child: const Chev(size: 11, stroke: 1.8, color: Colors.white),
                  ),
                ),
              ),
              const Spacer(),
              Text('رحلة شحناتك اليوم', style: font(12, w7, Colors.white.withValues(alpha: .85))),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .18),
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white.withValues(alpha: .35)),
                ),
                child: Icon(view.icon, color: Colors.white, size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(view.title, style: display(20, w8, Colors.white, height: 1.25)),
                    Text(
                      view.text,
                      maxLines: 2,
                      style: font(11.5, w6, Colors.white.withValues(alpha: .88), height: 1.45),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Column(
                children: [
                  Text(total == null ? '—' : money(total!), style: display(28, w8, Colors.white, height: 1.1)),
                  Text('شحنة', style: font(11, w7, Colors.white.withValues(alpha: .85))),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _search() => Container(
    height: 44,
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: cardShadow),
    child: TextField(
      controller: search,
      onChanged: _typed,
      textInputAction: TextInputAction.search,
      style: font(13, w6, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحث في «${view.title}» بالاسم أو الهاتف أو الرقم',
        hintStyle: font(12, w5, Palette.muted),
        prefixIcon: Icon(Icons.search_rounded, color: color, size: 21),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
      ),
    ),
  );

  /// شرائح المحافظات: «كل المحافظات»، ثم كلّ محافظةٍ بعددها — الأكثر أوّلاً
  Widget _governorates() {
    final all = governorates.fold<int>(0, (n, g) => n + g.count);
    Widget chip(String label, bool on, VoidCallback onTap) => Padding(
      padding: const EdgeInsetsDirectional.only(end: 6),
      child: ChoiceChip(
        selected: on,
        showCheckmark: false,
        onSelected: (_) => onTap(),
        selectedColor: color,
        backgroundColor: Colors.white,
        side: BorderSide(color: on ? color : Palette.line),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        visualDensity: VisualDensity.compact,
        label: Text(label, style: font(12, w7, on ? Colors.white : Palette.ink)),
      ),
    );
    void pick(int? id) {
      if (governorate == id) return;
      setState(() => governorate = id);
      _reload();
    }

    return SizedBox(
      height: 38,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        children: [
          chip(
            governorate == null ? 'كل المحافظات ${money(all)}' : 'كل المحافظات',
            governorate == null,
            () => pick(null),
          ),
          for (final g in governorates) chip('${g.name} ${money(g.count)}', governorate == g.id, () => pick(g.id)),
        ],
      ),
    );
  }

  Widget _list() {
    if (items.isEmpty) {
      return RefreshIndicator(
        color: color,
        onRefresh: _reload,
        child: ListView(
          padding: const EdgeInsets.symmetric(horizontal: 30),
          children: [
            const SizedBox(height: 40),
            if (loading)
              Center(child: CircularProgressIndicator(color: color))
            else ...[
              Icon(error != null ? Icons.wifi_off_rounded : view.icon, size: 46, color: color.withValues(alpha: .45)),
              const SizedBox(height: 10),
              Text(
                error ?? (search.text.isEmpty ? 'لا شحنات ${view.title} الآن.' : 'لا شحنة تطابق «${search.text}».'),
                textAlign: TextAlign.center,
                style: font(13, w6, Palette.slate, height: 1.6),
              ),
            ],
          ],
        ),
      );
    }

    return RefreshIndicator(
      color: color,
      onRefresh: _reload,
      child: ListView.separated(
        controller: scroll,
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 28),
        itemCount: items.length + (page < lastPage ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (_, i) => i == items.length
            ? Padding(
                padding: const EdgeInsets.all(12),
                child: Center(child: CircularProgressIndicator(color: color, strokeWidth: 2.4)),
              )
            : _card(items[i]),
      ),
    );
  }

  /// بطاقة الشحنة: شريطٌ بلون حالتها، والاسم والمبلغ، والمكان، والوقت والرقم، وشارة الحالة
  Widget _card(RecentShipment row) {
    final tone = StatusPill.colorOf(widget.brand, row);
    return Tap(
      radius: 18,
      shadow: cardShadow,
      onTap: () => openShipment(context, widget.brand, row.id),
      child: Ink(
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                width: 5,
                margin: const EdgeInsets.symmetric(vertical: 12),
                decoration: BoxDecoration(
                  color: tone,
                  borderRadius: const BorderRadiusDirectional.horizontal(end: Radius.circular(4))
                      .resolve(Directionality.of(context)),
                ),
              ),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              row.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: font(14, w8, Palette.ink, height: 1.3),
                            ),
                          ),
                          StatusPill(brand: widget.brand, row: row, width: 74, height: 22, size: 10),
                        ],
                      ),
                      const SizedBox(height: 4),
                      if (row.place.isNotEmpty)
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(Icons.location_on_outlined, size: 14, color: tone),
                            const SizedBox(width: 3),
                            Expanded(child: Text(row.place, style: font(12, w6, Palette.slate, height: 1.35))),
                          ],
                        ),
                      // موعد المؤجّلة (docs/plan/86)
                      if (row.until != null) ...[const SizedBox(height: 5), PostponedChip(row.until!)],
                      // أين الراجع الآن (docs/plan/87)
                      if (row.returnPlace != null) ReturnPlace(row.returnPlace!),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                            decoration: BoxDecoration(color: Palette.eye, borderRadius: BorderRadius.circular(8)),
                            child: Text(
                              '#${row.number}',
                              textDirection: TextDirection.ltr,
                              style: font(10.5, w7, Palette.slate, height: 1.3),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              when(row.at),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: font(10.5, w5, Palette.muted, height: 1.3),
                            ),
                          ),
                          Text(money(row.amount), style: display(15, w8, Palette.ink, height: 1.2)),
                          const SizedBox(width: 3),
                          Text('د.ع', style: font(10.5, w6, Palette.slate, height: 1.2)),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
