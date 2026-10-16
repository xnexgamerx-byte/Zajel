import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/cash_card.dart';
import '../widgets/page.dart';

/// رئيسية المندوب (docs/plan/66): ما بيده الآن، والنقد وسقفه، وأرقام اليوم، و«تحتاج انتباهك».
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.brand, required this.onOpen, required this.refresh});

  final Brand brand;

  /// tasks · returns · cash · shipment:رقمها
  final void Function(String screen) onOpen;
  final ValueNotifier<int> refresh;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  HomeData? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.home();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) {
      return Waiting(
        brand: brand,
        error: error,
        onRetry: () {
          setState(() => error = null);
          _load();
        },
      );
    }
    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        color: brand.main,
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 18, 16, 130),
          children: [
            _header(d),
            const SizedBox(height: 16),
            _inHand(d),
            const SizedBox(height: 12),
            CashCard(brand: brand, cash: d.cash, onTap: () => widget.onOpen('cash')),
            const SizedBox(height: 12),
            _counters(d),
            if (d.attention.isNotEmpty) ...[
              SectionTitle('تحتاج انتباهك (${d.attention.length})'),
              WhiteCard(
                child: Column(
                  children: [
                    for (final (i, a) in d.attention.indexed) ...[
                      if (i > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: Palette.line),
                      _attention(a),
                    ],
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _header(HomeData d) => Row(
    children: [
      Container(
        width: 44,
        height: 44,
        alignment: Alignment.center,
        decoration: BoxDecoration(gradient: brand.tile, shape: BoxShape.circle),
        child: Text(d.name.isEmpty ? 'م' : d.name.characters.first, style: font(18, w8, Colors.white, height: 1)),
      ),
      const SizedBox(width: 10),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(d.greeting, style: font(12.5, w6, Palette.slate)),
            Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(17, w8, Palette.ink)),
          ],
        ),
      ),
      if (d.unread > 0)
        Tooltip(
          message: 'إعلانات جديدة',
          child: Tap(
            radius: 21,
            onTap: () => toast(context, 'لديك ${d.unread} إعلانات جديدة — تُقرأ من موقع الشركة حتى تصل للتطبيق.'),
            child: Badge(
              label: Text('${d.unread}'),
              backgroundColor: brand.main,
              child: Container(
                width: 42,
                height: 42,
                decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
                child: const Icon(Icons.notifications_none_rounded, color: Palette.ink, size: 22),
              ),
            ),
          ),
        ),
    ],
  );

  Widget _inHand(HomeData d) => WhiteCard(
    padding: const EdgeInsets.all(16),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('بيدك الآن', style: font(12.5, w6, Palette.slate)),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: '${d.inHand}', style: font(26, w8, Palette.ink)),
                    TextSpan(text: ' شحنة', style: font(15, w7, Palette.ink)),
                  ],
                ),
              ),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: 'للتحصيل ', style: font(12.5, w6, Palette.slate)),
                    TextSpan(text: money(d.toCollect), style: font(12.5, w8, Palette.ink)),
                    TextSpan(text: ' د.ع', style: font(12.5, w6, Palette.slate)),
                  ],
                ),
              ),
            ],
          ),
        ),
        SizedBox(
          height: 40,
          child: FilledButton(
            onPressed: () => widget.onOpen('tasks'),
            style: FilledButton.styleFrom(
              backgroundColor: brand.main,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            child: Text('ابدأ جولتك', style: font(13.5, w8, Colors.white)),
          ),
        ),
      ],
    ),
  );

  Widget _counters(HomeData d) {
    Widget tile(int n, String label, Color color, String screen) => Expanded(
      child: Tap(
        onTap: () => widget.onOpen(screen),
        child: WhiteCard(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
          child: Column(
            children: [
              Text('$n', style: font(20, w8, color)),
              const SizedBox(height: 2),
              FittedBox(child: Text(label, style: font(11.5, w6, Palette.slate))),
            ],
          ),
        ),
      ),
    );

    return Row(
      children: [
        tile(d.inHand, 'للتسليم', Palette.ink, 'tasks'),
        const SizedBox(width: 8),
        tile(d.delivered, 'سُلّمت اليوم', Palette.ok, 'tasks'),
        const SizedBox(width: 8),
        tile(d.failed + d.postponed, 'لم تُسلَّم', Palette.warn, 'tasks'),
        const SizedBox(width: 8),
        tile(d.returns, 'راجع بيدي', Palette.bad, 'returns'),
      ],
    );
  }

  Widget _attention(Attention a) {
    final (label, color) = switch (a.kind) {
      'ticket' => ('تذكرة', Palette.warn),
      'code' => ('كود', Palette.info),
      _ => ('راجع', Palette.bad),
    };
    return Tap(
      onTap: () => widget.onOpen('shipment:${a.id}'),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: [
            Pill(label, color: color),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Number(a.number, size: 14),
                  Text(a.text, style: font(12, w6, Palette.slate, height: 1.4)),
                ],
              ),
            ),
            const Chev(size: 9, stroke: 1.6),
          ],
        ),
      ),
    );
  }
}
