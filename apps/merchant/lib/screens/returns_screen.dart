import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'receive_screen.dart';
import 'requests_screen.dart' show openReturnReceipt;

Future<void> openReturns(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReturnsScreen(brand: brand)));

/// «كشوفات الرواجع» (docs/plan/69): ما سُلّم للتاجر من رواجع بإيصالاتها، كلٌّ يُفتح بشحناته، و«استلام راجع»
/// يمسح الإيصال الذي جاء معها.
class ReturnsScreen extends StatefulWidget {
  const ReturnsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<ReturnsScreen> createState() => _ReturnsScreenState();
}

class _ReturnsScreenState extends State<ReturnsScreen> {
  RequestsPage? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.requests();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _scan() async {
    final done = await openReceive(context, brand);
    if (done != null && mounted) await _load();
  }

  Future<void> _open(ReturnReceipt b) async {
    await openReturnReceipt(context, brand, b.id);
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'كشوفات الرواجع', subtitle: 'رواجعك بإيصالاتها وشحناتها'),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          _summary(d),
                          const SizedBox(height: 12),
                          HeroButton(
                            brand: brand,
                            label: 'استلام راجع بالباركود',
                            icon: Icons.qr_code_scanner_rounded,
                            onTap: _scan,
                          ),
                          const SectionTitle('الإيصالات'),
                          if (d.batches.isEmpty)
                            const Empty(
                              icon: Icons.assignment_return_outlined,
                              title: 'لا كشوفات رواجع بعد',
                              text: 'حين تُسلَّم لك رواجع يصلك إيصالها هنا، وتستلمها بمسح باركوده.',
                            )
                          else
                            for (final b in d.batches) ...[_row(d, b), const SizedBox(height: 8)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  /// ما ينتظر استلامه، وما استلمه، وما في الطريق إليه
  Widget _summary(RequestsPage d) {
    final waiting = d.batches.where((b) => b.received == null).toList();
    final received = d.batches.length - waiting.length;
    return WhiteCard(
      radius: 20,
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: IntrinsicHeight(
        child: Row(
          children: [
            _figure('بانتظار استلامك', waiting.length, brand.deep),
            const VerticalDivider(width: 1, color: Palette.line),
            _figure('استلمتها', received, Palette.ok),
            const VerticalDivider(width: 1, color: Palette.line),
            _figure('راجعة إليك الآن', d.returning, Palette.slate),
          ],
        ),
      ),
    );
  }

  Widget _figure(String label, int n, Color color) => Expanded(
    child: Column(
      children: [
        Text(money(n), style: display(20, w8, color, height: 1.2)),
        Text(label, textAlign: TextAlign.center, style: font(11, w6, Palette.slate, height: 1.3)),
      ],
    ),
  );

  Widget _row(RequestsPage d, ReturnReceipt b) => TintedRow(
    brand: brand,
    icon: Icons.inventory_2_rounded,
    title: 'إيصال ${b.number}',
    subtitle: '${b.count} شحنة · ${b.via}${b.at == null ? '' : ' · ${when(b.at)}'}',
    end: Text('أجرة ${money(b.fees)}', style: font(12, w8, Palette.ink)),
    status: b.received != null
        ? const OkPill(text: 'استلمتها')
        : d.scanRequired
        ? ScanPill(brand: brand, text: 'امسح للاستلام')
        : null,
    onTap: () => _open(b),
  );
}
