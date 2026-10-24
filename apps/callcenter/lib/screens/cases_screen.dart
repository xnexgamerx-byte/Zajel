import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp_template.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'tickets_screen.dart' show TabPage;

Future<String?> openCase(BuildContext context, Brand brand, int id) => Navigator.of(context).push<String>(
  MaterialPageRoute(
    builder: (_) => CaseScreen(brand: brand, id: id),
  ),
);

/// «للمعالجة» (docs/plan/38، 73): محاولةٌ فاشلة تنتظر قراراً — ما كتب عنه أحدٌ أوّلاً، ثم الأقدم.
class CasesScreen extends StatefulWidget {
  const CasesScreen({super.key, required this.brand, required this.refresh});

  final Brand brand;
  final ValueNotifier<int> refresh;

  @override
  State<CasesScreen> createState() => _CasesScreenState();
}

class _CasesScreenState extends State<CasesScreen> {
  List<CaseItem>? items;
  String? error;

  /// «ما لم تفتحه زميلة» وحده
  bool onlyFree = false;

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
      final list = await Api.instance.cases();
      if (mounted) setState(() => (items = list, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(CaseItem c) async {
    final message = await openCase(context, brand, c.id);
    if (message != null && mounted) toast(context, message);
    widget.refresh.value++;
  }

  @override
  Widget build(BuildContext context) {
    final all = items;
    final list = all?.where((c) => !onlyFree || !(c.claim?.other ?? false)).toList();
    return TabPage(
      title: 'للمعالجة',
      subtitle: 'لم تُسلَّم — اتصلي بالزبون وقرّري',
      color: Queue.processing,
      icon: Icons.support_agent_rounded,
      count: all?.length,
      trailing: FilterChip(
        label: Text('الحرّة', style: font(12, w7, onlyFree ? Colors.white : Palette.slate)),
        selected: onlyFree,
        showCheckmark: false,
        selectedColor: Queue.processing,
        backgroundColor: Colors.white,
        side: const BorderSide(color: Palette.line),
        onSelected: (v) => setState(() => onlyFree = v),
      ),
      child: list == null
          ? Waiting(brand: brand, error: error, onRetry: _load)
          : RefreshIndicator(
              color: brand.main,
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 6, 14, 130),
                children: [
                  if (list.isEmpty)
                    const Empty(
                      icon: Icons.task_alt_rounded,
                      title: 'لا شيء ينتظر المعالجة',
                      text: 'كلّ محاولةٍ فاشلة في محافظاتك تظهر هنا حتى تُقرَّر.',
                    )
                  else
                    for (final c in list) ...[_card(c), const SizedBox(height: 10)],
                ],
              ),
            ),
    );
  }

  Widget _card(CaseItem c) {
    final locked = c.claim?.other ?? false;
    return Opacity(
      opacity: locked ? .62 : 1,
      child: Tap(
        radius: 22,
        shadow: cardShadow,
        onTap: () => _open(c),
        child: Ink(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: c.waitingOnUs && !locked ? Queue.processing.withValues(alpha: .55) : Palette.line,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (c.waitingOnUs && !locked)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      const Icon(Icons.mark_chat_unread_rounded, size: 15, color: Queue.processing),
                      const SizedBox(width: 5),
                      Text('كتب عنها التاجر أو المندوب — ينتظر ردّك', style: font(11.5, w8, Queue.processing)),
                    ],
                  ),
                ),
              Row(
                children: [
                  Expanded(child: Text(c.recipient, style: font(15, w8, Palette.ink))),
                  WaitChip(c.waitingMinutes, late: c.late),
                ],
              ),
              Text(
                '${c.place} · ${tag(c.number)} · ${money(c.amount)} د.ع',
                style: font(11.5, w6, Palette.slate, height: 1.5),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  if (c.reason != null) Pill(c.reason!, color: Queue.processing, size: 10.5),
                  if (c.attempts > 1) Pill('المحاولة ${c.attempts}', color: Palette.returnRed, size: 10.5),
                  ClaimChip(c.claim),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// شحنة المعالجة: الزبون أوّلاً (اتصلي)، ثم التاجر والمندوب ومحادثتاهما، ثم القرار — لصاحبتها وحدها
class CaseScreen extends StatefulWidget {
  const CaseScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<CaseScreen> createState() => _CaseScreenState();
}

class _CaseScreenState extends State<CaseScreen> {
  CaseItem? c;
  String? error;
  bool busy = false;
  bool sending = false;

  /// المحادثة المعروضة: merchant أو courier
  String chat = 'merchant';
  final message = TextEditingController();
  final said = TextEditingController();

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    message.dispose();
    said.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.caseDetail(widget.id);
      if (mounted) setState(() => (c = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _run(Future<void> Function() action) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await action();
    } on ApiError catch (e) {
      if (!mounted) return;
      toast(context, e.message, bad: true);
      if (e.claimed) await _load();
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _claim() => _run(() async {
    final d = await Api.instance.claimCase(widget.id);
    HapticFeedback.mediumImpact();
    if (mounted) setState(() => c = d);
  });

  Future<void> _decide(String action) async {
    DateTime? until;
    if (action == 'postpone') {
      final now = DateTime.now();
      until = await showDatePicker(
        context: context,
        initialDate: now.add(const Duration(days: 1)),
        firstDate: now,
        lastDate: now.add(const Duration(days: 59)),
        helpText: 'إلى أيّ يومٍ اتّفقتِ مع الزبون؟',
      );
      if (until == null) return;
    } else if (!mounted ||
        !await confirm(
          context,
          action == 'return' ? 'تأكيد الراجع؟' : 'إعادة التوصيل؟',
          action == 'return'
              ? 'تصير «راجع مؤكد» وترجع لتاجرها.'
              : 'تعود مع مندوبها نفسه إن كان لها مندوب، وإلّا إلى المخزن تنتظر الإسناد.',
          action == 'return' ? 'نعم، راجع' : 'نعم، أعيديها',
        )) {
      return;
    }
    await _run(() async {
      final text = await Api.instance.decide(widget.id, action, until: until, note: said.text);
      if (mounted) Navigator.of(context).pop(text);
    });
  }

  Future<void> _send() async {
    if (message.text.trim().isEmpty || sending) return;
    setState(() => sending = true);
    try {
      await Api.instance.caseMessage(widget.id, chat, message.text);
      message.clear();
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  Future<void> _release() => _run(() async {
    final text = await Api.instance.releaseCase(widget.id);
    if (mounted) Navigator.of(context).pop(text);
  });

  @override
  Widget build(BuildContext context) {
    final d = c;
    final mine = d?.claim?.mine ?? false;
    final locked = d?.claim?.other ?? false;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            PageBar(title: d == null ? 'المعالجة' : tag(d.number), subtitle: d?.reason),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 20),
                        children: [
                          WaybillBar(
                            brand: brand,
                            number: d.number,
                            full: waybillText(
                              number: d.number,
                              recipient: d.recipient,
                              phone: d.phone,
                              phoneAlt: d.phoneAlt,
                              place: d.place,
                              address: d.address,
                              landmark: d.landmark,
                              amount: d.amount,
                              merchant: d.merchant.name,
                              status: 'لم يُسلَّم${d.reason == null ? '' : ' — ${d.reason}'}',
                              description: d.description,
                              pieces: d.pieces,
                              notes: d.notes,
                            ),
                          ),
                          const SizedBox(height: 12),
                          _customer(d),
                          const SizedBox(height: 12),
                          Panel(
                            child: Column(
                              children: [
                                PersonRow(
                                  role: 'التاجر',
                                  party: d.merchant,
                                  icon: Icons.storefront_rounded,
                                  color: Queue.merchants,
                                ),
                                PersonRow(
                                  role: 'المندوب',
                                  party: d.courier,
                                  icon: Icons.delivery_dining_rounded,
                                  color: brand.main,
                                ),
                              ],
                            ),
                          ),
                          if (d.canChat) ...[const SizedBox(height: 12), _chats(d, mine || d.claim == null)],
                        ],
                      ),
                    ),
            ),
            if (d != null)
              Container(
                padding: EdgeInsets.fromLTRB(14, 12, 14, 12 + MediaQuery.paddingOf(context).bottom),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                  boxShadow: [
                    BoxShadow(color: Color(0x2226181A), blurRadius: 24, spreadRadius: -12, offset: Offset(0, -8)),
                  ],
                ),
                child: locked
                    ? LockBanner(claim: d.claim!, onRefresh: _load)
                    : !mine
                    ? HeroButton(
                        brand: brand,
                        label: busy ? '…' : 'أعالجها',
                        icon: Icons.front_hand_rounded,
                        onTap: busy ? null : _claim,
                      )
                    : _decisions(),
              ),
          ],
        ),
      ),
    );
  }

  /// الزبون بكبيرٍ أعلى الصفحة: الاتصال أوّل ما يُعمل — بالخط أو واتساب برسالة الشحنة، وهاتفه ظاهرٌ يُنسخ
  /// (docs/plan/80). وسبب المحاولة بتوضيحه كما كتبه المندوب
  Widget _customer(CaseItem d) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      gradient: const LinearGradient(
        begin: AlignmentDirectional.topStart,
        end: AlignmentDirectional.bottomEnd,
        colors: [Color(0xFFFFF6E6), Color(0xFFFFEBC7)],
      ),
      borderRadius: BorderRadius.circular(24),
      border: Border.all(color: const Color(0xFFF2D9A6)),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        CustomerCard(
          brand: brand,
          big: true,
          name: d.recipient,
          place: d.place,
          phone: d.phone,
          phoneAlt: d.phoneAlt,
          message: WhatsAppTemplate.fill(
            recipient: d.recipient,
            number: d.number,
            amount: d.amount,
            merchant: d.merchant.name,
            place: d.place,
            reason: d.reason,
          ),
        ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            if (d.reason != null) Pill(d.reason!, color: Queue.processing, size: 11),
            Pill('المحاولة ${d.attempts}', color: d.attempts > 1 ? Palette.returnRed : Palette.slate, size: 11),
            Pill('ينتظر ${ago(d.waitingMinutes)}', color: d.late ? Palette.returnRed : Palette.slate, size: 11),
            Pill('${money(d.amount)} د.ع', color: Palette.ink, size: 11),
          ],
        ),
        // التوضيح الذي كُتب مع السبب (docs/plan/80): «تغيير عنوان — إلى الكرادة قرب الجسر»
        if ((d.reasonNote ?? '').isNotEmpty) ...[
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .7),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Text('توضيح المندوب: ${d.reasonNote}', style: font(12.5, w7, Palette.ink, height: 1.5)),
          ),
        ],
        if ((d.address ?? '').isNotEmpty || (d.landmark ?? '').isNotEmpty) ...[
          const SizedBox(height: 10),
          Text(
            [d.address, d.landmark].whereType<String>().where((t) => t.isNotEmpty).join(' — '),
            style: font(12.5, w6, Palette.ink, height: 1.5),
          ),
        ],
      ],
    ),
  );

  Widget _chats(CaseItem d, bool canWrite) => Panel(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SegmentedButton<String>(
          segments: [
            ButtonSegment(
              value: 'merchant',
              label: Text('التاجر (${d.merchantChat.length})', style: font(12.5, w7, Palette.ink)),
            ),
            ButtonSegment(
              value: 'courier',
              label: Text('المندوب (${d.courierChat.length})', style: font(12.5, w7, Palette.ink)),
            ),
          ],
          selected: {chat},
          showSelectedIcon: false,
          onSelectionChanged: (s) => setState(() => chat = s.first),
        ),
        const SizedBox(height: 10),
        ChatBubbles(
          brand: brand,
          lines: chat == 'merchant' ? d.merchantChat : d.courierChat,
          empty: chat == 'merchant' ? 'لا محادثة مع التاجر عنها بعد.' : 'لم يكتب المندوب عنها.',
        ),
        if (canWrite)
          Composer(
            brand: brand,
            controller: message,
            busy: sending,
            hint: chat == 'merchant' ? 'اكتبي للتاجر عنها…' : 'اكتبي لمندوبها…',
            onSend: _send,
          ),
      ],
    ),
  );

  Widget _decisions() => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      InputBox(controller: said, hint: 'ما قاله الزبون (يُكتب مع القرار)', maxLength: 255),
      const SizedBox(height: 10),
      SheetButton(
        brand: brand,
        label: 'إعادة توصيل',
        icon: Icons.replay_rounded,
        busy: busy,
        onTap: () => _decide('redeliver'),
      ),
      const SizedBox(height: 8),
      Row(
        children: [
          Expanded(child: _secondary('تأجيل', Icons.event_rounded, Queue.processing, () => _decide('postpone'))),
          const SizedBox(width: 8),
          Expanded(child: _secondary('راجع مؤكد', Icons.undo_rounded, Palette.returnRed, () => _decide('return'))),
        ],
      ),
      TextButton(
        onPressed: busy ? null : _release,
        child: Text('أفلتها لزميلاتك', style: font(12.5, w7, Palette.slate)),
      ),
    ],
  );

  Widget _secondary(String label, IconData icon, Color color, VoidCallback onTap) => SizedBox(
    height: 48,
    child: OutlinedButton.icon(
      onPressed: busy ? null : onTap,
      style: OutlinedButton.styleFrom(
        side: BorderSide(color: color.withValues(alpha: .45)),
        backgroundColor: color.withValues(alpha: .06),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      icon: Icon(icon, size: 19, color: color),
      label: Text(label, style: font(14, w8, color)),
    ),
  );
}
