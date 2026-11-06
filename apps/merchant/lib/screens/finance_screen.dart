import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/art.dart';
import '../widgets/bits.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import 'receive_screen.dart';
import 'shipment_screen.dart';

/// «المالية» (docs/plan/54، 70، 123) بتصميم «وصل»: المتاح للسحب في بطاقةٍ داكنة بلون الشركة وطلب المحاسبة منها، و«قيد
/// المطابقة» و«استلام الحساب» بمسح الوصل (docs/plan/69)، و«مؤشراتك هذا الشهر» مقياساً مقسّماً لنسبة التسليم ومنحنى
/// للمحصَّل أسبوعاً أسبوعاً، وكشوفه تذاكرَ بحالة كلٍّ منها، وآخر حركات حسابه.
class FinanceScreen extends StatefulWidget {
  const FinanceScreen({super.key, required this.brand, this.refresh});

  final Brand brand;

  /// يُعاد التحميل حين يتغيّر (شحنةٌ جديدة، قرار معالجة)
  final Listenable? refresh;

  @override
  State<FinanceScreen> createState() => _FinanceScreenState();
}

class _FinanceScreenState extends State<FinanceScreen> {
  Finance? data;
  String? error;
  bool hidden = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh?.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh?.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.finance();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _request(Finance d) async {
    final made = await showSheet<PaymentRequest>(context, _RequestSheet(brand: brand, finance: d));
    if (made == null || !mounted) return;
    toast(context, 'تمّ الطلب ${made.number} — ${money(made.amount)} د.ع');
    _load();
  }

  Future<void> _confirm(Statement s) async {
    try {
      final message = await Api.instance.confirmStatement(s.id);
      if (mounted) toast(context, message);
      _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  /// «استلام الحساب»: يمسح الوصل الذي وصل مع المبلغ (docs/plan/69)
  Future<void> _scan() async {
    final done = await openReceive(context, brand);
    if (done != null) _load();
  }

  void _openStatement(Statement s) => Navigator.of(context)
      .push(
        MaterialPageRoute(
          builder: (_) => StatementScreen(brand: brand, id: s.id),
        ),
      )
      .then((_) => _load());

  String _amount(int v) => hidden ? '••••' : money(v);

  @override
  Widget build(BuildContext context) {
    final d = data;
    final top = MediaQuery.paddingOf(context).top;
    if (d == null) {
      return Center(
        child: error == null
            ? CircularProgressIndicator(color: brand.main)
            : Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(error!, textAlign: TextAlign.center, style: kf(14, Palette.ink, bold: true)),
                    const SizedBox(height: 12),
                    FilledButton(onPressed: _load, child: const Text('أعد المحاولة')),
                  ],
                ),
              ),
      );
    }

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 12 : 36, 16, 130),
        children: [
          Row(
            children: [
              const TabBack(),
              Expanded(child: Text('المالية', style: kf(24, Palette.ink, bold: true))),
              Tooltip(
                message: hidden ? 'أظهر الأرقام' : 'أخفِ الأرقام',
                child: Tap(
                  radius: 22,
                  onTap: () => setState(() => hidden = !hidden),
                  child: Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      border: Border.all(color: Palette.line),
                    ),
                    child: Icon(
                      hidden ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                      size: 18,
                      color: Palette.ink,
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Rise(delay: 60, child: _hero(d)),
          if (d.request != null) ...[const SizedBox(height: 16), _open(d.request!)],
          const SizedBox(height: 16),
          Rise(delay: 140, child: _tiles(d)),
          const SizedBox(height: 16),
          Rise(delay: 200, child: _extras(d)),
          if (d.month != null) ...[const SizedBox(height: 16), Rise(delay: 260, child: _indicators(d.month!))],
          const SizedBox(height: 16),
          _title('كشوفك ودفعاتها'),
          if (d.statements.isEmpty) _none('لم تُدفع لك كشوفٌ بعد.'),
          for (final s in d.statements) ...[_statement(d, s), const SizedBox(height: 10)],
          const SizedBox(height: 6),
          _title('آخر حركات حسابك'),
          if (d.movements.isEmpty) _none('لا حركات بعد.'),
          if (d.movements.isNotEmpty)
            Container(
              clipBehavior: Clip.antiAlias,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                boxShadow: cardShadow,
              ),
              child: Material(
                type: MaterialType.transparency,
                child: Column(children: [for (final (i, m) in d.movements.indexed) _movement(m, first: i == 0)]),
              ),
            ),
        ],
      ),
    );
  }

  /// البطاقة الداكنة بلون الشركة: المتاح للسحب، وطلب المحاسبة منها، ورسم النقد في طرفها
  Widget _hero(Finance d) {
    final method = d.methods.where((m) => m.value == d.method).firstOrNull;
    final canRequest = d.request == null && d.available > 0;
    return Container(
      constraints: const BoxConstraints(minHeight: 184),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: brand.night, borderRadius: BorderRadius.circular(24)),
      child: CustomPaint(
        painter: const IsoGrid(),
        child: Stack(
          children: [
            Positioned(left: 4, bottom: 8, child: Art('cash', brand: brand, width: 150, height: 120)),
            Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 196),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('المتاح للسحب', style: kf(12, Colors.white.withValues(alpha: .82), bold: true)),
                        const SizedBox(height: 4),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: AlignmentDirectional.centerStart,
                          child: Text.rich(
                            TextSpan(
                              text: _amount(d.available),
                              children: [TextSpan(text: ' د.ع', style: kf(14, Colors.white.withValues(alpha: .85)))],
                            ),
                            style: kf(24, Colors.white, bold: true),
                          ),
                        ),
                        if (method != null) ...[
                          const SizedBox(height: 4),
                          Text(
                            'إلى ${method.label}${d.account == null ? '' : ' \u2066${d.account}\u2069'}',
                            style: kf(12, Colors.white.withValues(alpha: .88)),
                          ),
                        ],
                      ],
                    ),
                  ),
                  if (canRequest) ...[
                    const SizedBox(height: 16),
                    Tap(
                      shadow: const [
                        BoxShadow(color: Color(0x80000000), blurRadius: 18, spreadRadius: -10, offset: Offset(0, 10)),
                      ],
                      radius: 14,
                      onTap: () => _request(d),
                      child: Ink(
                        height: 44,
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text('اطلب محاسبة', style: kf(14, brand.main, bold: true)),
                            const SizedBox(width: 8),
                            Icon(Icons.arrow_forward_rounded, size: 16, color: brand.main),
                          ],
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// طلبٌ مفتوح: ينتظر المعالجة، فلا طلب غيره
  Widget _open(PaymentRequest r) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(18)),
    child: Row(
      children: [
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(12)),
          child: const Icon(Icons.hourglass_top_rounded, size: 16, color: Colors.white),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('طلبك ${r.number} بانتظار المعالجة', style: kf(14, Palette.ink, bold: true)),
              Text(
                '${_amount(r.amount)} د.ع، ${r.method}${r.at == null ? '' : '، ${when(r.at)}'}',
                style: kf(12, Palette.slate),
              ),
            ],
          ),
        ),
      ],
    ),
  );

  /// «قيد المطابقة»، و«استلام الحساب» بمسح الوصل حين تطلبه الشركة
  Widget _tiles(Finance d) {
    final waiting = d.statements.where((s) => s.paid && !s.confirmed).length;
    final pending = Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: cardShadow),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
            child: Icon(Icons.hourglass_top_rounded, size: 16, color: brand.main),
          ),
          const SizedBox(height: 8),
          Text('قيد المطابقة', style: kf(12, Palette.muted, bold: true)),
          Text(_amount(d.pending), style: kf(16, Palette.ink, bold: true)),
          Text(d.pending > 0 ? 'نقده مع المندوب حتى يُحاسَب' : 'لا شيء قيد المطابقة', style: kf(12, Palette.muted)),
        ],
      ),
    );
    if (!d.scanRequired) return pending;

    final receive = Tap(
      radius: 20,
      onTap: _scan,
      child: Ink(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: brand.soft,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: brand.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(12)),
                  child: const Icon(Icons.qr_code_scanner_rounded, size: 16, color: Colors.white),
                ),
                const Spacer(),
                if (waiting > 0)
                  Container(
                    height: 24,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(12)),
                    child: Text('$waiting بانتظارك', style: kf(12, Colors.white, bold: true, height: 1.2)),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text('استلام الحساب', style: kf(16, Palette.ink, bold: true)),
            Text('امسح وصل المبلغ الذي وصلك', style: kf(12, Palette.slate)),
          ],
        ),
      ),
    );
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: pending),
          const SizedBox(width: 12),
          Expanded(child: receive),
        ],
      ),
    );
  }

  /// ما وراء المتاح: الإجمالي، وكشفٌ أُقفل ينتظر الدفع، وسلفةٌ تُقتطع
  Widget _extras(Finance d) {
    Widget row(IconData icon, String label, int amount, {String? sub, Color? color}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color ?? Palette.ink),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: kf(14, const Color(0xFF3D3337))),
                if (sub != null) Text(sub, style: kf(12, Palette.muted)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text.rich(
            TextSpan(
              text: _amount(amount),
              children: [TextSpan(text: ' د.ع', style: kf(12, Palette.muted))],
            ),
            style: kf(16, color ?? Palette.ink, bold: true),
          ),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: cardShadow),
      child: Column(
        children: [
          row(
            Icons.account_balance_wallet_outlined,
            d.owed ? 'إجمالي المستحقات عن الواصل' : 'عليك للشركة',
            d.total,
            color: d.owed ? null : Palette.returnRed,
          ),
          if (d.awaitingPayment > 0)
            row(
              Icons.check_circle_outline_rounded,
              'بانتظار الدفع',
              d.awaitingPayment,
              sub: 'كشفٌ أُقفل ولم يُدفع بعد',
              color: Palette.ok,
            ),
          if (d.advances > 0)
            row(
              Icons.payments_outlined,
              'سلفة عليك',
              d.advances,
              sub: 'تُقتطع من الكشف القادم',
              color: Palette.returnRed,
            ),
        ],
      ),
    );
  }

  /// «مؤشراتك هذا الشهر» (docs/plan/70، 123): نسبة التسليم مقياساً مقسّماً يمتلئ من اليمين، وتحته شريط
  /// المنتهية وغيرها، ثم المحصَّل منحنىً أسبوعاً أسبوعاً، وثلاث خانات
  Widget _indicators(MonthStats m) {
    final growth = m.growth;
    final ended = m.delivered + m.returned;
    final open = math.max(0, m.shipments - ended);
    Widget kpi(IconData icon, Tone tone, String value, String label, {double? bar}) => Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
        decoration: BoxDecoration(color: const Color(0xFFF7F5F4), borderRadius: BorderRadius.circular(16)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 32,
              height: 32,
              decoration: BoxDecoration(color: tone.tint, borderRadius: BorderRadius.circular(10)),
              child: Icon(icon, size: 16, color: tone.ink),
            ),
            const SizedBox(height: 6),
            Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(16, Palette.ink, bold: true)),
            Text(label, maxLines: 1, style: kf(12, Palette.slate)),
            if (bar != null) ...[
              const SizedBox(height: 6),
              ClipRRect(
                borderRadius: BorderRadius.circular(2),
                child: LinearProgressIndicator(
                  value: bar.clamp(0, 1),
                  minHeight: 4,
                  color: tone.ink,
                  backgroundColor: const Color(0xFFF1D9D5),
                ),
              ),
            ],
          ],
        ),
      ),
    );
    Widget legend(Color color, String label, int n) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 8,
          height: 8,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: 6),
        Text.rich(
          TextSpan(
            text: '$label ',
            children: [TextSpan(text: money(n), style: kf(12, Palette.ink, bold: true))],
          ),
          style: kf(12, Palette.slate),
        ),
      ],
    );
    final brandTone = Tone(brand.main, brand.soft);
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), boxShadow: cardShadow),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
            child: Row(
              children: [
                Expanded(child: Text('مؤشراتك هذا الشهر', style: kf(16, Palette.ink, bold: true))),
                Container(
                  height: 24,
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
                  child: Text(m.label, style: kf(12, brand.main, bold: true, height: 1.2)),
                ),
              ],
            ),
          ),
          Semantics(
            label: m.rate == null
                ? 'لا شحنات منتهية هذا الشهر'
                : 'نسبة التسليم ${m.rate}٪: من $ended شحنة انتهت سُلّمت ${m.delivered} ورجعت ${m.returned}',
            excludeSemantics: true,
            child: _Gauge(brand: brand, rate: m.rate, delivered: m.delivered, ended: ended),
          ),
          if (m.shipments > 0)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Semantics(
                label: 'شحنات الشهر ${m.shipments}: سُلّمت ${m.delivered}، ورجعت ${m.returned}، ولم تنتهِ $open',
                excludeSemantics: true,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      height: 10,
                      child: Row(
                        children: [
                          for (final (n, color) in [
                            (m.delivered, Tone.green.ink),
                            (m.returned, Tone.red.ink),
                            (open, Tone.blue.ink),
                          ].where((e) => e.$1 > 0)) ...[
                            Expanded(
                              flex: n,
                              child: Container(
                                margin: const EdgeInsets.symmetric(horizontal: 1.5),
                                decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(5)),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      alignment: WrapAlignment.spaceBetween,
                      spacing: 8,
                      runSpacing: 4,
                      children: [
                        legend(Tone.green.ink, 'سُلّمت', m.delivered),
                        legend(Tone.red.ink, 'رجعت', m.returned),
                        legend(Tone.blue.ink, 'لم تنتهِ', open),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          const Padding(padding: EdgeInsets.symmetric(vertical: 12), child: _Perforation(height: 16, notch: 16)),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('مبيعاتك المحصَّلة', style: kf(12, Palette.slate)),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: AlignmentDirectional.centerStart,
                        child: Text.rich(
                          TextSpan(
                            text: _amount(m.collected),
                            children: [TextSpan(text: ' د.ع', style: kf(14, Palette.muted))],
                          ),
                          style: kf(24, Palette.ink, bold: true),
                        ),
                      ),
                    ],
                  ),
                ),
                if (growth != null)
                  Container(
                    height: 28,
                    margin: const EdgeInsets.only(top: 4),
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                    decoration: BoxDecoration(
                      color: growth >= 0 ? Tone.green.tint : Tone.red.tint,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // ينقلب مع العربية: يصعد نحو اليسار كما في التصميم
                        Icon(
                          growth >= 0 ? Icons.trending_up_rounded : Icons.trending_down_rounded,
                          size: 14,
                          color: growth >= 0 ? Tone.green.ink : Tone.red.ink,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          '${growth.abs()}% عن الشهر الماضي',
                          style: kf(12, growth >= 0 ? Tone.green.ink : Tone.red.ink, bold: true, height: 1.2),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          if (m.weeks.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
              child: _Sales(brand: brand, weeks: m.weeks, amount: _amount),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
            // الخانات الثلاث بطولٍ واحد، ولو كان في إحداها شريط
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  kpi(Icons.receipt_long_outlined, brandTone, _amount(m.average), 'متوسط الطلب'),
                  const SizedBox(width: 8),
                  kpi(
                    Icons.undo_rounded,
                    Tone.red,
                    m.returnRate == null ? '—' : '${m.returnRate}%',
                    'نسبة الراجع',
                    bar: m.returnRate == null ? null : m.returnRate! / 100,
                  ),
                  const SizedBox(width: 8),
                  kpi(Icons.inventory_2_outlined, Tone.blue, money(m.shipments), 'شحنات الشهر'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _title(String text) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(text, style: kf(16, Palette.ink, bold: true)),
  );

  Widget _none(String text) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(text, style: kf(12, Palette.slate)),
  );

  /// الكشف تذكرةٌ مثقوبة بحالته: يُضغط فتُفتح تفاصيله بشحناته (docs/plan/61)
  Widget _statement(Finance d, Statement s) {
    final Widget status;
    if (s.confirmed) {
      status = Container(
        height: 32,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(color: Tone.green.tint, borderRadius: BorderRadius.circular(16)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.check_rounded, size: 14, color: Tone.green.ink),
            const SizedBox(width: 6),
            Text('استلمتها', style: kf(12, Tone.green.ink, bold: true, height: 1.2)),
          ],
        ),
      );
    } else if (s.paid) {
      // الشركة تطلب الوصل نفسه فيُمسح (docs/plan/69)، أو يؤكّد بزرّ
      status = Tap(
        radius: 14,
        onTap: d.scanRequired ? _scan : () => _confirm(s),
        child: Ink(
          height: 44,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(14)),
          child: Center(
            widthFactor: 1,
            child: Text(d.scanRequired ? 'امسح للتأكيد' : 'استلمتُها', style: kf(14, Colors.white, bold: true)),
          ),
        ),
      );
    } else {
      status = Container(
        height: 32,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        alignment: Alignment.center,
        decoration: BoxDecoration(color: Tone.amber.tint, borderRadius: BorderRadius.circular(16)),
        child: Text(s.status, style: kf(12, Tone.amber.ink, bold: true, height: 1.2)),
      );
    }
    final sub = [
      '${s.count} شحنة',
      if (s.at != null) when(s.at),
      if (s.advance > 0) 'خُصم ${money(s.advance)} للسلفة',
    ].join('، ');
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [BoxShadow(color: Color(0x141C1416), blurRadius: 16, offset: Offset(0, 8))],
      ),
      child: Material(
        color: Colors.white,
        clipBehavior: Clip.antiAlias,
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          onTap: () => _openStatement(s),
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
                child: Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(color: brand.soft, shape: BoxShape.circle),
                      child: Icon(Icons.receipt_long_outlined, size: 18, color: brand.main),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.code, style: kf(14, Palette.ink, bold: true)),
                          Text(sub, style: kf(12, Palette.muted)),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(_amount(s.net), style: kf(16, Palette.ink, bold: true)),
                  ],
                ),
              ),
              const _Perforation(height: 12, notch: 12),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        s.reference == null ? '' : 'مرجع الدفع ${s.reference}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: kf(12, Palette.muted),
                      ),
                    ),
                    const SizedBox(width: 12),
                    status,
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// حركةٌ في الحساب: داخلةٌ خضراء وخارجةٌ حمراء، وما كان عن شحنةٍ يفتحها
  Widget _movement(Movement m, {required bool first}) {
    final plus = m.amount >= 0;
    final tone = plus ? Tone.green : Tone.red;
    return InkWell(
      onTap: m.shipment == null ? null : () => openShipmentNumber(context, brand, m.shipment!),
      child: Container(
        constraints: const BoxConstraints(minHeight: 64),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          border: first ? null : const Border(top: BorderSide(color: Color(0xFFF0EBED))),
        ),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: tone.tint, shape: BoxShape.circle),
              child: Icon(plus ? Icons.south_rounded : Icons.north_rounded, size: 16, color: tone.ink),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(m.label, maxLines: 2, overflow: TextOverflow.ellipsis, style: kf(14, Palette.ink, bold: true)),
                  if (m.at != null) Text(when(m.at), style: kf(12, Palette.muted)),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Text(
              '${plus ? '+' : '−'}${_amount(m.amount.abs())}',
              textDirection: TextDirection.ltr,
              style: kf(14, tone.ink, bold: true),
            ),
          ],
        ),
      ),
    );
  }
}

/// خطٌّ متقطّع بين ثقبين على حافتي البطاقة: تذكرةٌ تُقطع
class _Perforation extends StatelessWidget {
  const _Perforation({required this.height, required this.notch});

  final double height;
  final double notch;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: height,
    child: Stack(
      clipBehavior: Clip.none,
      children: [
        Positioned(
          left: 18,
          right: 18,
          top: height / 2 - 1,
          child: CustomPaint(size: const Size(double.infinity, 2), painter: const _Dash()),
        ),
        for (final side in [-notch / 2, null])
          Positioned(
            left: side,
            right: side == null ? -notch / 2 : null,
            top: (height - notch) / 2,
            child: Container(
              width: notch,
              height: notch,
              decoration: const BoxDecoration(color: Palette.bg, shape: BoxShape.circle),
            ),
          ),
      ],
    ),
  );
}

class _Dash extends CustomPainter {
  const _Dash();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = const Color(0xFFE4DDE0)
      ..strokeWidth = 1.5;
    for (var x = 0.0; x < size.width; x += 7) {
      canvas.drawLine(Offset(x, 1), Offset(math.min(x + 4, size.width), 1), paint);
    }
  }

  @override
  bool shouldRepaint(_Dash old) => false;
}

/// نسبة التسليم (docs/plan/123): سبعٌ وثلاثون شَرطة على نصف دائرة تمتلئ من اليمين بلون الشركة، ونقطةٌ عند النسبة،
/// والرقم في وسطها بما سُلّم من المنتهية
class _Gauge extends StatelessWidget {
  const _Gauge({required this.brand, required this.rate, required this.delivered, required this.ended});

  final Brand brand;
  final int? rate;
  final int delivered;
  final int ended;

  @override
  Widget build(BuildContext context) {
    final still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    return SizedBox(
      height: 162,
      child: LayoutBuilder(
        builder: (context, box) {
          final cx = box.maxWidth / 2;
          return Stack(
            clipBehavior: Clip.none,
            children: [
              // شبكةٌ خفيفة تذوب حول المقياس، وتوهّجٌ بلون الشركة تحته
              Positioned.fill(
                child: ShaderMask(
                  blendMode: BlendMode.dstIn,
                  shaderCallback: (r) => const RadialGradient(
                    center: Alignment(0, .76),
                    radius: .62,
                    colors: [Colors.black, Colors.transparent],
                    stops: [.3, 1],
                  ).createShader(r),
                  child: const CustomPaint(painter: IsoGrid(color: Color(0x0D1C1416), step: 16)),
                ),
              ),
              Positioned(
                left: cx - 110,
                bottom: 14,
                width: 220,
                height: 110,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(110)),
                    gradient: RadialGradient(
                      center: Alignment.bottomCenter,
                      radius: 1,
                      colors: [brand.soft, brand.soft.withValues(alpha: 0)],
                    ),
                  ),
                ),
              ),
              Positioned.fill(
                child: TweenAnimationBuilder<double>(
                  tween: Tween(begin: still ? 1 : 0, end: 1),
                  duration: Duration(milliseconds: still ? 0 : 1700),
                  builder: (_, t, _) => CustomPaint(
                    painter: _Ticks(brand: brand, value: (rate ?? 0) / 100, t: t),
                  ),
                ),
              ),
              Positioned(
                left: 0,
                right: 0,
                top: 64,
                child: Column(
                  children: [
                    Text(rate == null ? '—' : '$rate%', style: kf(32, Palette.ink, bold: true, height: 1.25)),
                    Text('نسبة التسليم', style: kf(12, Palette.ink, bold: true)),
                    Text(rate == null ? 'لا منتهية بعد' : '$delivered من $ended انتهت', style: kf(12, Palette.slate)),
                  ],
                ),
              ),
              Positioned(
                left: cx + 86,
                top: 140,
                width: 36,
                child: Text('0%', textAlign: TextAlign.center, style: kf(12, Palette.muted)),
              ),
              Positioned(
                left: cx - 122,
                top: 140,
                width: 36,
                child: Text('100%', textAlign: TextAlign.center, style: kf(12, Palette.muted)),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _Ticks extends CustomPainter {
  _Ticks({required this.brand, required this.value, required this.t});

  final Brand brand;
  final double value;

  /// تقدّم الحركة من 0 إلى 1: الشَّرطات تمتلئ واحدةً بعد واحدة ثم تظهر النقطة
  final double t;

  static const n = 37;
  static const total = 1700.0;

  @override
  void paint(Canvas canvas, Size size) {
    final cx = size.width / 2;
    const cy = 134.0, r1 = 94.0, r2 = 114.0, rm = 80.0;
    // القوس المنقّط في الداخل
    final dots = Paint()..color = const Color(0xFFDCD4D8);
    for (var a = 0.0; a <= math.pi + 1e-9; a += 6 / rm) {
      canvas.drawCircle(Offset(cx + rm * math.cos(a), cy - rm * math.sin(a)), .9, dots);
    }
    final ms = t * total;
    final base = Paint()
      ..color = const Color(0xFFEFE9EB)
      ..strokeWidth = 5
      ..strokeCap = StrokeCap.round;
    var last = -1;
    for (var k = 0; k < n; k++) {
      // الأولى في أقصى اليمين: الزاوية صفر
      final a = math.pi * k / (n - 1);
      final p1 = Offset(cx + r1 * math.cos(a), cy - r1 * math.sin(a));
      final p2 = Offset(cx + r2 * math.cos(a), cy - r2 * math.sin(a));
      canvas.drawLine(p1, p2, base);
      if (value > 0 && k / (n - 1) <= value + 1e-9) {
        last = k;
        final shown = ((ms - (300 + k * 24)) / 200).clamp(0.0, 1.0);
        if (shown <= 0) continue;
        final op = .55 + .45 * k / ((n - 1) * value);
        canvas.drawLine(
          p1,
          p2,
          Paint()
            ..color = brand.main.withValues(alpha: (op * shown).clamp(0.0, 1.0))
            ..strokeWidth = 5
            ..strokeCap = StrokeCap.round,
        );
      }
    }
    if (last < 0) return;
    final pop = ((ms - (300 + last * 24 + 120)) / 260).clamp(0.0, 1.0);
    if (pop <= 0) return;
    final a = math.pi * value;
    final c = Offset(cx + rm * math.cos(a), cy - rm * math.sin(a));
    final scale = Curves.easeOutBack.transform(pop);
    canvas.drawCircle(c, 11 * scale, Paint()..color = brand.main.withValues(alpha: .14));
    canvas.drawCircle(c, 6.75 * scale, Paint()..color = Colors.white);
    canvas.drawCircle(c, 4.25 * scale, Paint()..color = brand.main);
  }

  @override
  bool shouldRepaint(_Ticks old) => old.t != t || old.value != value || old.brand.main != brand.main;
}

/// المحصَّل أسبوعاً أسبوعاً (docs/plan/123): منحنىً من الصفر — الأسبوع الأوّل يميناً كما تُقرأ العربية — بمساحةٍ تحته،
/// ونقطةٌ لكلّ أسبوع تُلمس فتنتقل إليها الفقاعة بمبلغه. يبدأ على هذا الأسبوع
class _Sales extends StatefulWidget {
  const _Sales({required this.brand, required this.weeks, required this.amount});

  final Brand brand;
  final List<int> weeks;
  final String Function(int) amount;

  @override
  State<_Sales> createState() => _SalesState();
}

class _SalesState extends State<_Sales> {
  late int week = widget.weeks.length - 1;

  static const _ordinals = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];
  static const _pt = 64.0, _ph = 92.0, _h = 186.0, _bubble = 128.0;
  static const _move = Duration(milliseconds: 400);
  static const _curve = Cubic(.2, .8, .2, 1);

  String _name(int i) => i < _ordinals.length ? _ordinals[i] : '${i + 1}';

  /// أعلى المحور رقمٌ مدوّر فوق أعلى أسبوع: 512 ألفاً ← 600 ألف
  double get _max {
    final top = widget.weeks.fold<int>(0, math.max);
    if (top <= 0) return 1;
    final step = math.pow(10, (math.log(top) / math.ln10).floor()).toDouble();
    return ((top * 1.1 / step).ceil() * step).toDouble();
  }

  @override
  void didUpdateWidget(_Sales old) {
    super.didUpdateWidget(old);
    if (week >= widget.weeks.length) week = widget.weeks.length - 1;
  }

  @override
  Widget build(BuildContext context) {
    final weeks = widget.weeks;
    final brand = widget.brand;
    final still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    return Semantics(
      label:
          'مبيعاتك المحصَّلة بالأسابيع: '
          '${[for (final (i, v) in weeks.indexed) 'الأسبوع ${_name(i)} ${widget.amount(v)} د.ع'].join('، ')}',
      child: SizedBox(
        height: _h,
        child: LayoutBuilder(
          builder: (context, box) {
            final w = box.maxWidth;
            final max = _max;
            final n = weeks.length;
            final xs = [for (var i = 0; i < n; i++) n == 1 ? w / 2 : w - 34 - i * ((w - 68) / (n - 1))];
            final ys = [for (final v in weeks) _pt + _ph - (v / max) * _ph];
            final sx = xs[week], sy = ys[week];
            final bl = (sx - _bubble / 2).clamp(0.0, math.max(0.0, w - _bubble)).toDouble();
            return Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned.fill(
                  child: TweenAnimationBuilder<double>(
                    tween: Tween(begin: still ? 1 : 0, end: 1),
                    duration: Duration(milliseconds: still ? 0 : 1400),
                    builder: (_, t, _) => CustomPaint(
                      painter: _Curve(xs: xs, ys: ys, max: max, color: brand.main, t: t),
                    ),
                  ),
                ),
                // خطّ الأسبوع المختار من نقطته إلى الأساس
                AnimatedPositioned(
                  duration: _move,
                  curve: _curve,
                  left: sx - .75,
                  top: sy,
                  height: _pt + _ph - sy,
                  width: 1.5,
                  child: CustomPaint(painter: _VDash(brand.border)),
                ),
                for (final (i, x) in xs.indexed) ...[
                  Positioned(
                    left: x - 5,
                    top: ys[i] - 5,
                    child: Container(
                      width: 10,
                      height: 10,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                        border: Border.all(color: brand.main, width: 2),
                      ),
                    ),
                  ),
                  Positioned(
                    left: x - 36,
                    top: 166,
                    width: 72,
                    child: Text(
                      _name(i),
                      textAlign: TextAlign.center,
                      style: kf(12, i == week ? Palette.ink : Palette.muted, bold: i == week),
                    ),
                  ),
                ],
                AnimatedPositioned(
                  duration: _move,
                  curve: _curve,
                  left: sx - 9,
                  top: sy - 9,
                  child: IgnorePointer(
                    child: Container(
                      width: 18,
                      height: 18,
                      decoration: BoxDecoration(
                        color: brand.main,
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 4),
                        boxShadow: [
                          BoxShadow(color: brand.border, spreadRadius: 1),
                          BoxShadow(color: brand.halo, blurRadius: 14, offset: const Offset(0, 6), spreadRadius: -4),
                        ],
                      ),
                    ),
                  ),
                ),
                // الفقاعة: اسم الأسبوع ومبلغه، وسهمٌ صغيرٌ إلى نقطته
                AnimatedPositioned(
                  duration: _move,
                  curve: _curve,
                  left: bl,
                  top: sy - 72,
                  width: _bubble,
                  child: IgnorePointer(
                    child: Stack(
                      clipBehavior: Clip.none,
                      children: [
                        Positioned(
                          left: sx - bl - 6,
                          bottom: -5,
                          child: Transform.rotate(
                            angle: math.pi / 4,
                            child: Container(
                              width: 12,
                              height: 12,
                              decoration: BoxDecoration(color: Palette.ink, borderRadius: BorderRadius.circular(2)),
                            ),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: Palette.ink,
                            borderRadius: BorderRadius.circular(12),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x991C1416),
                                blurRadius: 20,
                                offset: Offset(0, 10),
                                spreadRadius: -10,
                              ),
                            ],
                          ),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text('الأسبوع ${_name(week)}', style: kf(12, Colors.white.withValues(alpha: .78))),
                              Text(
                                '${widget.amount(weeks[week])} د.ع',
                                maxLines: 1,
                                style: kf(14, Colors.white, bold: true),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                // لمسة الأسبوع: عمودٌ عريض حول نقطته
                for (final (i, x) in xs.indexed)
                  Positioned(
                    left: x - 43,
                    top: 0,
                    width: 86,
                    height: _h,
                    child: Semantics(
                      button: true,
                      selected: i == week,
                      label: 'الأسبوع ${_name(i)}: ${widget.amount(weeks[i])} د.ع',
                      child: GestureDetector(
                        behavior: HitTestBehavior.translucent,
                        onTap: () => setState(() => week = i),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _Curve extends CustomPainter {
  _Curve({required this.xs, required this.ys, required this.max, required this.color, required this.t});

  final List<double> xs;
  final List<double> ys;
  final double max;
  final Color color;
  final double t;

  static const _pt = 64.0, _ph = 92.0;

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final bottom = _pt + _ph;
    // خطوط المحور: ثلثٌ وثلثان والأعلى متقطّعة، والأساس متّصل
    final grid = Paint()
      ..color = const Color(0xFFEFE9EB)
      ..strokeWidth = 1;
    for (final f in [1 / 3, 2 / 3, 1.0]) {
      final y = bottom - f * _ph;
      for (var x = 8.0; x < w - 8; x += 8) {
        canvas.drawLine(Offset(x, y), Offset(math.min(x + 3, w - 8), y), grid);
      }
    }
    canvas.drawLine(
      Offset(8, bottom),
      Offset(w - 8, bottom),
      Paint()
        ..color = const Color(0xFFE4DDE0)
        ..strokeWidth = 1,
    );
    final n = xs.length;
    if (n == 0) return;
    // منحنى كاتمل-روم إلى بيزييه، كما في ملف التصميم
    final line = Path()..moveTo(xs[0], ys[0]);
    for (var i = 0; i < n - 1; i++) {
      final p0 = math.max(0, i - 1), p3 = math.min(n - 1, i + 2);
      final c1 = Offset(xs[i] + (xs[i + 1] - xs[p0]) / 6, ys[i] + (ys[i + 1] - ys[p0]) / 6);
      final c2 = Offset(xs[i + 1] - (xs[p3] - xs[i]) / 6, ys[i + 1] - (ys[p3] - ys[i]) / 6);
      line.cubicTo(c1.dx, c1.dy, c2.dx, c2.dy, xs[i + 1], ys[i + 1]);
    }
    // المساحة تظهر بعد أن يُرسم الخطّ
    final fill = ((t - .55) / .45).clamp(0.0, 1.0);
    if (fill > 0 && n > 1) {
      final area = Path.from(line)
        ..lineTo(xs[n - 1], bottom)
        ..lineTo(xs[0], bottom)
        ..close();
      canvas.drawPath(
        area,
        Paint()
          ..shader = ui.Gradient.linear(Offset(0, _pt - 10), Offset(0, bottom), [
            color.withValues(alpha: .26 * fill),
            color.withValues(alpha: 0),
          ]),
      );
    }
    final draw = (t / .7).clamp(0.0, 1.0);
    final stroke = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    if (draw >= 1) {
      canvas.drawPath(line, stroke);
    } else if (draw > 0) {
      for (final m in line.computeMetrics()) {
        canvas.drawPath(m.extractPath(0, m.length * draw), stroke);
      }
    }
  }

  @override
  bool shouldRepaint(_Curve old) => old.t != t || old.max != max || old.color != color || old.ys != ys;
}

class _VDash extends CustomPainter {
  _VDash(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.5;
    for (var y = 0.0; y < size.height; y += 6) {
      canvas.drawLine(Offset(.75, y), Offset(.75, math.min(y + 3, size.height)), paint);
    }
  }

  @override
  bool shouldRepaint(_VDash old) => old.color != color;
}

/// «اطلب محاسبة»: الطريقة من طرق الشركة، ورقم البطاقة أو المحفظة لما سوى النقد
class _RequestSheet extends StatefulWidget {
  const _RequestSheet({required this.brand, required this.finance});

  final Brand brand;
  final Finance finance;

  @override
  State<_RequestSheet> createState() => _RequestSheetState();
}

class _RequestSheetState extends State<_RequestSheet> {
  final details = TextEditingController();
  final note = TextEditingController();
  late String method;
  bool saving = false;
  String? error;

  @override
  void initState() {
    super.initState();
    final methods = widget.finance.methods;
    method = methods.any((m) => m.value == widget.finance.method) ? widget.finance.method! : methods.first.value;
  }

  @override
  void dispose() {
    details.dispose();
    note.dispose();
    super.dispose();
  }

  PayoutMethod get _chosen => widget.finance.methods.firstWhere((m) => m.value == method);

  Future<void> _save() async {
    setState(() => (saving = true, error = null));
    try {
      final made = await Api.instance.requestPayment(
        method: method,
        details: details.text.trim(),
        note: note.text.trim(),
      );
      if (mounted) Navigator.of(context).pop(made);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, saving = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    final f = widget.finance;
    final saved = f.account != null && method == f.method;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 12, 16, MediaQuery.viewInsetsOf(context).bottom + 24),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SheetHandle(),
              const SizedBox(height: 12),
              Text('اطلب محاسبة', style: kf(16, Palette.ink, bold: true)),
              Text('يُطلب المتاح للسحب: ${money(f.available)} د.ع', style: kf(12, Palette.slate)),
              const SizedBox(height: 16),
              Text('طريقة الدفع', style: kf(14, Palette.ink, bold: true)),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final m in f.methods)
                    Semantics(
                      selected: m.value == method,
                      button: true,
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () => setState(() => method = m.value),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          height: 44,
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: m.value == method ? brand.soft : Colors.white,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: m.value == method ? brand.main : Palette.line, width: 1.5),
                          ),
                          child: Text(
                            m.label,
                            style: kf(14, m.value == method ? brand.main : Palette.ink, bold: true, height: 1.3),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              if (_chosen.details) ...[
                const SizedBox(height: 12),
                InputBox(
                  controller: details,
                  hint: saved ? 'المحفوظ ${f.account} — اتركه فارغاً لتستعمله' : (_chosen.hint ?? 'الرقم واسم صاحبه'),
                ),
              ],
              const SizedBox(height: 10),
              InputBox(controller: note, hint: 'ملاحظة (اختياري)'),
              if (error != null) ErrorNote(error!),
              const SizedBox(height: 16),
              SheetButton(brand: brand, label: 'أرسل الطلب', busy: saving, onTap: _save),
            ],
          ),
        ),
      ),
    );
  }
}

/// تفاصيل الكشف (docs/plan/61): مجاميعه، وشحناته سطراً سطراً — والسطر يفتح شحنته
class StatementScreen extends StatefulWidget {
  const StatementScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<StatementScreen> createState() => _StatementScreenState();
}

class _StatementScreenState extends State<StatementScreen> {
  StatementDetail? data;
  String? error;
  bool confirming = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.statement(widget.id);
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _confirm() async {
    // الشركة تطلب الوصل نفسه: يُؤكَّد بمسح باركوده (docs/plan/69)
    if (data?.scanRequired ?? false) {
      await openReceive(context, brand);
      await _load();
      return;
    }
    setState(() => confirming = true);
    try {
      final message = await Api.instance.confirmStatement(widget.id);
      if (mounted) toast(context, message);
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => confirming = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: d?.statement.code ?? 'الكشف',
              subtitle: d == null
                  ? null
                  : '${d.statement.status} · ${d.statement.count} شحنة${d.statement.at == null ? '' : ' · ${when(d.statement.at)}'}',
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                      children: [
                        _totals(d),
                        const SizedBox(height: 10),
                        if (d.statement.paid && !d.statement.confirmed) ...[
                          SheetButton(
                            brand: brand,
                            label: d.scanRequired ? 'امسح الوصل لتأكيد الاستلام' : 'استلمتُ هذه الدفعة',
                            icon: d.scanRequired ? Icons.qr_code_scanner_rounded : Icons.check_circle_rounded,
                            busy: confirming,
                            onTap: _confirm,
                          ),
                          const SizedBox(height: 10),
                        ],
                        const SectionTitle('شحنات الكشف'),
                        WhiteCard(
                          child: Material(
                            type: MaterialType.transparency,
                            child: Column(
                              children: [
                                for (final (i, l) in d.lines.indexed) ...[
                                  if (i > 0) const Divider(height: 1, color: Palette.line),
                                  _line(l),
                                ],
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _totals(StatementDetail d) {
    Widget row(String label, int value, {Color? color, bool strong = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: font(13, strong ? w8 : w6, Palette.slate))),
          Text('${money(value)} د.ع', style: font(strong ? 16 : 13.5, w8, color ?? Palette.ink)),
        ],
      ),
    );
    return WhiteCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        children: [
          row('المحصَّل', d.total('collected')),
          row('أجور التوصيل', -d.total('delivery'), color: Palette.slate),
          if (d.total('returns') > 0) row('أجور الراجع', -d.total('returns'), color: Palette.returnRed),
          if (d.total('advance') > 0) row('مقتطعٌ للسلفة', -d.total('advance'), color: Palette.returnRed),
          const Divider(color: Palette.line),
          row('لك', d.total('net'), color: brand.main, strong: true),
          if (d.statement.reference != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('مرجع الدفع: ${d.statement.reference}', style: font(11.5, w6, Palette.slate)),
            ),
        ],
      ),
    );
  }

  Widget _line(StatementLine l) => InkWell(
    onTap: l.shipmentId == 0 ? null : () => openShipment(context, brand, l.shipmentId),
    child: Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('#${l.number}', textDirection: TextDirection.ltr, style: font(13, w8, brand.main)),
                Text(l.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(12, w6, Palette.ink)),
                Text(
                  l.returned
                      ? 'راجع · أجرة الراجع ${money(l.returnFee)}'
                      : 'المحصَّل ${money(l.collected)} · التوصيل ${money(l.delivery)}',
                  style: font(11, w5, Palette.slate),
                ),
              ],
            ),
          ),
          Text('${money(l.net)} د.ع', style: font(13.5, w8, l.net < 0 ? Palette.returnRed : Palette.ink)),
          const SizedBox(width: 6),
          const Chev(size: 8),
        ],
      ),
    ),
  );
}
