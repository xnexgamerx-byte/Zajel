import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'board_screen.dart';
import 'couriers_screen.dart';
import 'my_message_screen.dart';
import 'quick_replies_screen.dart';
import 'template_screen.dart';

/// رئيسية الكول سنتر (docs/plan/73، 80): «الآن» أوّلاً — أشدّ ما ينتظرها بزرٍّ واحد — ثم الطوابير
/// الأربعة بألوانها وأقدم ما فيها، ثم «حالة الشحنات» بخاناتها الأربع، وما تعالجه هي فتعود إليه، وما
/// أنجزته اليوم بأنواعه — وكلٌّ يُفتح بتفاصيله.
class HomeScreen extends StatefulWidget {
  const HomeScreen({
    super.key,
    required this.brand,
    required this.refresh,
    required this.onOpen,
    required this.onData,
    required this.onLogout,
  });

  final Brand brand;
  final ValueNotifier<int> refresh;

  /// tickets · processing · conversations · search · ticket:رقم · processing:رقم · conversation:رقم
  final void Function(String screen) onOpen;

  /// لعدّادات الشريط السفلي
  final ValueChanged<HomeData> onData;
  final Future<void> Function() onLogout;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  HomeData? data;
  String? error;

  /// «حالة الشحنات» (docs/plan/80): قيد التوصيل، مؤجل، إعادة توصيل، راجع مؤكد
  List<BoardKind> board = const [];

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
      if (!mounted) return;
      setState(() => (data = d, error = null));
      widget.onData(d);
      // الخانات وحدها: تعذّرها لا يُخفي الرئيسية
      try {
        final b = await Api.instance.board();
        if (mounted) setState(() => board = b);
      } on ApiError catch (_) {}
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _account(HomeData d) async {
    final out = await showSheet<bool>(
      context,
      SheetFrame(
        title: d.name,
        text: d.governorates.isEmpty ? 'تصلك طلبات المحافظات كلّها.' : 'محافظاتك: ${d.governorates.join('، ')}',
        children: [
          Text(
            'كلمة المرور واسم المستخدم يغيّرهما مدير الشركة، وهما نفسهما في موقع الشركة.',
            style: font(12, w6, Palette.slate, height: 1.6),
          ),
          const SizedBox(height: 14),
          // رسالة واتساب للزبون من الشحنة (docs/plan/80): تُكتب مرّة وتُملأ من كلّ شحنة
          SheetButton(
            brand: brand,
            label: 'رسالة واتساب للزبون',
            icon: Icons.edit_note_rounded,
            onTap: () => Navigator.of(context).pop(false),
          ),
          const SizedBox(height: 8),
          // رسالتي الثابتة للتاجر (docs/plan/83): نصّها نفسه في الموقع
          SheetButton(
            brand: brand,
            label: 'رسالتي الثابتة للتاجر',
            icon: Icons.push_pin_outlined,
            outlined: true,
            onTap: () {
              Navigator.of(context).pop();
              editMyMessage(context, brand);
            },
          ),
          const SizedBox(height: 8),
          SheetButton(
            brand: brand,
            label: 'الردود الجاهزة',
            icon: Icons.quickreply_outlined,
            outlined: true,
            onTap: () {
              Navigator.of(context).pop();
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => QuickRepliesScreen(brand: brand)));
            },
          ),
          const SizedBox(height: 8),
          SheetButton(
            brand: brand,
            label: 'تسجيل الخروج من هذا الجهاز',
            outlined: true,
            onTap: () => Navigator.of(context).pop(true),
          ),
        ],
      ),
    );
    if (out == true) await widget.onLogout();
    if (out == false && mounted) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => WhatsAppTemplateScreen(brand: brand)));
    }
  }

  Future<void> _board(String kind) async {
    final message = await openBoard(context, brand, kind);
    if (message != null && mounted) toast(context, message);
    widget.refresh.value++;
  }

  Future<void> _done(String kind, {String? action}) => Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => DoneScreen(brand: brand, kind: kind, action: action),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) return Waiting(brand: brand, error: error, onRetry: _load);
    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      // عمودٌ واحد في القائمة: بطاقة «الآن» والطوابير مرفوعةٌ فوق الرأس، ولمسها يصل إليها لا إلى الرأس
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(d),
              Transform.translate(
                offset: const Offset(0, -46),
                child: Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: _now(d)),
              ),
              Transform.translate(
                offset: const Offset(0, -32),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 0, 14, 130),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _queues(d),
                      if (board.isNotEmpty) ...[const SectionTitle('حالة الشحنات'), _boardTiles()],
                      if (d.mine.isNotEmpty) ...[const SectionTitle('أعالجها الآن'), _mine(d)],
                      const SectionTitle('أنجزتِ اليوم'),
                      _today(d),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// رأسٌ بلون الشركة: التحية، ومحافظاتها، والبحث
  Widget _header(HomeData d) {
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.fromLTRB(18, top > 0 ? top + 10 : (AppConfig.demo ? 38 : 16), 18, 66),
      decoration: BoxDecoration(
        gradient: brand.hero,
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(34)),
      ),
      child: Row(
        children: [
          Tooltip(
            message: 'حسابي',
            child: Tap(
              radius: 24,
              onTap: () => _account(d),
              child: Container(
                width: 48,
                height: 48,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .18),
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white.withValues(alpha: .4), width: 1.5),
                ),
                child: Text(
                  d.name.isEmpty ? 'ك' : d.name.characters.first,
                  style: display(19, w7, Colors.white, height: 1),
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(d.greeting, style: font(12.5, w6, Colors.white.withValues(alpha: .85))),
                Text(d.name, style: display(19, w7, Colors.white)),
                Text(
                  d.governorates.isEmpty ? 'كلّ المحافظات' : d.governorates.join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(11.5, w6, Colors.white.withValues(alpha: .8)),
                ),
              ],
            ),
          ),
          Tooltip(
            message: 'ابحث عن شحنة',
            child: Tap(
              radius: 22,
              onTap: () => widget.onOpen('search'),
              child: Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .18), shape: BoxShape.circle),
                child: const Icon(Icons.search_rounded, color: Colors.white, size: 23),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// «الآن»: أشدّ ما ينتظرها — المندوب عند الباب أوّلاً، ثم ما لم يُسلَّم، ثم التجّار
  Widget _now(HomeData d) {
    final t = d.tickets;
    final p = d.processing;
    final c = d.conversations;
    final (String title, String text, String screen, IconData icon, Color color) = switch (null) {
      _ when (t?.count ?? 0) > 0 => (
        t!.late > 0 ? 'مندوبٌ ينتظرك عند الباب' : 'تذكرة مبلغٍ تنتظر',
        '${t.count == 1 ? 'تذكرةٌ واحدة' : '${t.count} تذاكر'} — أقدمها ${ago(t.oldestMinutes)}${t.late > 0 ? '. المتأخّرة: ${t.late}' : ''}',
        'tickets',
        Icons.price_change_rounded,
        brand.main,
      ),
      _ when (p?.free ?? p?.count ?? 0) > 0 => (
        'شحناتٌ تنتظر قرارك',
        '${p!.free ?? p.count} لم تفتحها زميلة — أقدمها ${ago(p.oldestMinutes)}',
        'processing',
        Icons.support_agent_rounded,
        Queue.processing,
      ),
      _ when (c?.count ?? 0) > 0 => (
        'تاجرٌ ينتظر ردّك',
        '${c!.count} محادثة — أقدمها ${ago(c.oldestMinutes)}',
        'conversations',
        Icons.forum_rounded,
        Queue.merchants,
      ),
      _ => (
        'كلّ شيءٍ تحت السيطرة',
        'لا تذكرة ولا معالجة ولا محادثة تنتظر الآن.',
        '',
        Icons.task_alt_rounded,
        Palette.ok,
      ),
    };
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: Palette.line),
        boxShadow: const [
          BoxShadow(color: Color(0x3326181A), blurRadius: 30, spreadRadius: -14, offset: Offset(0, 16)),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: color,
              borderRadius: BorderRadius.circular(18),
              boxShadow: [
                BoxShadow(
                  color: color.withValues(alpha: .45),
                  blurRadius: 16,
                  spreadRadius: -6,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Icon(icon, color: Colors.white, size: 27),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('الآن', style: font(11, w8, color)),
                Text(title, style: display(16, w7, Palette.ink, height: 1.3)),
                Text(text, style: font(12, w6, Palette.slate, height: 1.45)),
              ],
            ),
          ),
          if (screen.isNotEmpty) ...[
            const SizedBox(width: 8),
            Tap(
              radius: 16,
              shadow: [
                BoxShadow(
                  color: color.withValues(alpha: .4),
                  blurRadius: 14,
                  spreadRadius: -6,
                  offset: const Offset(0, 7),
                ),
              ],
              onTap: () => widget.onOpen(screen),
              child: Ink(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(16)),
                child: Text('ابدئي', style: font(13.5, w8, Colors.white)),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// الطوابير الأربعة: كلٌّ بلونه وعدده وأقدم ما فيه
  Widget _queues(HomeData d) {
    final tiles = [
      if (d.tickets != null)
        _QueueTile(
          title: 'تذاكر المبلغ',
          sub: d.tickets!.count == 0 ? 'لا شيء ينتظر' : 'أقدمها ${ago(d.tickets!.oldestMinutes)}',
          count: d.tickets!.count,
          alert: d.tickets!.late > 0 ? '${d.tickets!.late} تأخّرت' : null,
          icon: Icons.price_change_rounded,
          color: brand.main,
          soft: brand.soft,
          onTap: () => widget.onOpen('tickets'),
        ),
      if (d.processing != null)
        _QueueTile(
          title: 'للمعالجة',
          sub: d.processing!.count == 0
              ? 'لا شيء ينتظر'
              : '${d.processing!.free ?? d.processing!.count} حرّة · أقدمها ${ago(d.processing!.oldestMinutes)}',
          count: d.processing!.count,
          alert: d.processing!.late > 0 ? '${d.processing!.late} تجاوزت يوماً' : null,
          icon: Icons.support_agent_rounded,
          color: Queue.processing,
          soft: Queue.processingSoft,
          onTap: () => widget.onOpen('processing'),
        ),
      if (d.conversations != null)
        _QueueTile(
          title: 'محادثات التجّار',
          sub: d.conversations!.count == 0 ? 'لا تاجر ينتظر' : 'أقدمها ${ago(d.conversations!.oldestMinutes)}',
          count: d.conversations!.count,
          icon: Icons.forum_rounded,
          color: Queue.merchants,
          soft: Queue.merchantsSoft,
          onTap: () => widget.onOpen('conversations'),
        ),
      if (d.couriers != null)
        _QueueTile(
          title: 'رسائل المناديب',
          sub: d.couriers!.count == 0 ? 'لا جديد' : 'تنتظر ردّك',
          count: d.couriers!.count,
          icon: Icons.delivery_dining_rounded,
          color: Queue.couriers,
          soft: Queue.couriersSoft,
          // تُقرأ ويُردّ عليها في التطبيق (docs/plan/81)
          onTap: () => openCouriers(context, brand).then((_) => widget.refresh.value++),
        ),
    ];
    return Column(
      children: [
        for (var i = 0; i < tiles.length; i += 2) ...[
          if (i > 0) const SizedBox(height: 10),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: tiles[i]),
                const SizedBox(width: 10),
                Expanded(child: i + 1 < tiles.length ? tiles[i + 1] : const SizedBox.shrink()),
              ],
            ),
          ),
        ],
      ],
    );
  }

  Widget _mine(HomeData d) => WhiteCard(
    radius: 22,
    child: Column(
      children: [
        for (final (i, m) in d.mine.indexed) ...[
          if (i > 0) const Divider(height: 1, indent: 56, color: Palette.line),
          Material(
            type: MaterialType.transparency,
            child: ListTile(
              onTap: () => widget.onOpen('${m.subject}:${m.id}'),
              leading: Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: Palette.okSoft, borderRadius: BorderRadius.circular(12)),
                child: Icon(
                  switch (m.subject) {
                    'ticket' => Icons.price_change_rounded,
                    'processing' => Icons.support_agent_rounded,
                    _ => Icons.forum_rounded,
                  },
                  size: 19,
                  color: Palette.ok,
                ),
              ),
              title: Text(m.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(13.5, w8, Palette.ink)),
              subtitle: Text('فتحتِها ${when(m.since)}', style: font(11.5, w6, Palette.muted)),
              trailing: const Chev(size: 9, stroke: 1.6, color: Palette.muted),
            ),
          ),
        ],
      ],
    ),
  );

  /// الخانات الأربع (docs/plan/80): أيقونةٌ بلونها أعلاها، ثم اسمها، ثم عددها — في الوسط، وكلٌّ يفتح قائمته
  Widget _boardTiles() => GridView.count(
    crossAxisCount: 2,
    shrinkWrap: true,
    padding: EdgeInsets.zero,
    physics: const NeverScrollableScrollPhysics(),
    mainAxisSpacing: 10,
    crossAxisSpacing: 10,
    childAspectRatio: 1.9,
    children: [
      for (final k in board)
        Builder(
          builder: (context) {
            final v = BoardView.of(k.kind);
            return Tap(
              radius: 20,
              shadow: cardShadow,
              onTap: () => _board(k.kind),
              child: Ink(
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: Color.lerp(Palette.line, v.color, .2)!),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Container(
                      width: 36,
                      height: 36,
                      decoration: BoxDecoration(color: v.soft, shape: BoxShape.circle),
                      child: Icon(v.icon, size: 19, color: v.ink),
                    ),
                    const SizedBox(height: 5),
                    Text(k.label, style: font(12.5, w8, Palette.slate, height: 1.2)),
                    Text(money(k.count), style: display(20, w7, Palette.ink, height: 1.25)),
                  ],
                ),
              ),
            );
          },
        ),
    ],
  );

  /// ما أنجزته اليوم بأنواعه (docs/plan/80): قرارات المعالجة الثلاثة، وتذاكر المبلغ التي حسمتها للمناديب —
  /// وكلٌّ يُفتح بتفاصيله
  Widget _today(HomeData d) => Column(
    children: [
      Row(
        children: [
          Expanded(
            child: _figure(
              'إعادة توصيل',
              d.todayActions['redeliver'] ?? 0,
              Queue.couriers,
              () => _done('processed', action: 'redeliver'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _figure(
              'مؤجل',
              d.todayActions['postpone'] ?? 0,
              BoardView.yellowInk,
              () => _done('processed', action: 'postpone'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _figure(
              'راجع مؤكد',
              d.todayActions['return'] ?? 0,
              Palette.returnRed,
              () => _done('processed', action: 'return'),
            ),
          ),
        ],
      ),
      const SizedBox(height: 8),
      Tap(
        radius: 20,
        shadow: cardShadow,
        onTap: () => _done('tickets'),
        child: Ink(
          padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
          child: Row(
            children: [
              Text('${d.todayTickets}', style: display(24, w7, brand.main, height: 1.1)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('تذاكر المبلغ حسمتِها', style: font(13, w8, Palette.ink, height: 1.3)),
                    Text(
                      'طلبات المناديب لتغيير المبلغ: اعتمدتِها أو رفضتِها',
                      style: font(11, w6, Palette.muted, height: 1.4),
                    ),
                  ],
                ),
              ),
              const Chev(size: 9, stroke: 1.6, color: Palette.muted),
            ],
          ),
        ),
      ),
    ],
  );

  Widget _figure(String label, int n, Color color, VoidCallback onTap) => Tap(
    radius: 18,
    shadow: cardShadow,
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
      child: Column(
        children: [
          Text('$n', style: display(24, w7, n == 0 ? Palette.line : color, height: 1.1)),
          const SizedBox(height: 2),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(label, maxLines: 1, style: font(12, w7, Palette.slate, height: 1.3)),
          ),
        ],
      ),
    ),
  );
}

/// مربّع طابور: أيقونةٌ بلونه، وعدده كبيراً، وأقدم ما فيه، وشارةٌ حمراء إن تأخّر شيء
class _QueueTile extends StatelessWidget {
  const _QueueTile({
    required this.title,
    required this.sub,
    required this.count,
    required this.icon,
    required this.color,
    required this.soft,
    required this.onTap,
    this.alert,
  });

  final String title;
  final String sub;
  final int count;
  final String? alert;
  final IconData icon;
  final Color color;
  final Color soft;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Tap(
    radius: 22,
    shadow: cardShadow,
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Palette.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: soft, borderRadius: BorderRadius.circular(14)),
                child: Icon(icon, size: 21, color: color),
              ),
              const Spacer(),
              Text('$count', style: display(28, w7, count == 0 ? Palette.line : color, height: 1)),
            ],
          ),
          const SizedBox(height: 10),
          Text(title, style: font(14, w8, Palette.ink)),
          Text(sub, maxLines: 2, style: font(11.5, w6, Palette.muted, height: 1.4)),
          if (alert != null) ...[
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(20)),
              child: Text(alert!, style: font(11, w8, Palette.returnRed)),
            ),
          ],
        ],
      ),
    ),
  );
}
