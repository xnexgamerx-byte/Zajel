import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// «المالية» (docs/plan/54): حساب التاجر بأرقام البوابة نفسها — الإجمالي والمتاح للسحب وما وراءهما —
/// وطلب المحاسبة بطريقة دفعه، وكشوفه ودفعاتها و«استلمتُها»، وآخر حركات حسابه.
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
        padding: EdgeInsets.fromLTRB(14, top > 0 ? top + 6 : 34, 14, 130),
        children: [
          Text('المالية', style: font(20, w8, Palette.ink, height: 1.2)),
          const SizedBox(height: 10),
          _balance(d),
          const SizedBox(height: 10),
          if (d.request != null) ...[_open(d.request!), const SizedBox(height: 10)],
          if (d.request == null && d.available > 0) ...[_requestButton(d), const SizedBox(height: 14)],
          _title('كشوفك ودفعاتها'),
          if (d.statements.isEmpty) _none('لم تُدفع لك كشوفٌ بعد.'),
          for (final s in d.statements) ...[_statement(s), const SizedBox(height: 8)],
          const SizedBox(height: 8),
          _title('آخر حركات حسابك'),
          if (d.movements.isEmpty) _none('لا حركات بعد.'),
          if (d.movements.isNotEmpty)
            WhiteCard(
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

  Widget _balance(Finance d) {
    Widget line(IconData icon, Color color, String label, int amount, [String? sub]) => Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text.rich(
                  TextSpan(
                    text: '${money(amount)} د.ع ',
                    style: font(13, w8, Palette.ink),
                    children: [TextSpan(text: label, style: font(12.5, w6, Palette.slate))],
                  ),
                ),
                if (sub != null) Text(sub, style: font(11, w5, Palette.slate, height: 1.5)),
              ],
            ),
          ),
        ],
      ),
    );

    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: _tile(
                  d.owed ? 'إجمالي المستحقات عن الواصل' : 'عليك للشركة',
                  d.total,
                  d.owed ? Palette.ink : Palette.returnRed,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(child: _tile('المتاح للسحب', d.available, const Color(0xFF0F7B4A))),
            ],
          ),
          if (d.pending > 0)
            line(Icons.schedule_rounded, const Color(0xFFB54708), 'قيد المطابقة', d.pending, d.pendingReason),
          if (d.awaitingPayment > 0)
            line(
              Icons.check_circle_outline_rounded,
              const Color(0xFF0F7B4A),
              'بانتظار الدفع',
              d.awaitingPayment,
              'كشفٌ أُقفل ولم يُدفع بعد — من المتاح للسحب.',
            ),
          if (d.advances > 0)
            line(Icons.payments_outlined, Palette.returnRed, 'سلفة عليك', d.advances, 'تُقتطع من الكشف القادم.'),
        ],
      ),
    );
  }

  Widget _tile(String label, int amount, Color color) => Container(
    padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
    decoration: BoxDecoration(color: const Color(0xFFF4F7FB), borderRadius: BorderRadius.circular(14)),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: font(12, w7, Palette.slate)),
        const SizedBox(height: 2),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: Text(money(amount), style: font(22, w8, color, height: 1.2)),
        ),
        Text('د.ع', style: font(10.5, w6, Palette.muted)),
      ],
    ),
  );

  Widget _requestButton(Finance d) => Tap(
    radius: 14,
    onTap: () => _request(d),
    child: Container(
      height: 50,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: LinearGradient(colors: [brand.coral, brand.main]),
        boxShadow: [BoxShadow(color: brand.main.withValues(alpha: .25), blurRadius: 10, offset: const Offset(0, 4))],
      ),
      child: Text('اطلب محاسبة — ${money(d.available)} د.ع', style: font(14.5, w8, Colors.white)),
    ),
  );

  Widget _open(PaymentRequest r) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: brand.softer,
      borderRadius: BorderRadius.circular(14),
      border: Border.all(color: brand.border),
    ),
    child: Row(
      children: [
        SolidIcon(brand: brand, icon: Icons.hourglass_top_rounded, size: 32, iconSize: 18, radius: 9),
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
    child: Text(text, style: font(14, w8, Palette.ink)),
  );

  Widget _none(String text) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Text(text, style: font(12.5, w5, Palette.slate)),
  );

  Widget _statement(Statement s) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(s.code, textDirection: TextDirection.ltr, style: font(13, w8, Palette.ink)),
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: s.paid ? const Color(0xFFE9F7EF) : const Color(0xFFFFF4E5),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(
                      s.status,
                      style: font(10.5, w8, s.paid ? const Color(0xFF0F7B4A) : const Color(0xFFB54708)),
                    ),
                  ),
                ],
              ),
              Text(
                '${s.count} شحنة${s.at == null ? '' : ' · ${when(s.at)}'}${s.advance > 0 ? ' · خُصم ${money(s.advance)} للسلفة' : ''}',
                style: font(11.5, w5, Palette.slate),
              ),
            ],
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text('${money(s.net)} د.ع', style: font(13.5, w8, brand.main)),
            if (s.paid && !s.confirmed)
              TextButton(
                onPressed: () => _confirm(s),
                style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 30)),
                child: Text('استلمتُها', style: font(12, w8, brand.main)),
              )
            else if (s.confirmed)
              Text('أكّدتَ استلامها', style: font(10.5, w6, Palette.slate)),
          ],
        ),
      ],
    ),
  );

  Widget _movement(Movement m) {
    final plus = m.amount >= 0;
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
            '${plus ? '+' : '−'}${money(m.amount.abs())}',
            textDirection: TextDirection.ltr,
            style: font(13, w8, plus ? const Color(0xFF0F7B4A) : Palette.returnRed),
          ),
        ],
      ),
    );
  }
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
      color: const Color(0xFFF6F8FB),
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
