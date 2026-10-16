import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';

/// «لم تُسلَّم» و«مؤجلة»: السبب إلزامي، وبعض الأسباب تحتاج توضيحاً — ويعود (السبب، الملاحظة)
Future<(int, String)?> showReasons(BuildContext context, Brand brand, {required bool postpone}) =>
    showSheet<(int, String)>(context, _ReasonsSheet(brand: brand, postpone: postpone));

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
    Navigator.of(context).pop((r.id, note.text.trim()));
  }

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

/// «الزبون يريد يدفع غير المبلغ؟» — طلبٌ إلى الكول سنتر، ويعود نصّ النظام إن أُرسل
Future<String?> showTicketSheet(BuildContext context, Brand brand, ShipmentDetail shipment) =>
    showSheet<String>(context, _TicketSheet(brand: brand, shipment: shipment));

class _TicketSheet extends StatefulWidget {
  const _TicketSheet({required this.brand, required this.shipment});

  final Brand brand;
  final ShipmentDetail shipment;

  @override
  State<_TicketSheet> createState() => _TicketSheetState();
}

class _TicketSheetState extends State<_TicketSheet> {
  final amount = TextEditingController();
  final reason = TextEditingController();
  String? kind;
  String? error;
  bool busy = false;

  static const _kinds = [
    ('price', 'السعر تغيّر', 'اتّفق الزبون مع التاجر على مبلغٍ آخر'),
    ('partial', 'أخذ جزءاً وأرجع الباقي', 'يدفع عن القطع التي أخذها، والباقي يرجع معك'),
  ];

  @override
  void dispose() {
    amount.dispose();
    reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final value = int.tryParse(amount.text.replaceAll(' ', ''));
    if (kind == null) {
      setState(() => error = 'اختر: السعر تغيّر، أم أخذ الزبون جزءاً من الطلب؟');
      return;
    }
    if (value == null) {
      setState(() => error = 'اكتب المبلغ الذي سيدفعه الزبون.');
      return;
    }
    if (reason.text.trim().length < 3) {
      setState(() => error = 'اكتب ما قاله الزبون — به تقرّر الكول سنتر.');
      return;
    }
    setState(() => (busy = true, error = null));
    try {
      final (message, _) = await Api.instance.openTicket(
        widget.shipment.id,
        kind: kind!,
        amount: value,
        reason: reason.text.trim(),
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
    title: 'اطلب تغيير المبلغ',
    text: 'المبلغ الآن ${money(widget.shipment.amount)} د.ع. يصل طلبك للكول سنتر، ولا تسلّم بالمبلغ الجديد حتى يُعتمد.',
    children: [
      for (final (value, label, hint) in _kinds)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Tap(
            radius: 12,
            onTap: () => setState(() => (kind = value, error = null)),
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: kind == value ? widget.brand.softer : Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: kind == value ? widget.brand.main : Palette.line,
                  width: kind == value ? 1.4 : 1,
                ),
              ),
              child: Row(
                children: [
                  Icon(
                    kind == value ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                    size: 20,
                    color: kind == value ? widget.brand.main : Palette.muted,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(label, style: font(14, w7, Palette.ink)),
                        Text(hint, style: font(11.5, w5, Palette.slate)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      const FieldLabel('المبلغ الذي سيدفعه الزبون (د.ع)'),
      InputBox(
        controller: amount,
        hint: '20 000',
        keyboard: TextInputType.number,
        formatters: const [LatinDigits(), Thousands()],
        ltr: true,
      ),
      const FieldLabel('ما قاله الزبون'),
      InputBox(controller: reason, hint: 'مثلاً: أخذ قطعتين من ثلاث', lines: 2, maxLength: 255),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'أرسل للكول سنتر', icon: Icons.send_rounded, busy: busy, onTap: _send),
    ],
  );
}
