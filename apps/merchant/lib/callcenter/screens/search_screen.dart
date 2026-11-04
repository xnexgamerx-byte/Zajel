import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp_template.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/follow_up.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'cases_screen.dart';
import 'my_message_screen.dart';
import 'status_sheets.dart';
import 'scan_screen.dart';

/// «ابحث» (docs/plan/73): «الزبون اتّصل بخصوص الوصل كذا» — برقم الوصل أو هاتف الزبون أو اسمه، أو
/// بمسح باركوده. والشحنة بسجلّها، وإن كانت في المعالجة تُفتح منها.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, required this.brand, this.scanFirst = false});

  final Brand brand;

  /// فُتحت من زرّ المسح: الكاميرا أوّلاً
  final bool scanFirst;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final q = TextEditingController();
  List<FoundShipment>? results;
  bool busy = false;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    if (widget.scanFirst) WidgetsBinding.instance.addPostFrameCallback((_) => _scan());
  }

  @override
  void dispose() {
    q.dispose();
    super.dispose();
  }

  Future<void> _search([String? text]) async {
    final term = LatinDigits.convert(text ?? q.text).trim();
    if (term.isEmpty) return;
    if (text != null) q.text = term;
    setState(() => (busy = true, error = null));
    try {
      final list = await Api.instance.search(term);
      if (!mounted) return;
      setState(() => results = list);
      // وصلٌ واحد: يُفتح مباشرة
      if (list.length == 1) _open(list.first);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _scan() async {
    final code = await Navigator.of(context)
        .push<String>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (code != null && mounted) await _search(code);
  }

  void _open(FoundShipment s) => Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => ShipmentScreen(brand: brand, id: s.id),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final list = results;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'ابحث عن شحنة', subtitle: 'الزبون اتّصل؟ رقم الوصل أو هاتفه أو اسمه'),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 10),
              child: Row(
                children: [
                  Expanded(
                    child: Container(
                      height: 50,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Palette.line),
                        boxShadow: cardShadow,
                      ),
                      child: TextField(
                        controller: q,
                        autofocus: !widget.scanFirst,
                        textInputAction: TextInputAction.search,
                        inputFormatters: const [LatinDigits()],
                        onSubmitted: (_) => _search(),
                        style: font(15, w7, Palette.ink),
                        decoration: InputDecoration(
                          hintText: '180240 أو 0780…',
                          hintStyle: font(13.5, w5, Palette.muted),
                          prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted),
                          border: InputBorder.none,
                          contentPadding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Tooltip(
                    message: 'امسح الوصل',
                    child: Tap(
                      radius: 16,
                      shadow: brand.glow(.35),
                      onTap: _scan,
                      child: Ink(
                        width: 50,
                        height: 50,
                        decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(16)),
                        child: const Icon(Icons.qr_code_scanner_rounded, color: Colors.white),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            if (busy) LinearProgressIndicator(color: brand.main, backgroundColor: brand.soft),
            if (error != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: ErrorNote(error!)),
            Expanded(
              child: list == null
                  ? const Empty(
                      icon: Icons.manage_search_rounded,
                      title: 'من اتّصل؟',
                      text: 'اكتب رقم الوصل أو هاتف الزبون أو اسمه، أو امسح الباركود.',
                    )
                  : list.isEmpty
                  ? const Empty(
                      icon: Icons.search_off_rounded,
                      title: 'لا شحنة',
                      text: 'جرّبي الرقم كاملاً، أو هاتف الزبون.',
                    )
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 0, 14, 24),
                      children: [
                        for (final s in list) ...[
                          Tap(
                            radius: 18,
                            onTap: () => _open(s),
                            child: WhiteCard(
                              radius: 18,
                              padding: const EdgeInsets.all(12),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(s.recipient, style: font(14, w8, Palette.ink)),
                                        Text(
                                          [tag(s.number), s.place, if (s.merchant != null) s.merchant!].join(' · '),
                                          style: font(11.5, w6, Palette.slate, height: 1.5),
                                        ),
                                        if (s.until != null) ...[const SizedBox(height: 4), PostponedChip(s.until!)],
                                        if (s.staffNote != null) ...[
                                          const SizedBox(height: 4),
                                          StaffNoteChip(s.staffNote),
                                        ],
                                      ],
                                    ),
                                  ),
                                  Pill(s.statusLabel, color: statusColor(s.status), size: 10.5),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(height: 8),
                        ],
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// الشحنة برقمها (docs/plan/81): يُفتح منها تتبّعها كاملاً — من المعالجة والتذكرة والمحادثة
Future<void> openShipment(BuildContext context, Brand brand, int id) => Navigator.of(context).push(
  MaterialPageRoute(
    builder: (_) => ShipmentScreen(brand: brand, id: id),
  ),
);

/// برقم الوصل وحده (محادثة التاجر تحمل الرقم لا المعرّف): يُبحث عنه ثم يُفتح
Future<void> openShipmentNumber(BuildContext context, Brand brand, String number) async {
  try {
    final found = await Api.instance.search(number);
    final hit = found.where((s) => s.number == number).firstOrNull ?? (found.length == 1 ? found.first : null);
    if (!context.mounted) return;
    if (hit == null) {
      toast(context, 'لم تُوجد الشحنة $number في محافظاتك.', bad: true);
      return;
    }
    await openShipment(context, brand, hit.id);
  } on ApiError catch (e) {
    if (context.mounted) toast(context, e.message, bad: true);
  }
}

/// الشحنة كما تراها الموظّفة: رقمها يُنسخ والوصل كاملاً، وزبونها بهاتفه ظاهراً (بالخط أو واتساب)، وسبب آخر
/// محاولةٍ بتوضيحه، وسجلّها خطّاً زمنيّاً — ومنها تحدّث حالتها، وتعيد للتوصيل راجعاً مؤكَّداً (docs/plan/80)
class ShipmentScreen extends StatefulWidget {
  const ShipmentScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ShipmentScreen> createState() => _ShipmentScreenState();
}

class _ShipmentScreenState extends State<ShipmentScreen> {
  ShipmentDetail? s;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.shipment(widget.id);
      if (mounted) setState(() => (s = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _update(ShipmentDetail d) async {
    final message = await showSheet<String>(context, StatusSheet(brand: brand, shipment: d));
    if (message == null || !mounted) return;
    toast(context, message);
    await _load();
  }

  Future<void> _reschedule(ShipmentDetail d) async {
    final until = await pickPostpone(context, brand);
    if (until == null || !mounted) return;
    try {
      final message = await Api.instance.reschedule(d.found.id, until);
      if (mounted) toast(context, message);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
    await _load();
  }

  Future<void> _reopen(ShipmentDetail d) async {
    final message = await showSheet<String>(context, ReopenSheet(brand: brand, shipment: d));
    if (message == null || !mounted) return;
    toast(context, message);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final d = s;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            PageBar(
              title: d == null ? 'الشحنة' : tag(d.found.number),
              subtitle: d?.found.merchant,
              trailing: d == null ? null : Pill(d.found.statusLabel, color: statusColor(d.found.status), size: 12),
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                        children: [
                          WaybillBar(brand: brand, number: d.found.number, full: _full(d)),
                          const SizedBox(height: 12),
                          if (d.processing) ...[
                            HeroButton(
                              brand: brand,
                              label: 'في المعالجة — افتحيها',
                              icon: Icons.support_agent_rounded,
                              onTap: () async {
                                final message = await openCase(context, brand, d.found.id);
                                if (message != null && context.mounted) toast(context, message);
                                await _load();
                              },
                            ),
                            const SizedBox(height: 12),
                          ],
                          if (d.reason != null || d.until != null) ...[_reasonCard(d), const SizedBox(height: 12)],
                          // ملاحظة للموظّفين (docs/plan/83): «اتّصلتُ ولم يردّ» — تراها زميلاتك في القوائم
                          StaffNotePanel(brand: brand, shipmentId: d.found.id, note: d.staffNote, onSaved: _load),
                          const SizedBox(height: 12),
                          Panel(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                CustomerCard(
                                  brand: brand,
                                  name: d.found.recipient,
                                  place: d.found.place,
                                  phone: d.found.phone,
                                  phoneAlt: d.phoneAlt,
                                  message: WhatsAppTemplate.fill(
                                    recipient: d.found.recipient,
                                    number: d.found.number,
                                    amount: d.found.amount,
                                    merchant: d.found.merchant,
                                    place: d.found.place,
                                    reason: d.reason,
                                  ),
                                ),
                                if ((d.address ?? '').isNotEmpty || (d.landmark ?? '').isNotEmpty)
                                  Padding(
                                    padding: const EdgeInsets.only(top: 8),
                                    child: Text(
                                      [
                                        d.address,
                                        d.landmark,
                                      ].whereType<String>().where((t) => t.isNotEmpty).join(' — '),
                                      style: font(12.5, w6, Palette.ink, height: 1.5),
                                    ),
                                  ),
                                const Divider(height: 22, color: Palette.line),
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
                                const Divider(height: 18, color: Palette.line),
                                Row(
                                  children: [
                                    Text('المبلغ', style: font(12.5, w6, Palette.slate)),
                                    const Spacer(),
                                    Text('${money(d.found.amount)} د.ع', style: display(16, w7, Palette.ink)),
                                  ],
                                ),
                                if ((d.description ?? '').isNotEmpty)
                                  Text(
                                    'المحتوى: ${d.description} · ${d.pieces} قطعة',
                                    style: font(12, w6, Palette.slate, height: 1.6),
                                  ),
                                if ((d.notes ?? '').isNotEmpty) ...[
                                  const SizedBox(height: 6),
                                  Text('ملاحظة التاجر: ${d.notes}', style: font(12, w6, Palette.ink, height: 1.5)),
                                ],
                              ],
                            ),
                          ),
                          if (d.canReopen || d.canReschedule || d.transitions.isNotEmpty) ...[
                            const SizedBox(height: 12),
                            if (d.canReopen) ...[
                              SheetButton(
                                brand: brand,
                                label: 'أعيديها للتوصيل',
                                icon: Icons.replay_rounded,
                                onTap: () => _reopen(d),
                              ),
                              const SizedBox(height: 8),
                            ],
                            // المؤجّلة إلى موعدٍ آخر اتّفقتِ عليه، وتبقى «مؤجل» (docs/plan/83)
                            if (d.canReschedule) ...[
                              SheetButton(
                                brand: brand,
                                label: 'غيّري موعد التأجيل',
                                icon: Icons.edit_calendar_rounded,
                                outlined: true,
                                onTap: () => _reschedule(d),
                              ),
                              const SizedBox(height: 8),
                            ],
                            if (d.transitions.isNotEmpty)
                              SheetButton(
                                brand: brand,
                                label: 'حدّثي الحالة',
                                icon: Icons.published_with_changes_rounded,
                                outlined: d.canReopen,
                                onTap: () => _update(d),
                              ),
                          ],
                          // محادثتا التاجر والمندوب عنها (docs/plan/81)
                          if (d.canChat) ...[
                            const SectionTitle('المحادثات'),
                            ShipmentChats(
                              brand: brand,
                              shipmentId: d.found.id,
                              merchantChat: d.merchantChat,
                              courierChat: d.courierChat,
                              canAsk: d.canAsk,
                              hasCourier: d.hasCourier,
                              onSent: _load,
                              merchantMessage: d.merchantMessage,
                              onEditTemplate: () => editMyMessage(context, brand, then: _load),
                            ),
                          ],
                          const SectionTitle('سجلّها'),
                          Panel(child: _timeline(d.events)),
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  /// سبب آخر محاولةٍ بتوضيحه كما كُتب (docs/plan/80)، وموعد المؤجّلة
  Widget _reasonCard(ShipmentDetail d) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: Queue.processingSoft,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: const Color(0xFFF2D9A6)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(Icons.info_outline_rounded, size: 20, color: Queue.processing),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (d.reason != null)
                Text(
                  'آخر سبب: ${d.reason}${d.attempts > 0 ? ' · المحاولة ${d.attempts}' : ''}',
                  style: font(13, w8, Palette.ink, height: 1.4),
                ),
              if ((d.reasonNote ?? '').isNotEmpty)
                Text(d.reasonNote!, style: font(12.5, w6, Palette.ink, height: 1.55)),
              if (d.until != null)
                Padding(padding: const EdgeInsets.only(top: 6), child: PostponedChip(d.until!, size: 12)),
            ],
          ),
        ),
      ],
    ),
  );

  String _full(ShipmentDetail d) => waybillText(
    number: d.found.number,
    recipient: d.found.recipient,
    phone: d.found.phone,
    phoneAlt: d.phoneAlt,
    place: d.found.place,
    address: d.address,
    landmark: d.landmark,
    amount: d.found.amount,
    merchant: d.found.merchant,
    status: d.found.statusLabel,
    description: d.description,
    pieces: d.pieces,
    notes: d.notes,
  );

  Widget _timeline(List<EventLine> events) => Column(
    children: [
      for (final (i, e) in events.indexed)
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                width: 22,
                child: Column(
                  children: [
                    Container(
                      width: 12,
                      height: 12,
                      margin: const EdgeInsets.only(top: 4),
                      decoration: BoxDecoration(
                        color: i == 0 ? brand.main : Colors.white,
                        shape: BoxShape.circle,
                        border: Border.all(color: i == 0 ? brand.main : Palette.line, width: 2),
                      ),
                    ),
                    if (i < events.length - 1) Expanded(child: Container(width: 2, color: Palette.line)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          if (e.type != 'status_change' && e.type != 'processed') ...[
                            Icon(
                              e.type == 'edited' ? Icons.edit_note_rounded : Icons.receipt_long_rounded,
                              size: 15,
                              color: Queue.processing,
                            ),
                            const SizedBox(width: 4),
                          ],
                          Expanded(child: Text(e.status, style: font(13.5, w8, i == 0 ? Palette.ink : Palette.slate))),
                        ],
                      ),
                      if ((e.note ?? '').isNotEmpty) Text(e.note!, style: font(12, w6, Palette.ink, height: 1.5)),
                      Text(
                        [if (e.by != null) e.by!, when(e.at)].join(' · '),
                        style: font(11, w6, Palette.muted, height: 1.5),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
    ],
  );
}
