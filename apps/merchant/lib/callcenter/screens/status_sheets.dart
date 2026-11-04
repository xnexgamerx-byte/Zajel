import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/follow_up.dart';
import '../widgets/page.dart';

/// «حدّثي الحالة» من التطبيق (docs/plan/80): الحالات التي تجوز من حالها الآن، ولكلٍّ ما يحتاجه — سبب عدم
/// التسليم بتوضيحه إن احتاجه، أو المبلغ المحصَّل في الواصل الجزئي، أو المندوب. بقواعد الموقع نفسها،
/// ويعود نصّ النظام إن حُدّثت.
class StatusSheet extends StatefulWidget {
  const StatusSheet({super.key, required this.brand, required this.shipment});

  final Brand brand;
  final ShipmentDetail shipment;

  @override
  State<StatusSheet> createState() => _StatusSheetState();
}

class _StatusSheetState extends State<StatusSheet> {
  Transition? to;
  ReasonOption? reason;
  CourierOption? courier;

  /// «مؤجل» إلى موعده (docs/plan/83)
  DateTime? until;
  final note = TextEditingController();
  final amount = TextEditingController();
  String? error;
  bool busy = false;

  ShipmentDetail get d => widget.shipment;
  bool _needs(String what) => to?.needs.contains(what) ?? false;

  @override
  void dispose() {
    note.dispose();
    amount.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final t = to;
    if (t == null) {
      setState(() => error = 'اختاري الحالة الجديدة.');
      return;
    }
    if (_needs('reason') && reason == null) {
      setState(() => error = 'اختاري سبب عدم التسليم.');
      return;
    }
    if (_needs('reason') && reason!.note && note.text.trim().isEmpty) {
      setState(() => error = 'السبب «${reason!.name}» يحتاج توضيحاً — يظهر مع السبب في الشحنة.');
      return;
    }
    final value = int.tryParse(amount.text.replaceAll(RegExp(r'[^0-9]'), ''));
    if (_needs('amount') && value == null) {
      setState(() => error = 'اكتبي المبلغ المحصَّل فعلاً.');
      return;
    }
    if (_needs('courier') && courier == null) {
      setState(() => error = 'اختاري المندوب.');
      return;
    }
    if (t.value == 'postponed' && until == null) {
      setState(() => error = 'مؤجل إلى متى؟ اختاري اليوم الذي اتّفقتِ عليه مع الزبون.');
      return;
    }
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.updateStatus(
        d.found.id,
        t.value,
        reasonId: _needs('reason') ? reason?.id : null,
        note: note.text,
        amount: _needs('amount') ? value : null,
        courierId: courier?.id,
        until: t.value == 'postponed' ? until : null,
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'حدّثي الحالة',
    text: 'الآن: ${d.found.statusLabel}. تُسجَّل باسمك في سجلّ الشحنة كما في الموقع.',
    children: [
      Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final t in d.transitions)
            ChoiceChip(
              selected: to?.value == t.value,
              showCheckmark: false,
              onSelected: (_) => setState(() => (to = t, error = null)),
              selectedColor: widget.brand.main,
              backgroundColor: Colors.white,
              side: BorderSide(color: to?.value == t.value ? widget.brand.main : Palette.line),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              label: Text(t.label, style: font(13, w8, to?.value == t.value ? Colors.white : Palette.ink)),
            ),
        ],
      ),
      if (_needs('reason')) ...[
        const SizedBox(height: 10),
        const FieldLabel('سبب عدم التسليم'),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final r in d.reasons)
              ChoiceChip(
                selected: reason?.id == r.id,
                showCheckmark: false,
                onSelected: (_) => setState(() => (reason = r, error = null)),
                selectedColor: Queue.processing,
                backgroundColor: Colors.white,
                side: BorderSide(color: reason?.id == r.id ? Queue.processing : Palette.line),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                label: Text(
                  r.note ? '${r.name} · يحتاج توضيحاً' : r.name,
                  style: font(12.5, w7, reason?.id == r.id ? Colors.white : Palette.ink),
                ),
              ),
          ],
        ),
      ],
      if (_needs('amount')) ...[
        const SizedBox(height: 10),
        FieldLabel('المبلغ المحصَّل فعلاً (من ${money(d.found.amount)} د.ع)'),
        InputBox(
          controller: amount,
          hint: '20000',
          keyboard: TextInputType.number,
          formatters: [FilteringTextInputFormatter.digitsOnly],
          ltr: true,
        ),
      ],
      if (_needs('courier')) ...[
        const SizedBox(height: 10),
        const FieldLabel('المندوب'),
        DropdownButtonFormField<int>(
          initialValue: courier?.id,
          isExpanded: true,
          items: [
            for (final c in d.couriers)
              DropdownMenuItem(
                value: c.id,
                child: Text(c.name, style: font(13.5, w7, Palette.ink)),
              ),
          ],
          onChanged: (id) => setState(() => courier = d.couriers.firstWhere((c) => c.id == id)),
          decoration: InputDecoration(
            filled: true,
            fillColor: Palette.field,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          ),
        ),
      ],
      // موعد التأجيل: اليوم وساعته إن قيلت — «مؤجل إلى غداً الليل» (docs/plan/83)
      if (to?.value == 'postponed') ...[
        const SizedBox(height: 10),
        PostponePicker(brand: widget.brand, onChanged: (v) => setState(() => (until = v, error = null))),
      ],
      if (to != null) ...[
        const SizedBox(height: 10),
        FieldLabel(_needs('reason') && (reason?.note ?? false) ? 'التوضيح (إلزاميّ)' : 'ملاحظة (اختياريّة)'),
        InputBox(controller: note, hint: 'مثلاً: الزبونة طلبت تغيير العنوان إلى الكرادة', lines: 2, maxLength: 500),
      ],
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'حدّثي', icon: Icons.check_rounded, busy: busy, onTap: _save),
    ],
  );
}

/// «أعيديها للتوصيل» (docs/plan/80): راجعٌ مؤكَّد اقتنع زبونه بالاستلام، أو اتّفق معه التاجر. ما زال بيد
/// مندوبه فيعود معه «إعادة توصيل»، وما استُلم في المخزن يعود إليه. والسبب إلزاميّ ويُكتب باسمها.
class ReopenSheet extends StatefulWidget {
  const ReopenSheet({super.key, required this.brand, required this.shipment});

  final Brand brand;
  final ShipmentDetail shipment;

  @override
  State<ReopenSheet> createState() => _ReopenSheetState();
}

class _ReopenSheetState extends State<ReopenSheet> {
  final note = TextEditingController();
  String? error;
  bool busy = false;

  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (note.text.trim().isEmpty) {
      setState(() => error = 'اكتبي لماذا تعود للتوصيل: من اتّفق مع الزبون وعلى ماذا.');
      return;
    }
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.reopen(widget.shipment.found.id, note.text);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'أعيديها للتوصيل',
    text: (widget.shipment.courier.name ?? '').isEmpty
        ? 'تعود «إعادة توصيل» بعد أن تأكّد راجعها.'
        : 'ما زالت بيد ${widget.shipment.courier.name}: تعود معه «إعادة توصيل» إلى الزبون نفسه.',
    children: [
      const FieldLabel('لماذا تعود؟'),
      InputBox(
        controller: note,
        hint: 'مثلاً: اتّصلتُ بالزبون واقتنع بالاستلام اليوم',
        lines: 2,
        maxLength: 255,
        autofocus: true,
      ),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'أعيديها', icon: Icons.replay_rounded, busy: busy, onTap: _save),
    ],
  );
}
