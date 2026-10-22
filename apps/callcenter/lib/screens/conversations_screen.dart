import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'tickets_screen.dart' show TabPage;

Future<String?> openConversation(BuildContext context, Brand brand, int id) => Navigator.of(context).push<String>(
  MaterialPageRoute(
    builder: (_) => ConversationScreen(brand: brand, id: id),
  ),
);

/// «محادثات التجّار» (docs/plan/73): ما ينتظر ردّنا الأقدم أوّلاً — ومن فتحت المحادثة تردّ عليها وحدها.
class ConversationsScreen extends StatefulWidget {
  const ConversationsScreen({super.key, required this.brand, required this.refresh});

  final Brand brand;
  final ValueNotifier<int> refresh;

  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
  List<ConversationItem>? items;
  String? error;

  /// «تنتظر ردّنا» وحدها، أو كلّ المفتوحة
  bool waiting = true;

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
      final list = await Api.instance.conversations(waiting: waiting);
      if (mounted) setState(() => (items = list, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(ConversationItem c) async {
    final message = await openConversation(context, brand, c.id);
    if (message != null && mounted) toast(context, message);
    widget.refresh.value++;
  }

  @override
  Widget build(BuildContext context) {
    final list = items;
    return TabPage(
      title: 'محادثات التجّار',
      subtitle: waiting ? 'تنتظر ردّك — الأقدم أوّلاً' : 'كلّ المفتوحة',
      color: Queue.merchants,
      icon: Icons.forum_rounded,
      count: waiting ? list?.length : null,
      trailing: FilterChip(
        label: Text('تنتظر', style: font(12, w7, waiting ? Colors.white : Palette.slate)),
        selected: waiting,
        showCheckmark: false,
        selectedColor: Queue.merchants,
        backgroundColor: Colors.white,
        side: const BorderSide(color: Palette.line),
        onSelected: (v) {
          setState(() => (waiting = v, items = null));
          _load();
        },
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
                      icon: Icons.mark_chat_read_rounded,
                      title: 'لا محادثة تنتظر',
                      text: 'ما يكتبه التجّار للشركة يصل هنا.',
                    )
                  else
                    for (final c in list) ...[_card(c), const SizedBox(height: 8)],
                ],
              ),
            ),
    );
  }

  Widget _card(ConversationItem c) {
    final locked = c.claim?.other ?? false;
    return Opacity(
      opacity: locked ? .62 : 1,
      child: Tap(
        radius: 20,
        shadow: cardShadow,
        onTap: () => _open(c),
        child: Ink(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: Palette.line),
          ),
          child: Row(
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Queue.merchantsSoft, borderRadius: BorderRadius.circular(16)),
                    child: Text(
                      (c.merchant.name ?? '؟').characters.first,
                      style: display(18, w7, Queue.merchants, height: 1),
                    ),
                  ),
                  if (c.unread)
                    PositionedDirectional(
                      top: -3,
                      end: -3,
                      child: Container(
                        width: 13,
                        height: 13,
                        decoration: BoxDecoration(
                          color: brand.main,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            c.merchant.name ?? '—',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: font(14, w8, Palette.ink),
                          ),
                        ),
                        WaitChip(c.waitingMinutes, late: c.waiting && c.waitingMinutes >= 60),
                      ],
                    ),
                    Text(
                      [c.subject, if (c.shipment != null) tag(c.shipment!)].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: font(12, w6, Palette.slate, height: 1.5),
                    ),
                    if (c.claim != null) ...[const SizedBox(height: 4), ClaimChip(c.claim)],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// المحادثة: تُفتح فتصير لها إن لم تكن لزميلتها — وتُقرأ على كلّ حال
class ConversationScreen extends StatefulWidget {
  const ConversationScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<ConversationScreen> {
  ConversationItem? c;
  String? error;
  bool sending = false;
  final text = TextEditingController();
  final scroll = ScrollController();

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    text.dispose();
    scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.conversation(widget.id);
      if (!mounted) return;
      setState(() => (c = d, error = null));
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (scroll.hasClients) scroll.jumpTo(scroll.position.maxScrollExtent);
      });
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _send() async {
    if (text.text.trim().isEmpty || sending) return;
    setState(() => sending = true);
    try {
      await Api.instance.reply(widget.id, text.text);
      text.clear();
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
      if (e.claimed) await _load();
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  Future<void> _menu(String action) async {
    try {
      final message = action == 'close'
          ? await Api.instance.closeConversation(widget.id)
          : await Api.instance.releaseConversation(widget.id);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = c;
    final mine = d?.claim?.mine ?? false;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: d?.merchant.name ?? 'المحادثة',
              subtitle: d == null ? null : [d.subject, if (d.shipment != null) 'الوصل ${tag(d.shipment!)}'].join(' · '),
              trailing: d == null
                  ? null
                  : Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if ((d.merchant.phone ?? '').isNotEmpty)
                          IconButton(
                            tooltip: 'اتصل بالتاجر',
                            onPressed: () => callPhone(d.merchant.phone),
                            icon: const Icon(Icons.call_rounded, color: Palette.ok),
                          ),
                        if (mine)
                          PopupMenuButton<String>(
                            tooltip: 'المزيد',
                            onSelected: _menu,
                            itemBuilder: (_) => [
                              PopupMenuItem(
                                value: 'close',
                                child: Text('أغلقها — انتهى ما فيها', style: font(13.5, w7, Palette.ink)),
                              ),
                              PopupMenuItem(
                                value: 'release',
                                child: Text('أفلتها لزميلاتك', style: font(13.5, w7, Palette.ink)),
                              ),
                            ],
                          ),
                      ],
                    ),
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        controller: scroll,
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 12),
                        children: [ChatBubbles(brand: brand, lines: d.messages)],
                      ),
                    ),
            ),
            if (d != null)
              Container(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  border: Border(top: BorderSide(color: Palette.line)),
                ),
                child: d.claim?.other ?? false
                    ? LockBanner(claim: d.claim!, onRefresh: _load)
                    : !d.open
                    ? Text('أُغلقت المحادثة.', textAlign: TextAlign.center, style: font(12.5, w6, Palette.muted))
                    : Composer(brand: brand, controller: text, busy: sending, hint: 'ردّك للتاجر…', onSend: _send),
              ),
          ],
        ),
      ),
    );
  }
}
