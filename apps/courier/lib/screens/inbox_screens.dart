import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// الجرس والمحادثة مع المكتب (docs/plan/71): ما في شاشة المندوب في الموقع نفسه — يُقرأ هنا فيُعلَّم
/// مقروءاً هناك.
Future<void> openNotifications(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotificationsScreen(brand: brand)));

Future<void> openChat(BuildContext context, Brand brand, {void Function(int id)? onShipment, int? shipmentId}) =>
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ChatScreen(brand: brand, onShipment: onShipment, shipmentId: shipmentId),
      ),
    );

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Notice>? items;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final list = await Api.instance.notifications();
      if (mounted) setState(() => (items = list, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final list = items;
    final brand = widget.brand;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'الإشعارات', subtitle: 'إعلانات الشركة لك'),
            Expanded(
              child: list == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          if (list.isEmpty)
                            const Empty(
                              icon: Icons.notifications_none_rounded,
                              title: 'لا إشعارات',
                              text: 'ما تعلنه الشركة للمناديب يصلك هنا.',
                            )
                          else
                            for (final n in list) ...[
                              WhiteCard(
                                padding: const EdgeInsets.all(14),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Container(
                                      width: 38,
                                      height: 38,
                                      decoration: BoxDecoration(
                                        gradient: n.fresh ? brand.hero : null,
                                        color: n.fresh ? null : brand.soft,
                                        borderRadius: BorderRadius.circular(13),
                                      ),
                                      child: Icon(
                                        Icons.campaign_rounded,
                                        size: 20,
                                        color: n.fresh ? Colors.white : brand.deep,
                                      ),
                                    ),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Expanded(child: Text(n.title, style: font(14, w8, Palette.ink))),
                                              if (n.fresh) Pill('جديد', color: brand.main, size: 10),
                                            ],
                                          ),
                                          const SizedBox(height: 2),
                                          Text(n.body, style: font(13, w5, Palette.ink, height: 1.6)),
                                          Text(when(n.at), style: font(11, w6, Palette.muted, height: 1.6)),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 8),
                            ],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// «المكتب»: محادثته مع الكول سنتر (docs/plan/38) — عن أيّ شحنة: الزبون لا يردّ، العنوان غلط
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.brand, this.onShipment, this.shipmentId});

  final Brand brand;
  final void Function(int id)? onShipment;

  /// فُتحت من شحنة: الرسالة عنها
  final int? shipmentId;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  List<ChatMessage>? messages;
  String? error;
  final text = TextEditingController();
  final scroll = ScrollController();
  bool sending = false;

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
      final list = await Api.instance.chat();
      if (!mounted) return;
      setState(() => (messages = list, error = null));
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
      await Api.instance.sendChat(text.text, shipmentId: widget.shipmentId);
      text.clear();
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final list = messages;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'المحادثة مع المكتب', subtitle: 'اكتب للكول سنتر عن أيّ شحنة'),
            Expanded(
              child: list == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        controller: scroll,
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 12),
                        children: [
                          if (list.isEmpty)
                            const Empty(
                              icon: Icons.forum_outlined,
                              title: 'لا رسالة بعد',
                              text: 'الزبون لا يردّ، العنوان غلط، يريد موعداً آخر — اكتب للمكتب من هنا.',
                            )
                          else
                            for (final m in list) _bubble(m),
                        ],
                      ),
                    ),
            ),
            _composer(),
          ],
        ),
      ),
    );
  }

  Widget _bubble(ChatMessage m) {
    final fg = m.mine ? Colors.white : Palette.ink;
    return Align(
      // كالموقع: رسائله في آخر السطر (يساراً)، والمكتب في أوّله
      alignment: m.mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * .8),
        padding: const EdgeInsets.fromLTRB(12, 9, 12, 7),
        decoration: BoxDecoration(
          gradient: m.mine ? brand.hero : null,
          color: m.mine ? null : Colors.white,
          border: m.mine ? null : Border.all(color: Palette.line),
          borderRadius: BorderRadius.circular(18),
          boxShadow: m.mine ? brand.glow(.25) : cardShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (m.shipmentNumber != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Tap(
                  radius: 8,
                  onTap: m.shipmentId == null || widget.onShipment == null
                      ? null
                      : () => widget.onShipment!(m.shipmentId!),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: m.mine ? Colors.white.withValues(alpha: .18) : brand.soft,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      'الشحنة ${tag(m.shipmentNumber!)}${m.recipient == null ? '' : ' — ${m.recipient}'}',
                      style: font(11.5, w8, m.mine ? Colors.white : brand.deep),
                    ),
                  ),
                ),
              ),
            Text(m.body, style: font(14, w6, fg, height: 1.55)),
            Text(
              '${m.author} · ${when(m.at)}',
              style: font(10.5, w6, m.mine ? Colors.white.withValues(alpha: .78) : Palette.muted, height: 1.6),
            ),
          ],
        ),
      ),
    );
  }

  Widget _composer() => Container(
    padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
    decoration: const BoxDecoration(
      color: Colors.white,
      border: Border(top: BorderSide(color: Palette.line)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: InputBox(controller: text, hint: 'اكتب رسالتك…', lines: 1, maxLength: 2000),
        ),
        const SizedBox(width: 8),
        Tooltip(
          message: 'أرسل',
          child: Tap(
            radius: 22,
            shadow: brand.glow(.4),
            onTap: sending ? null : _send,
            child: Ink(
              width: 46,
              height: 46,
              decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle),
              child: sending
                  ? const Padding(
                      padding: EdgeInsets.all(13),
                      child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                    )
                  : const Icon(Icons.send_rounded, color: Colors.white, size: 20),
            ),
          ),
        ),
      ],
    ),
  );
}
