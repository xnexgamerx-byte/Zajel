import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/art.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import '../widgets/rows.dart';
import 'shipment_screen.dart';

/// حالةٌ من «حالة الشحنات» في الرئيسية (docs/plan/79): اسمها ولونها ورسمها، وما فيها بكلامه. وفي «وصل»
/// (docs/plan/123) لونها لون الحالة (Tone) في كلّ الشركات، ورسمها من رسوم الحالات
class StatusView {
  const StatusView(this.filter, this.title, this.text, this.icon, this.tone, this.art);

  /// شريحة «شحناتي» نفسها: الرقم على البطاقة عددُ ما في القائمة
  final String filter;
  final String title;
  final String text;
  final IconData icon;
  final Tone tone;

  /// اسم رسمها في رسوم «وصل»
  final String art;

  Color get color => tone.ink;

  static StatusView of(String filter) => switch (filter) {
    'delivered' => const StatusView(
      'delivered',
      'مسلّمة',
      'وصلت إلى زبائنك',
      Icons.check_circle_outline_rounded,
      Tone.green,
      'delivered',
    ),
    'attention' => const StatusView(
      'attention',
      'تحتاج انتباهك',
      'مؤجّلةٌ إلى موعدها، أو راجعةٌ لم تصل المخزن بعد',
      Icons.schedule_rounded,
      Tone.amber,
      'postponed',
    ),
    'returns' => const StatusView(
      'returns',
      'راجع مؤكّد',
      'أكّدت الشركة رجوعها، وتُسلَّم لك في إيصال الراجع',
      Icons.assignment_return_outlined,
      Tone.red,
      'returns',
    ),
    // «راجعة» في الرئيسية (docs/plan/123): كلّ ما في طريقه إليك
    'returning' => const StatusView(
      'returning',
      'راجعة',
      'لم تُسلَّم، وتعود إليك: مع المندوب، أو في مخزنٍ، أو في الطريق إلى فرعك',
      Icons.assignment_return_outlined,
      Tone.red,
      'returnsAll',
    ),
    // خانات «رحلة شحناتك اليوم» (docs/plan/87)
    'all' => const StatusView(
      'all',
      'كل الشحنات',
      'كلّ ما لم ينتهِ معك بعد',
      Icons.inventory_2_outlined,
      Tone.slate,
      'warehouse',
    ),
    'created' => const StatusView(
      'created',
      'جديدة',
      'أدخلتها ولم يُطلب لها مندوب',
      Icons.fiber_new_rounded,
      Tone.slate,
      'created',
    ),
    'pickup' => const StatusView(
      'pickup',
      'بانتظار المندوب',
      'ينتظر مندوب الاستلام',
      Icons.hail_rounded,
      Tone.slate,
      'pickup',
    ),
    'new' => const StatusView('new', 'جديدة', 'لم يستلمها مندوبٌ بعد', Icons.fiber_new_rounded, Tone.slate, 'created'),
    'warehouse' => const StatusView(
      'warehouse',
      'بالمخزن',
      'استُلمت منك، وهي في مخزننا أو بين مراكزنا',
      Icons.warehouse_outlined,
      Tone.teal,
      'warehouse',
    ),
    'courier' => const StatusView(
      'courier',
      'مع المندوب',
      'خرجت مع المندوب في طريقها إلى زبونها',
      Icons.two_wheeler_rounded,
      Tone.blue,
      'courier',
    ),
    'redelivery' => const StatusView(
      'redelivery',
      'إعادة توصيل',
      'لم تُسلَّم أوّل مرّة، وتُوصَل ثانيةً',
      Icons.replay_rounded,
      Tone.blue,
      'redelivery',
    ),
    'postponed' => const StatusView(
      'postponed',
      'مؤجلة',
      'اتّفقنا مع الزبون على موعدٍ آخر',
      Icons.event_rounded,
      Tone.amber,
      'postponed',
    ),
    'processing' => const StatusView(
      'processing',
      'للمعالجة',
      'لم تُسلَّم، وتنتظر قرارك',
      Icons.support_agent_rounded,
      Tone.orange,
      'processing',
    ),
    'return_courier' => const StatusView(
      'return_courier',
      'راجع عند المندوب',
      'مع المندوب، في طريقها إلى المخزن',
      Icons.two_wheeler_rounded,
      Tone.red,
      'return_courier',
    ),
    'return_hub' => const StatusView(
      'return_hub',
      'راجع في المخزن',
      'وصل مخزننا، أو في طريقه إلى فرعك',
      Icons.warehouse_outlined,
      Tone.red,
      'return_hub',
    ),
    _ => const StatusView(
      'open',
      'قيد التوصيل',
      'استلمها المندوب، وهي في طريقها إلى زبائنك',
      Icons.local_shipping_outlined,
      Tone.blue,
      'courier',
    ),
  };
}

Future<void> openStatus(BuildContext context, Brand brand, String filter) => Navigator.of(context).push(
  MaterialPageRoute(
    builder: (_) => StatusScreen(brand: brand, view: StatusView.of(filter)),
  ),
);

/// قائمة حالةٍ وحدها (docs/plan/79، 123): رأسٌ بلونها ورسمها وعددها، ثم ورقةٌ منقوشة فيها بحثٌ وشحناتها بأيّامها —
/// صفوف «سجلّ» نفسها التي في «الشحنات». تتحمّل صفحةً بعد صفحة كلّما نزل.
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

  StatusView get view => widget.view;
  Color get color => view.color;

  @override
  void initState() {
    super.initState();
    scroll.addListener(() {
      if (scroll.position.pixels > scroll.position.maxScrollExtent - 300) _more();
    });
    _fetch(1);
    // حُذفت شحنةٌ من تعديلها (docs/plan/117): لا تبقى في القائمة
    shipmentsChanged.addListener(_reload);
  }

  @override
  void dispose() {
    shipmentsChanged.removeListener(_reload);
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
      final r = await Api.instance.shipments(filter: view.filter, q: search.text.trim(), page: p);
      if (!mounted || mine != ticket) return;
      setState(() {
        items = p == 1 ? r.items : [...items, ...r.items];
        page = r.page;
        lastPage = r.lastPage;
        // العدد في الرأس عدد الحالة كلّها، لا ما طابق البحث
        total = r.filters.where((f) => f.key == view.filter).firstOrNull?.count ?? r.total;
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
    backgroundColor: view.tone.tint,
    body: Column(
      children: [
        _hero(context),
        Expanded(
          child: Rise(
            delay: 180,
            dy: 64,
            child: ClipRRect(
              borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
              child: ColoredBox(
                color: Palette.bg,
                child: GridBackdrop(
                  child: Column(
                    children: [
                      // وشرائح المحافظات صارت في الكول سنتر (docs/plan/93)
                      Padding(padding: const EdgeInsets.fromLTRB(16, 16, 16, 4), child: _search()),
                      Expanded(child: _list()),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    ),
  );

  /// الرأس بلون الحالة الفاتح: رجوعٌ، ورسمها كبيراً، واسمها وعددها وما فيها
  Widget _hero(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final n = total;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, (top > 0 ? top : 28) + 8, 16, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SquareButton(tip: 'رجوع', onTap: () => Navigator.of(context).pop(), child: const BackArrow()),
              const Spacer(),
              Text('حالة شحناتك', style: kf(12, view.tone.ink, bold: true)),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Rise(delay: 120, scale: true, child: Art(view.art, brand: widget.brand, width: 136, height: 119)),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(view.title, style: kf(24, Palette.ink, bold: true)),
                    Text(n == null ? '…' : _count(n), style: kf(16, view.tone.ink, bold: true)),
                    const SizedBox(height: 2),
                    Text(view.text, style: kf(14, Palette.slate)),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// «12 شحنة» بتمييزها العربي
  static String _count(int n) => switch (n) {
    0 => 'لا شحنات',
    1 => 'شحنة واحدة',
    2 => 'شحنتان',
    >= 3 && <= 10 => '$n شحنات',
    _ => '${money(n)} شحنة',
  };

  Widget _search() => Container(
    height: 48,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: search,
      onChanged: _typed,
      textInputAction: TextInputAction.search,
      style: kf(14, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحث في «${view.title}» بالاسم أو الهاتف أو الرقم',
        hintStyle: kf(14, const Color(0xFF8C8286)),
        prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted, size: 22),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
      ),
    ),
  );

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
                style: kf(14, Palette.slate),
              ),
            ],
          ],
        ),
      );
    }

    final entries = dayEntries(items);
    return RefreshIndicator(
      color: color,
      onRefresh: _reload,
      child: ListView.builder(
        controller: scroll,
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 28),
        itemCount: entries.length + (page < lastPage ? 1 : 0),
        itemBuilder: (_, i) {
          if (i == entries.length) {
            return Padding(
              padding: const EdgeInsets.all(12),
              child: Center(child: CircularProgressIndicator(color: color, strokeWidth: 2.4)),
            );
          }
          final e = entries[i];
          if (e is String) return DayHeading(e);
          final row = e as RecentShipment;
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Rise(
              delay: 40 * (i.clamp(0, 10)),
              child: ShipmentRow(row: row, onTap: () => openShipment(context, widget.brand, row.id)),
            ),
          );
        },
      ),
    );
  }
}
