import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// رئيسية الكول سنتر (docs/plan/73): «الآن» أوّلاً — أشدّ ما ينتظرها بزرٍّ واحد — ثم الطوابير
/// الأربعة بألوانها وأقدم ما فيها، ثم ما تعالجه هي فتعود إليه، وما أنجزته اليوم.
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
  }

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
          sub: d.couriers!.count == 0 ? 'لا جديد' : 'تُقرأ من الموقع',
          count: d.couriers!.count,
          icon: Icons.delivery_dining_rounded,
          color: Queue.couriers,
          soft: Queue.couriersSoft,
          onTap: () => toast(context, 'محادثة المناديب في موقع الشركة: المتابعة ← محادثة المناديب.'),
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

  Widget _today(HomeData d) => Row(
    children: [
      Expanded(child: _figure('تذاكر حسمتِها', d.todayTickets, brand.main)),
      const SizedBox(width: 10),
      Expanded(child: _figure('شحنات عالجتِها', d.todayProcessed, Queue.processing)),
    ],
  );

  Widget _figure(String label, int n, Color color) => WhiteCard(
    radius: 20,
    padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
    child: Row(
      children: [
        Text('$n', style: display(26, w7, color, height: 1.1)),
        const SizedBox(width: 10),
        Expanded(child: Text(label, style: font(12.5, w7, Palette.slate, height: 1.3))),
      ],
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
