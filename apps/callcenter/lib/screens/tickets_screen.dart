import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'my_message_screen.dart';
import 'search_screen.dart' show openShipment;

Future<String?> openTicket(BuildContext context, Brand brand, int id) => Navigator.of(context).push<String>(
  MaterialPageRoute(
    builder: (_) => TicketScreen(brand: brand, id: id),
  ),
);

/// «تذاكر المبلغ» (docs/plan/30، 73): المندوب عند الباب والزبون ينتظر — الأقدم أوّلاً، والمتأخّر أحمر،
/// وما تعالجه زميلةٌ مقفولٌ باسمها.
class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key, required this.brand, required this.refresh});

  final Brand brand;
  final ValueNotifier<int> refresh;

  @override
  State<TicketsScreen> createState() => _TicketsScreenState();
}

class _TicketsScreenState extends State<TicketsScreen> {
  List<TicketItem>? items;
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
      final list = await Api.instance.tickets();
      if (mounted) setState(() => (items = list, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(TicketItem t) async {
    final message = await openTicket(context, brand, t.id);
    if (message != null && mounted) toast(context, message);
    widget.refresh.value++;
  }

  @override
  Widget build(BuildContext context) {
    final list = items;
    return TabPage(
      title: 'تذاكر المبلغ',
      subtitle: 'المندوب عند الباب — الأقدم أوّلاً',
      color: brand.main,
      icon: Icons.price_change_rounded,
      count: list?.length,
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
                      title: 'لا تذكرة تنتظر',
                      text: 'حين يطلب مندوبٌ تغيير المبلغ عند الباب تظهر هنا فوراً.',
                    )
                  else
                    for (final t in list) ...[_card(t), const SizedBox(height: 10)],
                ],
              ),
            ),
    );
  }

  Widget _card(TicketItem t) {
    final locked = t.claim?.other ?? false;
    return Opacity(
      opacity: locked ? .62 : 1,
      child: Tap(
        radius: 22,
        shadow: cardShadow,
        onTap: () => _open(t),
        child: Ink(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: t.late && !locked ? Palette.returnRed.withValues(alpha: .45) : Palette.line),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Text(tag(t.number), style: font(13, w8, Palette.ink)),
                  const SizedBox(width: 8),
                  Pill(t.kindLabel, color: t.kind == 'price' ? brand.deep : Queue.processing, size: 10.5),
                  // سلّمها المندوب وينتظر (docs/plan/80): اعتمادها يسجّلها واصلة
                  if (t.finalize) ...[
                    const SizedBox(width: 6),
                    Pill(
                      t.returnedPieces == null ? 'سلّمها — ينتظرك' : 'رجعت ${t.returnedPieces} قطعة',
                      color: Queue.couriers,
                      size: 10.5,
                    ),
                  ],
                  const Spacer(),
                  WaitChip(t.waitingMinutes, late: t.late),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(t.recipient ?? '—', style: font(14.5, w8, Palette.ink)),
                        Text(
                          [t.place, if (t.courier.name != null) 'المندوب ${t.courier.name}'].join(' · '),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: font(11.5, w6, Palette.slate),
                        ),
                      ],
                    ),
                  ),
                  _Amounts(current: t.current, requested: t.requested, color: brand.deep),
                ],
              ),
              if (t.claim != null) ...[
                const SizedBox(height: 10),
                Align(alignment: AlignmentDirectional.centerStart, child: ClaimChip(t.claim)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// «50,000 ← 45,000»: القديم مشطوب، والمطلوب كبير
class _Amounts extends StatelessWidget {
  const _Amounts({required this.current, required this.requested, required this.color, this.light = false});

  final int current;
  final int requested;
  final Color color;
  final bool light;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.end,
    children: [
      Text(
        money(current),
        style: font(
          12,
          w7,
          light ? Colors.white.withValues(alpha: .7) : Palette.muted,
        ).copyWith(decoration: TextDecoration.lineThrough, decorationColor: light ? Colors.white70 : Palette.muted),
      ),
      Text.rich(
        TextSpan(
          children: [
            TextSpan(
              text: money(requested),
              style: display(light ? 30 : 19, w7, light ? Colors.white : color, height: 1.15),
            ),
            TextSpan(text: ' د.ع', style: font(11, w7, light ? Colors.white : Palette.slate)),
          ],
        ),
      ),
    ],
  );
}

/// التذكرة: المبلغ وما قاله الزبون ومن يُتّصل به — و«أعالجها» ثم الاعتماد أو الرفض، لصاحبتها وحدها
class TicketScreen extends StatefulWidget {
  const TicketScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<TicketScreen> createState() => _TicketScreenState();
}

class _TicketScreenState extends State<TicketScreen> {
  TicketItem? t;
  String? error;
  bool busy = false;

  /// شحنة التذكرة بمحادثتيها (docs/plan/81)
  ShipmentDetail? ship;
  final amount = TextEditingController();
  final reply = TextEditingController();
  String? fieldError;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    amount.dispose();
    reply.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.ticket(widget.id);
      if (!mounted) return;
      setState(() => (t = d, error = null));
      if (amount.text.isEmpty) amount.text = '${d.requested}';
      await _loadShipment();
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _loadShipment() async {
    final id = t?.shipmentId;
    if (id == null) return;
    try {
      final d = await Api.instance.shipment(id);
      if (mounted) setState(() => ship = d);
    } on ApiError catch (_) {
      // المحادثتان وحدهما: تعذّرهما لا يُخفي التذكرة
    }
  }

  Future<void> _run(Future<void> Function() action) async {
    if (busy) return;
    setState(() => (busy = true, fieldError = null));
    try {
      await action();
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.claimed) {
        toast(context, e.message, bad: true);
        await _load();
      } else {
        setState(() => fieldError = e.message);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _claim() => _run(() async {
    final d = await Api.instance.claimTicket(widget.id);
    HapticFeedback.mediumImpact();
    if (mounted) setState(() => t = d);
  });

  Future<void> _approve() => _run(() async {
    final value = int.tryParse(LatinDigits.convert(amount.text).replaceAll(',', '').trim());
    if (value == null) throw ApiError('اكتب المبلغ المعتمد.');
    final message = await Api.instance.approveTicket(widget.id, value, reply: reply.text);
    if (mounted) Navigator.of(context).pop(message);
  });

  Future<void> _reject() => _run(() async {
    if (reply.text.trim().length < 3) throw ApiError('اكتب للمندوب سبب الرفض في «ردّك» — يقرؤه عند الباب.');
    final message = await Api.instance.rejectTicket(widget.id, reply.text);
    if (mounted) Navigator.of(context).pop(message);
  });

  Future<void> _release() => _run(() async {
    final message = await Api.instance.releaseTicket(widget.id);
    if (mounted) Navigator.of(context).pop(message);
  });

  @override
  Widget build(BuildContext context) {
    final d = t;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            PageBar(title: d == null ? 'التذكرة' : tag(d.number), subtitle: d?.kindLabel),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 20),
                      children: [
                        // رقم الوصل ومعلوماته (docs/plan/81): لمسه يفتح تتبّعها، ونسخه ونسخ الوصل كاملاً بزرّيهما
                        if (d.shipmentNumber != null) ...[
                          WaybillBar(
                            brand: brand,
                            number: d.shipmentNumber!,
                            onOpen: d.shipmentId == null ? null : () => openShipment(context, brand, d.shipmentId!),
                            full: waybillText(
                              number: d.shipmentNumber!,
                              recipient: d.recipient ?? '',
                              phone: d.phone,
                              place: d.place,
                              amount: d.current,
                              merchant: d.merchant.name,
                              status: '${d.kindLabel}: ${money(d.current)} ← ${money(d.requested)}',
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            [
                              d.recipient ?? 'الزبون',
                              if (d.place.isNotEmpty) d.place,
                              if ((d.merchant.name ?? '').isNotEmpty) d.merchant.name!,
                              if ((d.phone ?? '').isNotEmpty) d.phone!,
                            ].join(' · '),
                            style: font(12.5, w7, Palette.slate, height: 1.5),
                          ),
                          const SizedBox(height: 12),
                        ],
                        _hero(d),
                        const SizedBox(height: 12),
                        Panel(
                          title: 'من يُتّصل به',
                          child: Column(
                            children: [
                              PersonRow(
                                role: 'المندوب — عند الباب',
                                party: d.courier,
                                icon: Icons.delivery_dining_rounded,
                                color: brand.main,
                              ),
                              PersonRow(
                                role: 'التاجر',
                                party: d.merchant,
                                icon: Icons.storefront_rounded,
                                color: Queue.merchants,
                              ),
                              PersonRow(
                                role: 'الزبون · ${d.place}',
                                party: Party(name: d.recipient, phone: d.phone),
                                icon: Icons.person_rounded,
                                color: Queue.couriers,
                              ),
                            ],
                          ),
                        ),
                        if (d.effect != null) ...[
                          const SizedBox(height: 12),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Icon(Icons.info_outline_rounded, size: 17, color: Palette.muted),
                              const SizedBox(width: 6),
                              Expanded(child: Text(d.effect!, style: font(12, w6, Palette.slate, height: 1.6))),
                            ],
                          ),
                        ],
                        // محادثتا التاجر والمندوب عن الشحنة (docs/plan/81)
                        if (ship != null && ship!.canChat) ...[
                          const SectionTitle('المحادثات'),
                          ShipmentChats(
                            brand: brand,
                            shipmentId: ship!.found.id,
                            merchantChat: ship!.merchantChat,
                            courierChat: ship!.courierChat,
                            canAsk: ship!.canAsk,
                            hasCourier: ship!.hasCourier,
                            onSent: _loadShipment,
                            merchantMessage: ship!.merchantMessage,
                            onEditTemplate: () => editMyMessage(context, brand, then: _loadShipment),
                          ),
                        ],
                      ],
                    ),
            ),
            if (d != null) _bottom(d),
          ],
        ),
      ),
    );
  }

  /// المبلغ بتدرّج لون الشركة: ما يطلبه المندوب، وما قاله الزبون
  Widget _hero(TicketItem d) => Container(
    padding: const EdgeInsets.all(18),
    decoration: BoxDecoration(
      gradient: brand.hero,
      borderRadius: BorderRadius.circular(24),
      boxShadow: brand.glow(.45),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Text('المبلغ المطلوب', style: font(12.5, w6, Colors.white.withValues(alpha: .88))),
            const Spacer(),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
              decoration: BoxDecoration(
                color: d.late ? Colors.white : Colors.white.withValues(alpha: .18),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                'ينتظر ${ago(d.waitingMinutes)}',
                style: font(11, w8, d.late ? Palette.returnRed : Colors.white),
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: _Amounts(current: d.current, requested: d.requested, color: Colors.white, light: true),
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: .14),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.format_quote_rounded, size: 18, color: Colors.white),
              const SizedBox(width: 6),
              Expanded(child: Text(d.reason, style: font(13.5, w7, Colors.white, height: 1.55))),
            ],
          ),
        ),
      ],
    ),
  );

  Widget _bottom(TicketItem d) {
    final c = d.claim;
    return Container(
      padding: EdgeInsets.fromLTRB(14, 12, 14, 12 + MediaQuery.paddingOf(context).bottom),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        boxShadow: [BoxShadow(color: Color(0x2226181A), blurRadius: 24, spreadRadius: -12, offset: Offset(0, -8))],
      ),
      child: c == null
          ? HeroButton(
              brand: brand,
              label: busy ? '…' : 'أعالجها',
              icon: Icons.front_hand_rounded,
              onTap: busy ? null : _claim,
            )
          : c.other
          ? LockBanner(claim: c, onRefresh: _load)
          : Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    SizedBox(
                      width: 130,
                      child: InputBox(
                        controller: amount,
                        hint: 'المبلغ المعتمد',
                        ltr: true,
                        keyboard: TextInputType.number,
                        formatters: const [LatinDigits()],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: InputBox(controller: reply, hint: 'ردّك للمندوب', maxLength: 255),
                    ),
                  ],
                ),
                if (fieldError != null) ErrorNote(fieldError!),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: SheetButton(
                        brand: brand,
                        label: 'اعتماد',
                        icon: Icons.check_rounded,
                        busy: busy,
                        onTap: _approve,
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      flex: 2,
                      child: SheetButton(brand: brand, label: 'رفض', outlined: true, onTap: busy ? null : _reject),
                    ),
                  ],
                ),
                TextButton(
                  onPressed: busy ? null : _release,
                  child: Text('أفلتها لزميلاتك', style: font(12.5, w7, Palette.slate)),
                ),
              ],
            ),
    );
  }
}

/// رأس صفحات الشريط: عنوانٌ كبير وأيقونة الطابور بلونه، وعدد ما فيه
class TabPage extends StatelessWidget {
  const TabPage({
    super.key,
    required this.title,
    required this.subtitle,
    required this.color,
    required this.icon,
    required this.child,
    this.count,
    this.trailing,
  });

  final String title;
  final String subtitle;
  final Color color;
  final IconData icon;
  final Widget child;
  final int? count;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Column(
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 8 : (AppConfig.demo ? 36 : 14), 16, 8),
          child: Row(
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(
                      color: color.withValues(alpha: .4),
                      blurRadius: 16,
                      spreadRadius: -6,
                      offset: const Offset(0, 8),
                    ),
                  ],
                ),
                child: Icon(icon, color: Colors.white, size: 23),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(child: Text(title, style: display(21, w7, Palette.ink))),
                        if (count != null && count! > 0) ...[
                          const SizedBox(width: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                            decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(20)),
                            child: Text('$count', style: font(12, w8, Colors.white)),
                          ),
                        ],
                      ],
                    ),
                    Text(subtitle, style: font(12, w6, Palette.slate)),
                  ],
                ),
              ),
              ?trailing,
            ],
          ),
        ),
        Expanded(child: child),
      ],
    );
  }
}
