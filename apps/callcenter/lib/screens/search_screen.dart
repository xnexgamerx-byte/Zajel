import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';
import 'cases_screen.dart';
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

/// الشحنة كما تراها الموظّفة: حالتها، ومن يُتّصل به، وسجلّها خطّاً زمنيّاً
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
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                      children: [
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
                        Panel(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              PersonRow(
                                role: 'الزبون · ${d.found.place}',
                                party: Party(name: d.found.recipient, phone: d.found.phone),
                                icon: Icons.person_rounded,
                                color: Queue.couriers,
                              ),
                              if ((d.address ?? '').isNotEmpty || (d.landmark ?? '').isNotEmpty)
                                Padding(
                                  padding: const EdgeInsetsDirectional.only(start: 48, bottom: 6),
                                  child: Text(
                                    [d.address, d.landmark].whereType<String>().where((t) => t.isNotEmpty).join(' — '),
                                    style: font(12, w6, Palette.slate, height: 1.5),
                                  ),
                                ),
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
                              if ((d.notes ?? '').isNotEmpty) ...[
                                const SizedBox(height: 6),
                                Text('ملاحظة التاجر: ${d.notes}', style: font(12, w6, Palette.ink, height: 1.5)),
                              ],
                            ],
                          ),
                        ),
                        const SectionTitle('سجلّها'),
                        Panel(child: _timeline(d.events)),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

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
                      Text(e.status, style: font(13.5, w8, i == 0 ? Palette.ink : Palette.slate)),
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
