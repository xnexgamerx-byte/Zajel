import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'search_screen.dart' show openShipmentNumber;

Future<void> openCouriers(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => CouriersScreen(brand: brand)));

/// «رسائل المناديب» (docs/plan/81): ما كان يُقرأ من الموقع وحده صار في التطبيق — محادثة كلّ مندوب، وما
/// ينتظر ردّك أوّلاً.
class CouriersScreen extends StatefulWidget {
  const CouriersScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<CouriersScreen> createState() => _CouriersScreenState();
}

class _CouriersScreenState extends State<CouriersScreen> {
  List<CourierThreadRow>? items;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final list = await Api.instance.couriers();
      if (mounted) setState(() => (items = list, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(CourierThreadRow c) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => CourierThreadScreen(brand: brand, id: c.id, name: c.name),
      ),
    );
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final list = items;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'رسائل المناديب', subtitle: 'ما ينتظر ردّك أوّلاً'),
            Expanded(
              child: list == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : list.isEmpty
                  ? const Empty(
                      icon: Icons.delivery_dining_rounded,
                      title: 'لا رسائل من المناديب',
                      text: 'حين يكتب مندوبٌ للمكتب تظهر محادثته هنا.',
                    )
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                        itemCount: list.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 8),
                        itemBuilder: (_, i) => _row(list[i]),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(CourierThreadRow c) => Tap(
    radius: 18,
    shadow: cardShadow,
    onTap: () => _open(c),
    child: Ink(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: c.waiting ? Queue.couriers.withValues(alpha: .5) : Palette.line),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(color: Queue.couriersSoft, borderRadius: BorderRadius.circular(14)),
            child: const Icon(Icons.delivery_dining_rounded, size: 21, color: Queue.couriers),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(c.name, style: font(14, w8, Palette.ink))),
                    if (c.unread)
                      Container(
                        width: 9,
                        height: 9,
                        decoration: const BoxDecoration(color: Queue.couriers, shape: BoxShape.circle),
                      ),
                  ],
                ),
                Text(
                  c.last ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(12, w6, Palette.slate, height: 1.4),
                ),
                Text(
                  c.waiting ? 'ينتظر ردّك ${ago(c.waitingMinutes)}' : ago(c.waitingMinutes),
                  style: font(11, c.waiting ? w8 : w6, c.waiting ? Queue.couriers : Palette.muted),
                ),
              ],
            ),
          ),
          if ((c.phone ?? '').isNotEmpty) ...[const SizedBox(width: 6), WhatsAppButton(phone: c.phone, size: 34)],
        ],
      ),
    ),
  );
}

/// محادثة مندوبٍ مع المكتب: تُقرأ فتُعلَّم مقروءة، ويُردّ فيها — ورقم الشحنة على السطر يفتحها
class CourierThreadScreen extends StatefulWidget {
  const CourierThreadScreen({super.key, required this.brand, required this.id, required this.name});

  final Brand brand;
  final int id;
  final String name;

  @override
  State<CourierThreadScreen> createState() => _CourierThreadScreenState();
}

class _CourierThreadScreenState extends State<CourierThreadScreen> {
  CourierThread? t;
  String? error;
  bool sending = false;
  final message = TextEditingController();

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    message.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.courierThread(widget.id);
      if (mounted) setState(() => (t = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _send() async {
    if (message.text.trim().isEmpty || sending) return;
    setState(() => sending = true);
    try {
      await Api.instance.courierReply(widget.id, message.text);
      message.clear();
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = t;
    final numbers = {
      for (final m in d?.messages ?? const <ChatLine>[])
        if (m.shipment != null) m.shipment!,
    };
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: widget.name,
              subtitle: 'المندوب',
              trailing: (d?.phone ?? '').isEmpty
                  ? null
                  : Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        WhatsAppButton(phone: d!.phone, size: 36),
                        IconButton(
                          tooltip: 'اتصل بالمندوب',
                          onPressed: () => callPhone(d.phone),
                          icon: const Icon(Icons.call_rounded, color: Palette.ok),
                        ),
                      ],
                    ),
            ),
            // الشحنات التي كتب عنها: لمسها يفتح معلوماتها
            if (numbers.isNotEmpty)
              SizedBox(
                height: 40,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  children: [
                    for (final n in numbers)
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 6),
                        child: ActionChip(
                          onPressed: () => openShipmentNumber(context, brand, n),
                          avatar: Icon(Icons.inventory_2_outlined, size: 15, color: brand.main),
                          backgroundColor: Colors.white,
                          side: const BorderSide(color: Palette.line),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                          label: Text(tag(n), style: font(12, w8, Palette.ink)),
                        ),
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
                        padding: const EdgeInsets.fromLTRB(14, 6, 14, 12),
                        children: [
                          ChatBubbles(
                            brand: brand,
                            lines: [
                              for (final m in d.messages)
                                ChatLine(
                                  mine: m.mine,
                                  author: m.author,
                                  body: m.shipment == null ? m.body : '${m.body}\nالوصل ${tag(m.shipment!)}',
                                  at: m.at,
                                ),
                            ],
                            empty: 'لا رسائل بعد.',
                          ),
                        ],
                      ),
                    ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 12),
              child: Composer(brand: brand, controller: message, busy: sending, hint: 'ردّك للمندوب…', onSend: _send),
            ),
          ],
        ),
      ),
    );
  }
}
