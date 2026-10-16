import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/cash_card.dart';
import '../widgets/page.dart';

/// «حسابي» (docs/plan/66): النقد بيده وسقفه، وعمولته، والواجب تسليمه، وآخر الحركات والتسويات —
/// من دفتر الحركات نفسه الذي تقرؤه الشركة.
class CashScreen extends StatefulWidget {
  const CashScreen({super.key, required this.brand, required this.refresh});

  final Brand brand;
  final ValueNotifier<int> refresh;

  @override
  State<CashScreen> createState() => _CashScreenState();
}

class _CashScreenState extends State<CashScreen> {
  CashPage? data;
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
      final d = await Api.instance.cash();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        color: brand.main,
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 18, 16, 130),
          children: [
            Row(
              children: [
                const TabBack(),
                Text('حسابي', style: font(22, w8, Palette.ink)),
              ],
            ),
            Text('نقدك وعمولتك وحركاتك', style: font(12.5, w6, Palette.slate)),
            const SizedBox(height: 14),
            if (d == null)
              Padding(
                padding: const EdgeInsets.only(top: 60),
                child: Waiting(brand: brand, error: error, onRetry: _load),
              )
            else ...[
              CashCard(brand: brand, cash: d.summary, large: true),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _tile(money(d.summary.commission), 'عمولتك المستحقّة', Palette.ok)),
                  const SizedBox(width: 10),
                  Expanded(child: _tile(money(d.summary.netDue), 'الواجب تسليمه', brand.main)),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                'الواجب تسليمه = النقد بيدك − عمولتك المستحقّة. يُسلَّم عند موظف الفرع، ويُطبع لك ورقة بالأرقام.',
                style: font(12, w6, Palette.slate, height: 1.6),
              ),
              const SectionTitle('آخر الحركات'),
              if (d.movements.isEmpty)
                const Empty(
                  icon: Icons.receipt_long_outlined,
                  title: 'لا حركات بعد',
                  text: 'كلّ تحصيلٍ وعمولةٍ وتسليم نقدٍ يظهر هنا.',
                )
              else
                WhiteCard(
                  child: Column(
                    children: [
                      for (final (i, m) in d.movements.indexed) ...[
                        if (i > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: Palette.line),
                        _movement(m),
                      ],
                    ],
                  ),
                ),
              if (d.settlements.isNotEmpty) ...[
                const SectionTitle('آخر التسويات'),
                WhiteCard(
                  child: Column(
                    children: [
                      for (final (i, s) in d.settlements.indexed) ...[
                        if (i > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: Palette.line),
                        _settlement(s),
                      ],
                    ],
                  ),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  Widget _tile(String value, String label, Color color) => WhiteCard(
    padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
    child: Column(
      children: [
        FittedBox(child: Text(value, style: font(19, w8, color))),
        const SizedBox(height: 2),
        Text(label, style: font(12, w6, Palette.slate)),
      ],
    ),
  );

  Widget _movement(Movement m) {
    final color = switch (m.category) {
      'cod_collected' => Palette.info,
      'commission' || 'commission_paid' => Palette.ok,
      'deduction' => Palette.bad,
      _ => Palette.slate,
    };
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Pill(m.short, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                m.shipment != null
                    ? Number(m.shipment!, size: 14)
                    : Text(
                        m.description ?? m.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: font(14, w7, Palette.ink),
                      ),
                Text(when(m.at), style: font(11.5, w6, Palette.slate)),
              ],
            ),
          ),
          Text(money(m.amount), style: font(14, w8, m.credit ? Palette.ok : Palette.ink)),
        ],
      ),
    );
  }

  Widget _settlement(Settlement s) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(s.code, style: font(14, w8, Palette.ink)),
              Text(
                '${s.shipments} شحنة${s.date == null ? '' : ' · \u2066${s.date}\u2069'}',
                style: font(11.5, w6, Palette.slate),
              ),
            ],
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(money(s.net), style: font(14, w8, Palette.ink)),
            Pill(s.statusLabel, color: s.status == 'paid' ? Palette.ok : Palette.slate, size: 10),
          ],
        ),
      ],
    ),
  );
}
