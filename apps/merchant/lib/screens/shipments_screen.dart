import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import '../widgets/rows.dart';
import 'shipment_screen.dart';

/// «الشحنات» (docs/plan/123): بحثٌ بالاسم أو الهاتف أو رقم الوصل، وشريط الحالات بألوانها وأعدادها (هي عدّادات
/// الرئيسية نفسها)، والشحنات بأيّامها صفوفاً شفّافةً بلون حالتها حتى تُفتح. تتحمّل صفحةً بعد صفحة كلّما نزلت.
class ShipmentsScreen extends StatefulWidget {
  const ShipmentsScreen({super.key, required this.brand, required this.filter, this.refresh, this.focusSearch});

  final Brand brand;

  /// الشريحة المطلوبة — تضبطها الرئيسية حين تفتح «مسلمة» أو «للمعالجة»…
  final ValueNotifier<String> filter;

  /// بعد حفظ شحنةٍ جديدة: تُعاد القائمة
  final Listenable? refresh;

  /// بحث الرئيسية (docs/plan/76): يُفتح هنا والمؤشّر في خانة البحث
  final Listenable? focusSearch;

  @override
  State<ShipmentsScreen> createState() => _ShipmentsScreenState();
}

class _ShipmentsScreenState extends State<ShipmentsScreen> {
  final search = TextEditingController();
  final searchFocus = FocusNode();
  final scroll = ScrollController();
  Timer? debounce;

  List<ShipmentFilter> filters = [];
  List<RecentShipment> items = [];
  int page = 1;
  int lastPage = 1;
  int total = 0;
  bool loading = false;
  String? error;

  /// رقم الطلب الجاري: ردٌّ متأخّر لبحثٍ قديم لا يكتب فوق الأحدث
  int ticket = 0;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.filter.addListener(_reload);
    widget.refresh?.addListener(_reload);
    widget.focusSearch?.addListener(_focus);
    scroll.addListener(() {
      if (scroll.position.pixels > scroll.position.maxScrollExtent - 300) _more();
    });
    _reload();
  }

  @override
  void dispose() {
    widget.filter.removeListener(_reload);
    widget.refresh?.removeListener(_reload);
    widget.focusSearch?.removeListener(_focus);
    debounce?.cancel();
    searchFocus.dispose();
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
      final r = await Api.instance.shipments(filter: widget.filter.value, q: search.text.trim(), page: p);
      if (!mounted || mine != ticket) return;
      setState(() {
        filters = r.filters;
        items = p == 1 ? r.items : [...items, ...r.items];
        page = r.page;
        lastPage = r.lastPage;
        total = r.total;
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
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Column(
      children: [
        SizedBox(height: top > 0 ? top + 8 : 36),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              const TabBack(),
              Text('الشحنات', style: kf(24, Palette.ink, bold: true)),
              const Spacer(),
              if (total > 0) Text(_count(total), style: kf(12, Palette.muted)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: _search()),
        const SizedBox(height: 12),
        SizedBox(height: 40, child: _chips()),
        const SizedBox(height: 4),
        Expanded(child: GridBackdrop(child: _list())),
      ],
    );
  }

  /// «30 شحنة» بتمييزها العربي
  static String _count(int n) => switch (n) {
    1 => 'شحنة واحدة',
    2 => 'شحنتان',
    >= 3 && <= 10 => '$n شحنات',
    _ => '${money(n)} شحنة',
  };

  void _focus() => WidgetsBinding.instance.addPostFrameCallback((_) {
    if (mounted) searchFocus.requestFocus();
  });

  Widget _search() => Container(
    height: 48,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: search,
      focusNode: searchFocus,
      onChanged: _typed,
      textInputAction: TextInputAction.search,
      onSubmitted: (_) => _reload(),
      style: kf(14, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحث بالاسم أو الهاتف أو رقم الوصل',
        hintStyle: kf(14, const Color(0xFF8C8286)),
        prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted, size: 20),
        suffixIcon: search.text.isEmpty
            ? null
            : IconButton(
                tooltip: 'امسح البحث',
                icon: const Icon(Icons.close_rounded, size: 16, color: Palette.muted),
                onPressed: () {
                  search.clear();
                  _reload();
                },
              ),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
      ),
    ),
  );

  /// لون الشريحة: لون حالتها، و«الكل» بالحبر
  static Tone _tone(String key) => switch (key) {
    'new' => Tone.slate,
    'open' => Tone.blue,
    'warehouse' => Tone.teal,
    'delivered' => Tone.green,
    'processing' => Tone.orange,
    'attention' => Tone.amber,
    'returns' => Tone.red,
    _ => const Tone(Palette.ink, Color(0xFFECE6E8)),
  };

  /// شريط الحالات (docs/plan/123): نقطةٌ بلون الحالة واسمها وعدّادها، والمختارة تمتلئ بلونها
  Widget _chips() {
    final current = widget.filter.value;
    return ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: filters.length,
      separatorBuilder: (_, _) => const SizedBox(width: 8),
      itemBuilder: (_, i) {
        final f = filters[i];
        final on = f.key == current;
        final tone = _tone(f.key);
        return Semantics(
          selected: on,
          button: true,
          label: '${f.label} ${money(f.count)}',
          excludeSemantics: true,
          onTap: () => widget.filter.value = f.key,
          child: Tap(
            radius: 20,
            onTap: () => widget.filter.value = f.key,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              padding: const EdgeInsetsDirectional.fromSTEB(12, 0, 6, 0),
              decoration: BoxDecoration(
                color: on ? tone.ink : Colors.white,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: on ? tone.ink : Palette.line),
                boxShadow: on
                    ? [
                        BoxShadow(
                          color: tone.ink.withValues(alpha: .6),
                          blurRadius: 16,
                          spreadRadius: -10,
                          offset: const Offset(0, 8),
                        ),
                      ]
                    : null,
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 8,
                    height: 8,
                    decoration: BoxDecoration(color: on ? Colors.white : tone.ink, shape: BoxShape.circle),
                  ),
                  const SizedBox(width: 8),
                  Text(f.label, style: kf(14, on ? Colors.white : Palette.ink, bold: true, height: 1.3)),
                  const SizedBox(width: 8),
                  Container(
                    constraints: const BoxConstraints(minWidth: 28),
                    height: 28,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: on ? Colors.white.withValues(alpha: .22) : tone.tint,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Text(money(f.count), style: kf(12, on ? Colors.white : tone.ink, bold: true, height: 1)),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _list() {
    if (items.isEmpty) {
      return RefreshIndicator(
        color: brand.main,
        onRefresh: _reload,
        child: ListView(
          children: [
            const SizedBox(height: 80),
            Center(
              child: loading
                  ? CircularProgressIndicator(color: brand.main)
                  : Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 30),
                      child: Text(
                        error ?? (search.text.isEmpty ? 'لا شحنات هنا.' : 'لا شحنة تطابق «${search.text}».'),
                        textAlign: TextAlign.center,
                        style: kf(14, Palette.slate),
                      ),
                    ),
            ),
          ],
        ),
      );
    }

    // بالأيّام كما في «سجلّ» وصل: اليوم، أمس، ثم بتاريخها
    final entries = dayEntries(items);
    return RefreshIndicator(
      color: brand.main,
      onRefresh: _reload,
      // شريحةٌ أخرى قائمةٌ جديدة: تظهر صفوفها واحداً بعد واحد
      child: ListView.builder(
        key: ValueKey(widget.filter.value),
        controller: scroll,
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 120),
        itemCount: entries.length + (page < lastPage ? 1 : 0),
        itemBuilder: (_, i) {
          if (i == entries.length) {
            return Padding(
              padding: const EdgeInsets.all(12),
              child: Center(child: CircularProgressIndicator(color: brand.main, strokeWidth: 2.4)),
            );
          }
          final e = entries[i];
          if (e is String) return DayHeading(e);
          final row = e as RecentShipment;
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Rise(
              delay: 40 * (i.clamp(0, 10)),
              child: ShipmentRow(row: row, onTap: () => openShipment(context, brand, row.id)),
            ),
          );
        },
      ),
    );
  }
}
