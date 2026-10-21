import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'receive_screen.dart';
import 'shipment_screen.dart';

/// «المالية» (docs/plan/54) بتصميم ز (docs/plan/70): المتاح للسحب في بطاقةٍ حمراء وطلب المحاسبة منها،
/// و«قيد المطابقة» و«استلام الحساب» بمسح الوصل (docs/plan/69)، و«مؤشراتك هذا الشهر»، وكشوفه في
/// مربّعاتٍ حمراء شفّافة بحالة كلٍّ منها، وآخر حركات حسابه.
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

  void _toast(String text) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Text(text, style: font(13, w6, Colors.white)),
        behavior: SnackBarBehavior.floating,
      ),
    );

  Future<void> _request(Finance d) async {
    final made = await showModalBottomSheet<PaymentRequest>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _RequestSheet(brand: brand, finance: d),
    );
    if (made == null || !mounted) return;
    _toast('تمّ الطلب ${made.number} — ${money(made.amount)} د.ع');
    _load();
  }

  Future<void> _confirm(Statement s) async {
    try {
      _toast(await Api.instance.confirmStatement(s.id));
      _load();
    } on ApiError catch (e) {
      _toast(e.message);
    }
  }

  /// «استلام الحساب»: يمسح الوصل الذي وصل مع المبلغ (docs/plan/69)
  Future<void> _scan() async {
    final done = await openReceive(context, brand);
    if (done != null) _load();
  }

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
                    Text(error!, textAlign: TextAlign.center, style: font(14, w6, Palette.ink)),
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
        padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 6 : 34, 16, 130),
        children: [
          Row(
            children: [
              const TabBack(),
              Expanded(child: Text('المالية', style: display(28, w7, Palette.ink, height: 1.3))),
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
                      size: 20,
                      color: Palette.ink,
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _hero(d),
          const SizedBox(height: 10),
          if (d.request != null) ...[_open(d.request!), const SizedBox(height: 10)],
          _tiles(d),
          const SizedBox(height: 10),
          if (_extras(d) case final extras?) ...[extras, const SizedBox(height: 10)],
          if (d.month != null) ...[_indicators(d.month!), const SizedBox(height: 14)],
          _title('كشوفك ودفعاتها'),
          if (d.statements.isEmpty) _none('لم تُدفع لك كشوفٌ بعد.'),
          for (final s in d.statements) ...[_statement(d, s), const SizedBox(height: 8)],
          const SizedBox(height: 8),
          _title('آخر حركات حسابك'),
          if (d.movements.isEmpty) _none('لا حركات بعد.'),
          if (d.movements.isNotEmpty)
            WhiteCard(
              radius: 20,
              child: Column(
                children: [
                  for (final (i, m) in d.movements.indexed) ...[
                    if (i > 0) const Divider(height: 1, color: Palette.line, indent: 12, endIndent: 12),
                    _movement(m),
                  ],
                ],
              ),
            ),
        ],
      ),
    );
  }

  /// البطاقة الحمراء: المتاح للسحب، وطلب المحاسبة منها
  Widget _hero(Finance d) {
    final method = d.methods.where((m) => m.value == d.method).firstOrNull;
    final canRequest = d.request == null && d.available > 0;
    return Container(
      decoration: BoxDecoration(
        gradient: brand.hero,
        borderRadius: BorderRadius.circular(26),
        boxShadow: brand.glow(.6),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(26),
        child: Stack(
          children: [
            PositionedDirectional(
              end: -40,
              top: -60,
              child: Container(
                width: 170,
                height: 170,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white.withValues(alpha: .08), width: 20),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 14, 14),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('المتاح للسحب', style: font(13, w7, Colors.white.withValues(alpha: .9))),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: AlignmentDirectional.centerStart,
                          child: Text.rich(
                            TextSpan(
                              text: _amount(d.available),
                              style: display(34, w7, Colors.white, height: 1.25),
                              children: [TextSpan(text: '  د.ع', style: display(14, w6, Colors.white))],
                            ),
                          ),
                        ),
                        if (method != null)
                          Text(
                            'إلى ${method.label}${d.account == null ? '' : ' \u2066${d.account}\u2069'}',
                            style: font(12, w6, Colors.white.withValues(alpha: .88)),
                          ),
                      ],
                    ),
                  ),
                  if (canRequest)
                    Tap(
                      shadow: const [
                        BoxShadow(color: Color(0x99280004), blurRadius: 18, spreadRadius: -10, offset: Offset(0, 10)),
                      ],
                      radius: 15,
                      onTap: () => _request(d),
                      child: Ink(
                        height: 46,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(15)),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text('اطلب محاسبة', style: font(13, w8, brand.deep)),
                            const SizedBox(width: 6),
                            Icon(Icons.arrow_back_rounded, size: 17, color: brand.deep),
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

  /// «قيد المطابقة»، و«استلام الحساب» بمسح الوصل حين تطلبه الشركة
  Widget _tiles(Finance d) {
    final waiting = d.statements.where((s) => s.paid && !s.confirmed).length;
    final pending = WhiteCard(
      radius: 22,
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(9)),
                child: Icon(Icons.hourglass_top_rounded, size: 15, color: brand.deep),
              ),
              const SizedBox(width: 6),
              Text('قيد المطابقة', style: font(12, w7, Palette.muted)),
            ],
          ),
          const SizedBox(height: 6),
          Text(_amount(d.pending), style: display(22, w7, Palette.ink, height: 1.2)),
          Text(
            d.pending > 0 ? 'نقده مع المندوب حتى يُحاسَب' : 'لا شيء قيد المطابقة',
            maxLines: 2,
            style: font(11, w5, Palette.muted, height: 1.4),
          ),
        ],
      ),
    );
    if (!d.scanRequired) return pending;

    final receive = Tap(
      shadow: [
        BoxShadow(
          color: brand.main.withValues(alpha: .25),
          blurRadius: 24,
          spreadRadius: -12,
          offset: const Offset(0, 12),
        ),
      ],
      radius: 22,
      onTap: _scan,
      child: Ink(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color.lerp(Colors.white, brand.main, .05)!, Color.lerp(Colors.white, brand.main, .15)!],
          ),
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: brand.main.withValues(alpha: .25)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    gradient: brand.hero,
                    borderRadius: BorderRadius.circular(14),
                    boxShadow: brand.glow(.5),
                  ),
                  child: const Icon(Icons.qr_code_scanner_rounded, size: 22, color: Colors.white),
                ),
                const Spacer(),
                if (waiting > 0)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                    decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(20)),
                    child: Text('$waiting بانتظارك', style: font(11, w8, Colors.white)),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text('استلام الحساب', style: font(15, w8, Palette.ink, height: 1.3)),
            Text('امسح وصل المبلغ الذي وصلك', maxLines: 2, style: font(11, w5, Palette.muted, height: 1.4)),
          ],
        ),
      ),
    );
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: pending),
          const SizedBox(width: 10),
          Expanded(child: receive),
        ],
      ),
    );
  }

  /// ما وراء المتاح: الإجمالي، وكشفٌ أُقفل ينتظر الدفع، وسلفةٌ تُقتطع
  Widget? _extras(Finance d) {
    Widget row(IconData icon, Color color, String label, int amount, [String? sub]) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: font(12.5, w6, Palette.slate)),
                if (sub != null) Text(sub, style: font(11, w5, Palette.muted, height: 1.4)),
              ],
            ),
          ),
          Text('${_amount(amount)} د.ع', style: display(14, w7, color == Palette.returnRed ? color : Palette.ink)),
        ],
      ),
    );
    return WhiteCard(
      radius: 20,
      padding: const EdgeInsets.fromLTRB(14, 8, 14, 8),
      child: Column(
        children: [
          row(
            Icons.account_balance_wallet_outlined,
            d.owed ? Palette.ink : Palette.returnRed,
            d.owed ? 'إجمالي المستحقات عن الواصل' : 'عليك للشركة',
            d.total,
          ),
          if (d.awaitingPayment > 0)
            row(
              Icons.check_circle_outline_rounded,
              Palette.ok,
              'بانتظار الدفع',
              d.awaitingPayment,
              'كشفٌ أُقفل ولم يُدفع بعد',
            ),
          if (d.advances > 0)
            row(Icons.payments_outlined, Palette.returnRed, 'سلفة عليك', d.advances, 'تُقتطع من الكشف القادم'),
        ],
      ),
    );
  }

  /// «مؤشراتك هذا الشهر» (docs/plan/70): نسبة التسليم حلقةً، والمحصَّل أسبوعاً أسبوعاً
  Widget _indicators(MonthStats m) {
    Widget chip(String label, String value) => Expanded(
      child: Container(
        padding: const EdgeInsets.fromLTRB(10, 7, 10, 7),
        decoration: BoxDecoration(
          color: Palette.bg,
          border: Border.all(color: Palette.line),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, maxLines: 1, style: font(11, w6, Palette.muted)),
            Text.rich(
              TextSpan(
                text: value.endsWith('%') ? value.substring(0, value.length - 1) : value,
                style: display(15, w7, Palette.ink, height: 1.3),
                children: [if (value.endsWith('%')) TextSpan(text: '%', style: font(12, w8, Palette.ink))],
              ),
              maxLines: 1,
            ),
          ],
        ),
      ),
    );
    final growth = m.growth;
    return WhiteCard(
      radius: 24,
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(child: Text('مؤشراتك هذا الشهر', style: display(16, w7, Palette.ink))),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(20)),
                child: Text(m.label, style: font(12, w8, brand.deep)),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Semantics(
                label: m.rate == null
                    ? 'لا شحنات منتهية هذا الشهر'
                    : 'نسبة التسليم ${m.rate}٪: ${m.delivered} سُلّمت و${m.returned} رجعت',
                child: SizedBox(
                  width: 100,
                  height: 100,
                  child: CustomPaint(
                    painter: _Ring((m.rate ?? 0) / 100, brand),
                    child: Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text.rich(
                            TextSpan(
                              text: m.rate == null ? '—' : '${m.rate}',
                              style: display(23, w7, Palette.ink, height: 1.1),
                              // «%» بخطّ القاهرة: تشانغا يرسمها دائرةً وشرطة
                              children: [if (m.rate != null) TextSpan(text: '%', style: font(15, w8, Palette.ink))],
                            ),
                          ),
                          Text('نسبة التسليم', style: font(10.5, w6, Palette.muted, height: 1.2)),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text('مبيعاتك المحصَّلة', style: font(12, w6, Palette.muted))),
                        if (growth != null)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                            decoration: BoxDecoration(
                              color: growth >= 0 ? Palette.okSoft : const Color(0xFFFDECEA),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Text(
                              '${growth >= 0 ? '▲' : '▼'} ${growth.abs()}%',
                              style: font(11, w8, growth >= 0 ? Palette.ok : Palette.returnRed),
                            ),
                          ),
                      ],
                    ),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: AlignmentDirectional.centerStart,
                      child: Text.rich(
                        TextSpan(
                          text: _amount(m.collected),
                          style: display(21, w7, Palette.ink, height: 1.3),
                          children: [TextSpan(text: '  د.ع', style: display(12, w6, Palette.ink))],
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Semantics(
                      label: 'المحصَّل أسبوعاً أسبوعاً: ${m.weeks.map(money).join('، ')}',
                      child: SizedBox(
                        height: 46,
                        child: CustomPaint(painter: _Weeks(m.weeks, brand), size: Size.infinite),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              chip('متوسط الطلب', _amount(m.average)),
              const SizedBox(width: 8),
              chip('نسبة الراجع', m.returnRate == null ? '—' : '${m.returnRate}%'),
              const SizedBox(width: 8),
              chip('شحنات الشهر', money(m.shipments)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _open(PaymentRequest r) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: brand.softer,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: brand.border),
    ),
    child: Row(
      children: [
        SolidIcon(brand: brand, icon: Icons.hourglass_top_rounded, size: 34, iconSize: 18, radius: 11),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('طلبك ${r.number} بانتظار المعالجة', style: font(13, w8, Palette.ink)),
              Text(
                '${money(r.amount)} د.ع · ${r.method}${r.at == null ? '' : ' · ${when(r.at)}'}',
                style: font(11.5, w5, Palette.slate),
              ),
            ],
          ),
        ),
      ],
    ),
  );

  Widget _title(String text) => Padding(
    padding: const EdgeInsets.fromLTRB(2, 6, 2, 8),
    child: Text(text, style: display(16, w7, Palette.ink)),
  );

  Widget _none(String text) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Text(text, style: font(12.5, w5, Palette.slate)),
  );

  /// الكشف مربّعٌ أحمر شفّاف بحالته: يُضغط فتُفتح تفاصيله بشحناته (docs/plan/61)
  Widget _statement(Finance d, Statement s) {
    final Widget? status;
    if (s.confirmed) {
      status = const OkPill(text: 'استلمتها');
    } else if (s.paid && d.scanRequired) {
      status = Tap(
        radius: 20,
        onTap: _scan,
        child: ScanPill(brand: brand, text: 'امسح للتأكيد'),
      );
    } else if (s.paid) {
      status = Tap(
        radius: 20,
        onTap: () => _confirm(s),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
          decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(20)),
          child: Text('استلمتُها', style: font(11.5, w8, Colors.white, height: 1.3)),
        ),
      );
    } else {
      status = Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
        decoration: BoxDecoration(color: const Color(0xFFFFF4E5), borderRadius: BorderRadius.circular(20)),
        child: Text(s.status, style: font(11, w8, const Color(0xFFB54708), height: 1.3)),
      );
    }
    return TintedRow(
      brand: brand,
      icon: Icons.receipt_long_rounded,
      title: s.code,
      subtitle:
          '${s.count} شحنة${s.at == null ? '' : ' · ${when(s.at)}'}${s.advance > 0 ? ' · خُصم ${money(s.advance)} للسلفة' : ''}',
      end: Text(_amount(s.net), style: display(16, w7, Palette.ink, height: 1.2)),
      status: status,
      onTap: () => Navigator.of(context)
          .push(
            MaterialPageRoute(
              builder: (_) => StatementScreen(brand: brand, id: s.id),
            ),
          )
          .then((_) => _load()),
    );
  }

  Widget _movement(Movement m) {
    final plus = m.amount >= 0;
    // حركةٌ عن شحنة: تُضغط فتُفتح الشحنة
    return InkWell(
      onTap: m.shipment == null ? null : () => openShipmentNumber(context, brand, m.shipment!),
      child: _movementRow(m, plus),
    );
  }

  Widget _movementRow(Movement m, bool plus) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  m.label,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: font(12.5, w6, Palette.ink, height: 1.4),
                ),
                if (m.at != null) Text(when(m.at), style: font(10.5, w5, Palette.muted)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            '${plus ? '+' : '−'}${_amount(m.amount.abs())}',
            textDirection: TextDirection.ltr,
            style: display(14, w7, plus ? Palette.ok : Palette.returnRed),
          ),
        ],
      ),
    );
  }
}

/// حلقة نسبة التسليم: مسارٌ فاتح، والنسبة بتدرّج لون الشركة من أعلاها
class _Ring extends CustomPainter {
  _Ring(this.ratio, this.brand);

  final double ratio;
  final Brand brand;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 11.0;
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: size.shortestSide / 2 - stroke / 2);
    canvas.drawArc(
      rect,
      0,
      6.2832,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..color = const Color(0xFFF3E5E3),
    );
    if (ratio <= 0) return;
    canvas.drawArc(
      rect,
      -1.5708,
      6.2832 * ratio.clamp(0, 1),
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.round
        ..shader = SweepGradient(
          startAngle: -1.5708,
          endAngle: 4.7124,
          colors: [brand.coral, brand.main, Color.lerp(brand.main, Colors.black, .25)!],
          transform: const GradientRotation(-1.5708),
        ).createShader(rect),
    );
  }

  @override
  bool shouldRepaint(covariant _Ring old) => old.ratio != ratio || old.brand.main != brand.main;
}

/// أعمدة الأسابيع: الأوّل يميناً، وهذا الأسبوع بلون الشركة والباقي فاتح، وتحت كلٍّ رقمه
class _Weeks extends CustomPainter {
  _Weeks(this.weeks, this.brand);

  final List<int> weeks;
  final Brand brand;

  @override
  void paint(Canvas canvas, Size size) {
    if (weeks.isEmpty) return;
    const label = 14.0, bar = 18.0, gap = 10.0;
    final top = weeks.reduce((a, b) => a > b ? a : b);
    final height = size.height - label;
    for (var i = 0; i < weeks.length; i++) {
      // الأسبوع الأوّل في أقصى اليمين (العربية من اليمين)
      final x = size.width - (i + 1) * bar - i * gap;
      if (x < 0) break;
      final h = top == 0 ? 3.0 : (height * weeks[i] / top).clamp(3.0, height);
      final r = RRect.fromRectAndRadius(Rect.fromLTWH(x, height - h, bar, h), const Radius.circular(6));
      final now = i == weeks.length - 1;
      canvas.drawRRect(
        r,
        Paint()
          ..shader =
              (now
                      ? LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [brand.coral, brand.main],
                        )
                      : const LinearGradient(colors: [Color(0xFFF2D2D1), Color(0xFFF2D2D1)]))
                  .createShader(r.outerRect),
      );
      final text = TextPainter(
        text: TextSpan(text: '${i + 1}', style: font(10.5, w6, Palette.muted, height: 1)),
        textDirection: TextDirection.ltr,
      )..layout();
      text.paint(canvas, Offset(x + bar / 2 - text.width / 2, size.height - text.height));
    }
  }

  @override
  bool shouldRepaint(covariant _Weeks old) => old.weeks != weeks;
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
      padding: EdgeInsets.fromLTRB(16, 10, 16, MediaQuery.viewInsetsOf(context).bottom + 18),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 14),
          Text('اطلب محاسبة', style: font(16, w8, Palette.ink)),
          Text('يُطلب المتاح للسحب: ${money(f.available)} د.ع', style: font(12, w5, Palette.slate)),
          const SizedBox(height: 14),
          Text('طريقة الدفع', style: font(12, w7, Palette.ink)),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final m in f.methods)
                ChoiceChip(
                  label: Text(
                    m.label,
                    style: font(12.5, m.value == method ? w8 : w6, m.value == method ? brand.main : Palette.ink),
                  ),
                  selected: m.value == method,
                  selectedColor: brand.soft,
                  onSelected: (_) => setState(() => method = m.value),
                ),
            ],
          ),
          if (_chosen.details) ...[
            const SizedBox(height: 12),
            _field(
              details,
              saved ? 'المحفوظ ${f.account} — اتركه فارغاً لتستعمله' : (_chosen.hint ?? 'الرقم واسم صاحبه'),
            ),
          ],
          const SizedBox(height: 10),
          _field(note, 'ملاحظة (اختياري)'),
          if (error != null) ...[
            const SizedBox(height: 8),
            Text(error!, style: font(12, w6, Palette.returnRed, height: 1.5)),
          ],
          const SizedBox(height: 14),
          Tap(
            radius: 14,
            onTap: saving ? null : _save,
            child: Container(
              height: 50,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(14)),
              child: saving
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                    )
                  : Text('أرسل الطلب', style: font(15, w8, Colors.white)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _field(TextEditingController controller, String hint) => Container(
    decoration: BoxDecoration(
      color: const Color(0xFFFBF6F5),
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: controller,
      style: font(13.5, w6, Palette.ink, height: 1.4),
      decoration: InputDecoration(
        isDense: true,
        hintText: hint,
        hintStyle: font(12.5, w5, Palette.muted),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.all(12),
      ),
    ),
  );
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
