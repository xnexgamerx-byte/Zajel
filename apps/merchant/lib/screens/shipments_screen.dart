import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'shipment_screen.dart';

/// «شحناتي»: بحثٌ بالاسم أو الهاتف أو رقم الوصل، وشرائح بأعدادها (هي عدّادات الرئيسية
/// نفسها)، والقائمة تتحمّل صفحةً بعد صفحة كلّما نزلت.
class ShipmentsScreen extends StatefulWidget {
  const ShipmentsScreen({super.key, required this.brand, required this.filter, this.refresh});

  final Brand brand;

  /// الشريحة المطلوبة — تضبطها الرئيسية حين تفتح «مسلمة» أو «للمعالجة»…
  final ValueNotifier<String> filter;

  /// بعد حفظ شحنةٍ جديدة: تُعاد القائمة
  final Listenable? refresh;

  @override
  State<ShipmentsScreen> createState() => _ShipmentsScreenState();
}

class _ShipmentsScreenState extends State<ShipmentsScreen> {
  final search = TextEditingController();
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
    scroll.addListener(() {
      if (scroll.position.pixels > scroll.position.maxScrollExtent - 300) _more();
    });
    _reload();
  }

  @override
  void dispose() {
    widget.filter.removeListener(_reload);
    widget.refresh?.removeListener(_reload);
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
        SizedBox(height: top > 0 ? top + 6 : 34),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 15),
          child: Row(
            children: [
              Text('شحناتي', style: font(20, w8, Palette.ink, height: 1.2)),
              const SizedBox(width: 8),
              if (total > 0) Text('${money(total)} شحنة', style: font(12, w6, Palette.slate)),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: _search()),
        const SizedBox(height: 10),
        SizedBox(height: 34, child: _chips()),
        const SizedBox(height: 6),
        Expanded(child: _list()),
      ],
    );
  }

  Widget _search() => Container(
    height: 44,
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), boxShadow: cardShadow),
    child: TextField(
      controller: search,
      onChanged: _typed,
      textInputAction: TextInputAction.search,
      onSubmitted: (_) => _reload(),
      style: font(13, w6, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحث بالاسم أو الهاتف أو رقم الوصل',
        hintStyle: font(12.5, w5, Palette.slate),
        prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted, size: 21),
        suffixIcon: search.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.close_rounded, size: 18, color: Palette.muted),
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

  Widget _chips() {
    final current = widget.filter.value;
    return ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      itemCount: filters.length,
      separatorBuilder: (_, _) => const SizedBox(width: 6),
      itemBuilder: (_, i) {
        final f = filters[i];
        final on = f.key == current;
        return Tap(
          radius: 17,
          onTap: () => widget.filter.value = f.key,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: on ? brand.main : Colors.white,
              borderRadius: BorderRadius.circular(17),
              border: Border.all(color: on ? brand.main : Palette.line),
            ),
            child: Text.rich(
              TextSpan(
                children: [
                  TextSpan(text: f.label, style: font(12, w7, on ? Colors.white : Palette.ink)),
                  TextSpan(
                    text: '  ${money(f.count)}',
                    style: font(11.5, w8, on ? Colors.white.withValues(alpha: .85) : brand.main),
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
                        style: font(13, w6, Palette.slate, height: 1.6),
                      ),
                    ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _reload,
      child: ListView.separated(
        controller: scroll,
        padding: const EdgeInsets.fromLTRB(14, 4, 14, 110),
        itemCount: items.length + (page < lastPage ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 7),
        itemBuilder: (_, i) => i == items.length
            ? Padding(
                padding: const EdgeInsets.all(12),
                child: Center(child: CircularProgressIndicator(color: brand.main, strokeWidth: 2.4)),
              )
            : _ShipmentCard(brand: brand, row: items[i]),
      ),
    );
  }
}

/// بطاقة شحنةٍ في القائمة — بعناصر صفّ «آخر الشحنات» في الرئيسية، مكبّرةً للّمس
class _ShipmentCard extends StatelessWidget {
  const _ShipmentCard({required this.brand, required this.row});

  final Brand brand;
  final RecentShipment row;

  @override
  Widget build(BuildContext context) => Tap(
    onTap: () => openShipment(context, brand, row.id),
    child: WhiteCard(
      padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
      child: Row(
        children: [
          Expanded(
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
                        style: font(13.5, w8, Palette.ink, height: 1.3),
                      ),
                    ),
                    Text(money(row.amount), style: font(14, w8, brand.main, height: 1.3)),
                    const SizedBox(width: 3),
                    Text('د.ع', style: font(11, w6, Palette.ink, height: 1.3)),
                  ],
                ),
                const SizedBox(height: 3),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        [row.area, when(row.at)].where((t) => t.isNotEmpty).join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: font(11, w5, Palette.slate, height: 1.3),
                      ),
                    ),
                    Text(
                      '#${row.number}',
                      textDirection: TextDirection.ltr,
                      style: font(10.5, w5, Palette.slate, height: 1.3),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          StatusPill(brand: brand, row: row, width: 72, height: 24, size: 10),
          const SizedBox(width: 8),
          const Chev(size: 9),
        ],
      ),
    ),
  );
}
