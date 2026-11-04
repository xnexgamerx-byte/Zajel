import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';

/// «لم تُسلَّم» و«مؤجلة»: السبب إلزامي، وبعض الأسباب تحتاج توضيحاً — ويعود (السبب، الملاحظة، الموعد).
/// وللمؤجّلة موعدها (docs/plan/75): يومٌ وساعةٌ إن شاء، ومنه تذكيره في الرئيسية وتنبيه الهاتف.
Future<(int, String, DateTime?)?> showReasons(BuildContext context, Brand brand, {required bool postpone}) =>
    showSheet<(int, String, DateTime?)>(context, _ReasonsSheet(brand: brand, postpone: postpone));

class _ReasonsSheet extends StatefulWidget {
  const _ReasonsSheet({required this.brand, required this.postpone});

  final Brand brand;
  final bool postpone;

  @override
  State<_ReasonsSheet> createState() => _ReasonsSheetState();
}

class _ReasonsSheetState extends State<_ReasonsSheet> {
  final note = TextEditingController();
  List<Reason>? reasons;
  Reason? picked;
  String? error;

  /// يوم الموعد وساعته — للمؤجّلة
  DateTime? day;
  TimeOfDay? time;

  static const _times = [
    ('الصبح', TimeOfDay(hour: 10, minute: 0)),
    ('الظهر', TimeOfDay(hour: 13, minute: 0)),
    ('العصر', TimeOfDay(hour: 16, minute: 0)),
    ('المساء', TimeOfDay(hour: 19, minute: 0)),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final all = await Api.instance.reasons();
      // للتأجيل أسبابه إن عرّفتها الشركة، وإلّا الأسباب كلّها
      final postponing = all.where((r) => r.forPostponed).toList();
      if (mounted) setState(() => reasons = widget.postpone && postponing.isNotEmpty ? postponing : all);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _save() {
    final r = picked;
    if (r == null) {
      setState(() => error = 'اختر السبب — بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.');
      return;
    }
    if (r.requiresNote && note.text.trim().isEmpty) {
      setState(() => error = 'السبب «${r.name}» يحتاج توضيحاً.');
      return;
    }
    if (widget.postpone && day == null) {
      setState(() => error = 'متى الموعد؟ اختر اليوم — ويذكّرك التطبيق به.');
      return;
    }
    final d = day;
    final at = d == null ? null : DateTime(d.year, d.month, d.day, time?.hour ?? 0, time?.minute ?? 0);
    Navigator.of(context).pop((r.id, note.text.trim(), at));
  }

  DateTime get _today {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: day ?? _today.add(const Duration(days: 1)),
      firstDate: _today,
      lastDate: _today.add(const Duration(days: 30)),
      helpText: 'يوم التسليم',
    );
    if (picked != null) setState(() => (day = picked, error = null));
  }

  /// الموعد: اليوم، غداً، بعد غد، أو يومٌ يختاره — وساعةٌ إن اتّفق عليها
  List<Widget> _when() {
    final days = [('اليوم', 0), ('غداً', 1), ('بعد غد', 2)];
    final custom = day != null && day!.difference(_today).inDays > 2;
    return [
      const FieldLabel('موعد التسليم'),
      Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final (label, n) in days)
            _chip(label, day == _today.add(Duration(days: n)), () {
              setState(() => (day = _today.add(Duration(days: n)), error = null));
            }),
          _chip(custom ? '${day!.day}/${day!.month}' : 'يومٌ آخر', custom, _pickDay, icon: Icons.event_rounded),
        ],
      ),
      const SizedBox(height: 8),
      Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          _chip('بلا ساعة', time == null, () => setState(() => time = null)),
          for (final (label, t) in _times)
            _chip('$label ${t.hour > 12 ? t.hour - 12 : t.hour}:00', time == t, () => setState(() => time = t)),
        ],
      ),
      const SizedBox(height: 4),
    ];
  }

  Widget _chip(String label, bool on, VoidCallback onTap, {IconData? icon}) => Tap(
    radius: 20,
    onTap: onTap,
    child: AnimatedContainer(
      duration: const Duration(milliseconds: 150),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: on ? OutcomeColors.soft(OutcomeColors.postponed) : Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: on ? OutcomeColors.postponed : Palette.line, width: on ? 1.4 : 1),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 15, color: on ? OutcomeColors.ink(OutcomeColors.postponed) : Palette.slate),
            const SizedBox(width: 4),
          ],
          Text(label, style: font(12.5, on ? w8 : w6, on ? OutcomeColors.ink(OutcomeColors.postponed) : Palette.ink)),
        ],
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final list = reasons;
    return SheetFrame(
      title: widget.postpone ? 'مؤجلة — اختر السبب' : 'لم تُسلَّم — اختر السبب',
      text: widget.postpone
          ? 'اكتب ما طلبه الزبون، مثلاً: الغد بعد الظهر.'
          : 'بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.',
      children: [
        if (list == null && error == null)
          Center(child: CircularProgressIndicator(color: widget.brand.main))
        else if (list != null)
          for (final r in list) _option(r),
        if (picked != null && widget.postpone) ..._when(),
        if (picked != null) ...[
          FieldLabel(picked!.requiresNote ? 'التوضيح (مطلوب)' : 'ملاحظة (اختيارية)'),
          InputBox(controller: note, hint: 'ما قاله الزبون أو ما حدث عند الباب', lines: 2, maxLength: 500),
        ],
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: SheetButton(
                brand: widget.brand,
                outlined: true,
                label: 'إلغاء',
                onTap: () => Navigator.of(context).pop(),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: SheetButton(brand: widget.brand, label: 'حفظ', onTap: _save),
            ),
          ],
        ),
      ],
    );
  }

  Widget _option(Reason r) {
    final on = picked?.id == r.id;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Tap(
        radius: 12,
        onTap: () => setState(() => (picked = r, error = null)),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          decoration: BoxDecoration(
            color: on ? widget.brand.softer : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: on ? widget.brand.main : Palette.line, width: on ? 1.4 : 1),
          ),
          child: Row(
            children: [
              Icon(
                on ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                size: 20,
                color: on ? widget.brand.main : Palette.muted,
              ),
              const SizedBox(width: 10),
              Expanded(child: Text(r.name, style: font(14, w7, Palette.ink))),
              if (r.requiresNote) const Pill('يحتاج توضيحاً', color: Palette.warn),
            ],
          ),
        ),
      ),
    );
  }
}

/// «سُلّمت مع تغيير السعر» أو «واصل جزئي» (docs/plan/80): سلّم المندوب، ويكتب ما قبضه — والجزئيّ بعدد
/// القطع الراجعة معه — فيصل الموظّف. حين يوافق تُسجَّل الشحنة وحدها. ويعود نصّ النظام إن أُرسل.
Future<String?> showTicketSheet(BuildContext context, Brand brand, ShipmentDetail shipment, {required String kind}) =>
    showSheet<String>(context, _TicketSheet(brand: brand, shipment: shipment, kind: kind));

class _TicketSheet extends StatefulWidget {
  const _TicketSheet({required this.brand, required this.shipment, required this.kind});

  final Brand brand;
  final ShipmentDetail shipment;

  /// price: سُلّمت كاملةً بسعرٍ آخر · partial: أخذ الزبون بعضها
  final String kind;

  @override
  State<_TicketSheet> createState() => _TicketSheetState();
}

class _TicketSheetState extends State<_TicketSheet> {
  final amount = TextEditingController();
  final reason = TextEditingController();
  int pieces = 1;
  String? error;
  bool busy = false;

  bool get partial => widget.kind == 'partial';

  @override
  void dispose() {
    amount.dispose();
    reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final value = int.tryParse(amount.text.replaceAll(RegExp(r'[^0-9]'), ''));
    if (value == null) {
      setState(() => error = 'اكتب المبلغ الذي قبضته من الزبون.');
      return;
    }
    if (reason.text.trim().length < 3) {
      setState(
        () => error = partial ? 'اكتب ماذا أخذ الزبون وماذا رجع.' : 'اكتب لماذا تغيّر السعر — به يقرّر الموظّف.',
      );
      return;
    }
    setState(() => (busy = true, error = null));
    try {
      final (message, _) = await Api.instance.openTicket(
        widget.shipment.id,
        kind: widget.kind,
        amount: value,
        reason: reason.text.trim(),
        finalize: true,
        returnedPieces: partial ? pieces : null,
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: partial ? 'واصل جزئي' : 'سُلّمت مع تغيير السعر',
    text:
        'المبلغ الأصلي ${money(widget.shipment.amount)} د.ع. يصل الموظّف، وحين يوافق تُسجَّل الشحنة '
        '${partial ? '«واصل جزئي»' : '«سُلّمت»'} وحدها.',
    children: [
      FieldLabel(partial ? 'ما دفعه الزبون عن القطع التي أخذها (د.ع)' : 'المبلغ الذي قبضته (د.ع)'),
      InputBox(
        controller: amount,
        hint: '20 000',
        keyboard: TextInputType.number,
        formatters: const [LatinDigits(), Thousands()],
        ltr: true,
      ),
      if (partial) ...[
        const FieldLabel('كم قطعةً رجعت معك؟'),
        Row(
          children: [
            _step(Icons.remove_rounded, pieces > 1 ? () => setState(() => pieces--) : null),
            Expanded(
              child: Text('$pieces قطعة', textAlign: TextAlign.center, style: font(18, w8, Palette.ink)),
            ),
            _step(Icons.add_rounded, pieces < 999 ? () => setState(() => pieces++) : null),
          ],
        ),
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            'ترجع مع الراجع للمخزن، وتصل تاجرها بعددها وقيمتها في إيصال الراجع.',
            style: font(11.5, w6, Palette.slate, height: 1.5),
          ),
        ),
      ],
      FieldLabel(partial ? 'ماذا أخذ الزبون وماذا رجع' : 'لماذا تغيّر السعر'),
      InputBox(
        controller: reason,
        hint: partial ? 'مثلاً: أخذ قطعة من ثلاث' : 'مثلاً: التاجر اتّفق معه على خصم',
        lines: 2,
        maxLength: 255,
      ),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'أرسل للموظّف', icon: Icons.send_rounded, busy: busy, onTap: _send),
    ],
  );

  Widget _step(IconData icon, VoidCallback? onTap) => Tap(
    radius: 14,
    onTap: onTap,
    child: Container(
      width: 48,
      height: 48,
      decoration: BoxDecoration(
        color: onTap == null ? Palette.line : OutcomeColors.soft(OutcomeColors.delivered),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Icon(icon, color: onTap == null ? Palette.muted : OutcomeColors.delivered),
    ),
  );
}
