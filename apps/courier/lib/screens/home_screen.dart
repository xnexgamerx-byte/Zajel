import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/cash_card.dart';
import '../widgets/page.dart';

/// رئيسية المندوب (docs/plan/66، 71): ما بيده الآن، والنقد وسقفه، وأرقام اليوم، واختصارات «اليوم» و«الاستلام»
/// و«حصصي» و«المكتب»، و«تحتاج انتباهك».
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.brand, required this.onOpen, required this.refresh});

  final Brand brand;

  /// tasks · returns · cash · today · pickups · shares · notifications · chat · shipment:رقمها
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
    final top = MediaQuery.paddingOf(context).top;
    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 8 : 36, 16, 130),
        children: [
          _header(d),
          const SizedBox(height: 16),
          if (d.delivers) ...[
            _inHand(d),
            const SizedBox(height: 12),
            CashCard(brand: brand, cash: d.cash, onTap: () => widget.onOpen('cash')),
            const SizedBox(height: 12),
            _counters(d),
            const SizedBox(height: 12),
          ],
          _shortcuts(d),
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
    );
  }

  Widget _header(HomeData d) => Row(
    children: [
      Container(
        width: 46,
        height: 46,
        alignment: Alignment.center,
        decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle, boxShadow: brand.glow(.35)),
        child: Text(d.name.isEmpty ? 'م' : d.name.characters.first, style: display(18, w7, Colors.white, height: 1)),
      ),
      const SizedBox(width: 10),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(d.greeting, style: font(12.5, w6, Palette.slate)),
            Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: display(17, w7, Palette.ink)),
          ],
        ),
      ),
      if (d.chat) ...[
        _round(Icons.forum_outlined, 'المحادثة مع المكتب', d.chatUnread ? '•' : null, () => widget.onOpen('chat')),
        const SizedBox(width: 8),
      ],
      _round(
        Icons.notifications_none_rounded,
        'الإشعارات',
        d.unread > 0 ? (d.unread > 9 ? '9+' : '${d.unread}') : null,
        () => widget.onOpen('notifications'),
      ),
    ],
  );

  /// زرٌّ دائريّ أبيض في الرأس، بشارةٍ حمراء إن كان فيه جديد
  Widget _round(IconData icon, String tip, String? badge, VoidCallback onTap) => Tooltip(
    message: tip,
    child: Tap(
      radius: 21,
      onTap: onTap,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: Palette.line),
              boxShadow: cardShadow,
            ),
            child: Icon(icon, color: Palette.ink, size: 21),
          ),
          if (badge != null)
            PositionedDirectional(
              top: -2,
              end: -2,
              child: Container(
                constraints: const BoxConstraints(minWidth: 18),
                height: 18,
                padding: const EdgeInsets.symmetric(horizontal: 4),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: brand.main,
                  borderRadius: BorderRadius.circular(9),
                  border: Border.all(color: Colors.white, width: 1.5),
                ),
                child: Text(badge == '•' ? '' : badge, style: font(10, w8, Colors.white, height: 1)),
              ),
            ),
        ],
      ),
    ),
  );

  /// ما بيده الآن بتدرّج لون الشركة (تصميم ز)، وزرّ «ابدأ جولتك» أبيض
  Widget _inHand(HomeData d) => Container(
    padding: const EdgeInsets.fromLTRB(18, 16, 16, 16),
    decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(24), boxShadow: brand.glow(.5)),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('بيدك الآن', style: font(12.5, w6, Colors.white.withValues(alpha: .88))),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: '${d.inHand}', style: display(30, w7, Colors.white, height: 1.15)),
                    TextSpan(text: ' شحنة', style: font(15, w7, Colors.white)),
                  ],
                ),
              ),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: 'للتحصيل ', style: font(12.5, w6, Colors.white.withValues(alpha: .88))),
                    TextSpan(text: money(d.toCollect), style: font(13, w8, Colors.white)),
                    TextSpan(text: ' د.ع', style: font(12.5, w6, Colors.white.withValues(alpha: .88))),
                  ],
                ),
              ),
            ],
          ),
        ),
        Tap(
          radius: 16,
          onTap: () => widget.onOpen('tasks'),
          shadow: const [BoxShadow(color: Color(0x40280004), blurRadius: 16, spreadRadius: -6, offset: Offset(0, 8))],
          child: Ink(
            height: 46,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('ابدأ جولتك', style: font(14, w8, brand.deep)),
                const SizedBox(width: 6),
                Icon(Icons.arrow_back_rounded, size: 18, color: brand.deep),
              ],
            ),
          ),
        ),
      ],
    ),
  );

  /// اختصارات شاشاته الأخرى: «اليوم» لمندوب التوصيل، و«الاستلام» و«حصصي» لمندوب الاستلام، و«المكتب»
  Widget _shortcuts(HomeData d) {
    final items = [
      if (d.delivers) ('اليوم', 'ما سجّلته', Icons.today_rounded, 'today', null),
      if (d.picks)
        ('الاستلام', 'من التجّار', Icons.storefront_rounded, 'pickups', d.pickups > 0 ? '${d.pickups}' : null),
      if (d.picks) ('حصصي', 'أرباح الاستلام', Icons.savings_rounded, 'shares', null),
      if (d.chat) ('المكتب', 'المحادثة', Icons.forum_rounded, 'chat', d.chatUnread ? 'جديد' : null),
    ];
    if (items.isEmpty) return const SizedBox.shrink();
    return Row(
      children: [
        for (final (i, (title, sub, icon, screen, badge)) in items.indexed) ...[
          if (i > 0) const SizedBox(width: 8),
          Expanded(
            child: Tap(
              radius: 20,
              shadow: cardShadow,
              onTap: () => widget.onOpen(screen),
              child: Ink(
                padding: const EdgeInsets.fromLTRB(4, 12, 4, 10),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Colors.white, Color(0xFFFFF8F7)],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: Palette.line),
                ),
                child: Column(
                  children: [
                    Stack(
                      clipBehavior: Clip.none,
                      children: [
                        Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: [
                                Color.lerp(Colors.white, brand.main, .07)!,
                                Color.lerp(Colors.white, brand.main, .22)!,
                              ],
                            ),
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: brand.main.withValues(alpha: .10)),
                          ),
                          child: Icon(icon, size: 20, color: brand.deep),
                        ),
                        if (badge != null)
                          PositionedDirectional(
                            top: -6,
                            end: -10,
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                              decoration: BoxDecoration(
                                color: brand.main,
                                borderRadius: BorderRadius.circular(9),
                                border: Border.all(color: Colors.white, width: 1.5),
                              ),
                              child: Text(badge, style: font(9.5, w8, Colors.white, height: 1.2)),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(title, maxLines: 1, style: font(12.5, w8, Palette.ink, height: 1.3)),
                    FittedBox(child: Text(sub, maxLines: 1, style: font(10.5, w5, Palette.muted, height: 1.3))),
                  ],
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }

  Widget _counters(HomeData d) {
    Widget tile(int n, String label, Color color, String screen) => Expanded(
      child: Tap(
        onTap: () => widget.onOpen(screen),
        child: WhiteCard(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
          child: Column(
            children: [
              Text('$n', style: display(20, w7, color)),
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
        tile(d.delivered, 'سُلّمت اليوم', Palette.ok, 'today'),
        const SizedBox(width: 8),
        tile(d.failed + d.postponed, 'لم تُسلَّم', Palette.warn, 'today'),
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
