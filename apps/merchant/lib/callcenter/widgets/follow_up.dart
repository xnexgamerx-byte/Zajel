import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import 'bits.dart';
import 'page.dart';

/// متابعة الشحنة بين الزميلات (docs/plan/83): موعد التأجيل بيومه وساعته، و«ملاحظة للموظّفين» تُرى في
/// القوائم كـ«تعالجها أنت» — فمن تفتح المؤجّل بعدك تعرف أنّه اتُّصل به، ومن اتّصل، وماذا قيل.

/// «مؤجل إلى غداً الساعة 9:00 م» — شارةٌ صفراء في السطر وفي الشحنة
class PostponedChip extends StatelessWidget {
  const PostponedChip(this.until, {super.key, this.size = 11});

  final DateTime until;
  final double size;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final late = until.isBefore(DateTime(now.year, now.month, now.day));
    final color = late ? Palette.returnRed : Queue.postponedInk;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: late ? Palette.returnRed.withValues(alpha: .1) : Queue.postponedSoft,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.event_rounded, size: size + 2, color: color),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              'مؤجل إلى ${postponedText(until)}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: font(size, w8, color, height: 1.3),
            ),
          ),
        ],
      ),
    );
  }
}

/// ملاحظة الزميلة في سطر القائمة: «نور: اتّصلتُ ولم تردّ · منذ 1 س»
class StaffNoteChip extends StatelessWidget {
  const StaffNoteChip(this.note, {super.key});

  final StaffNoteInfo? note;

  @override
  Widget build(BuildContext context) {
    final n = note;
    if (n == null) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(color: Queue.noteSoft, borderRadius: BorderRadius.circular(12)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.push_pin_rounded, size: 13, color: Queue.note),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              [
                (n.by ?? '').isEmpty ? n.text : '${n.by}: ${n.text}',
                if (n.at != null) ago(DateTime.now().difference(n.at!).inMinutes),
              ].join(' · '),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: font(11, w7, Queue.note, height: 1.35),
            ),
          ),
        ],
      ),
    );
  }
}

/// «ملاحظة للموظّفين» في الشحنة وفي المعالجة: ما كُتب ومن كتبه، و«اكتبي» أو «عدّلي»
class StaffNotePanel extends StatelessWidget {
  const StaffNotePanel({super.key, required this.brand, required this.shipmentId, this.note, required this.onSaved});

  final Brand brand;
  final int shipmentId;
  final StaffNoteInfo? note;

  /// بعد الحفظ أو المسح: تُعاد الشحنة
  final Future<void> Function() onSaved;

  Future<void> _edit(BuildContext context) async {
    final message = await showSheet<String>(context, StaffNoteSheet(brand: brand, shipmentId: shipmentId, note: note));
    if (message == null || !context.mounted) return;
    toast(context, message);
    await onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final n = note;
    return Tap(
      radius: 18,
      onTap: () => _edit(context),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
        decoration: BoxDecoration(
          color: Queue.noteSoft,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: Queue.note.withValues(alpha: .22)),
        ),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: Queue.note, borderRadius: BorderRadius.circular(12)),
              child: const Icon(Icons.push_pin_rounded, size: 18, color: Colors.white),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('ملاحظة للموظّفين', style: font(11, w7, Queue.note)),
                  Text(
                    n?.text ?? 'لا ملاحظة بعد — اكتبي ما فعلتِ لتعرفه زميلتك',
                    style: font(
                      n == null ? 12 : 13.5,
                      n == null ? w6 : w8,
                      n == null ? Palette.slate : Palette.ink,
                      height: 1.45,
                    ),
                  ),
                  if (n != null)
                    Text(
                      [n.by ?? '', if (n.at != null) when(n.at)].where((t) => t.isNotEmpty).join(' · '),
                      style: font(11, w6, Palette.muted, height: 1.4),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
              child: Text(n == null ? 'اكتبي' : 'عدّلي', style: font(12, w8, Queue.note)),
            ),
          ],
        ),
      ),
    );
  }
}

/// نافذة الملاحظة: جملٌ جاهزة تُكتب بلمسة، وحقلٌ يُعدَّل، ومسحٌ لما انتهى
class StaffNoteSheet extends StatefulWidget {
  const StaffNoteSheet({super.key, required this.brand, required this.shipmentId, this.note});

  final Brand brand;
  final int shipmentId;
  final StaffNoteInfo? note;

  /// ما يُكتب أكثر شيء بعد الاتصال
  static const suggestions = [
    'اتّصلتُ ولم يردّ',
    'الهاتف مغلق',
    'أكّد الزبون الموعد',
    'طلب الاتصال لاحقاً',
    'أُرسلت له رسالة واتساب',
  ];

  @override
  State<StaffNoteSheet> createState() => _StaffNoteSheetState();
}

class _StaffNoteSheetState extends State<StaffNoteSheet> {
  late final text = TextEditingController(text: widget.note?.text ?? '');
  String? error;
  bool busy = false;

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Future<void> _save(String value) async {
    if (busy) return;
    setState(() => (busy = true, error = null));
    try {
      final (message, _) = await Api.instance.staffNote(widget.shipmentId, value);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'ملاحظة للموظّفين',
    text: 'تراها زميلاتك في القوائم وفي الشحنة، باسمك ووقتها — لا التاجر ولا المندوب.',
    children: [
      Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final s in StaffNoteSheet.suggestions)
            ActionChip(
              onPressed: () {
                text.text = s;
                text.selection = TextSelection.collapsed(offset: s.length);
              },
              backgroundColor: Queue.noteSoft,
              side: BorderSide.none,
              visualDensity: VisualDensity.compact,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
              label: Text(s, style: font(12, w7, Queue.note)),
            ),
        ],
      ),
      const SizedBox(height: 10),
      InputBox(
        controller: text,
        hint: 'مثلاً: اتّصلتُ مرّتين، يردّ بعد الخامسة',
        lines: 2,
        maxLength: 255,
        autofocus: true,
      ),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 12),
      SheetButton(
        brand: widget.brand,
        label: 'احفظي الملاحظة',
        icon: Icons.push_pin_rounded,
        busy: busy,
        onTap: () {
          if (text.text.trim().isEmpty) {
            setState(() => error = 'اكتبي الملاحظة، أو اختاري جملةً جاهزة.');
            return;
          }
          _save(text.text);
        },
      ),
      if (widget.note != null)
        TextButton(
          onPressed: busy ? null : () => _save(''),
          child: Text('امسحي الملاحظة', style: font(12.5, w7, Palette.returnRed)),
        ),
    ],
  );
}

/// «مؤجل إلى»: اليوم بلمسة — اليوم، غداً، بعد غد، أو يومٌ تختارينه — وساعته إن قيلت، بلا ساعةٍ يعني اليوم كلّه.
/// ويُكتب الموعد كما سيظهر في القوائم
class PostponePicker extends StatefulWidget {
  const PostponePicker({super.key, required this.brand, required this.onChanged});

  final Brand brand;
  final ValueChanged<DateTime?> onChanged;

  /// الساعات الشائعة: الصبح، الظهر، العصر، المغرب، الليل
  static const hours = [('الصبح', 10), ('الظهر', 13), ('العصر', 16), ('المغرب', 18), ('الليل', 21)];

  @override
  State<PostponePicker> createState() => _PostponePickerState();
}

class _PostponePickerState extends State<PostponePicker> {
  DateTime? day;
  TimeOfDay? time;

  /// يومٌ اختارته من التقويم، لا من الثلاثة
  bool other = false;

  DateTime get _today {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  DateTime? get value {
    final d = day;
    if (d == null) return null;
    final t = time;
    return t == null ? d : DateTime(d.year, d.month, d.day, t.hour, t.minute);
  }

  void _set({DateTime? d, TimeOfDay? t, bool clearTime = false, bool? isOther}) {
    setState(() {
      if (d != null) day = d;
      if (isOther != null) other = isOther;
      if (clearTime) time = null;
      if (t != null) time = t;
    });
    widget.onChanged(value);
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: day ?? _today.add(const Duration(days: 3)),
      firstDate: _today,
      lastDate: _today.add(const Duration(days: 59)),
      helpText: 'إلى أيّ يومٍ اتّفقتِ مع الزبون؟',
    );
    if (picked != null) _set(d: picked, isOther: true);
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: time ?? const TimeOfDay(hour: 17, minute: 0),
      helpText: 'في أيّ ساعة؟',
    );
    if (picked != null) _set(t: picked);
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) => ChoiceChip(
    selected: selected,
    showCheckmark: false,
    onSelected: (_) => onTap(),
    selectedColor: Queue.postponed,
    backgroundColor: Colors.white,
    side: BorderSide(color: selected ? Queue.postponed : Palette.line),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    label: Text(label, style: font(12.5, w8, selected ? Colors.white : Palette.ink)),
  );

  @override
  Widget build(BuildContext context) {
    final picked = value;
    final days = [('اليوم', 0), ('غداً', 1), ('بعد غد', 2)];
    final commonHour = PostponePicker.hours.any((h) => time?.hour == h.$2 && time?.minute == 0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const FieldLabel('مؤجل إلى'),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final (label, plus) in days)
              _chip(
                label,
                !other && day == _today.add(Duration(days: plus)),
                () => _set(d: _today.add(Duration(days: plus)), isOther: false),
              ),
            _chip(other && day != null ? '${day!.day}/${day!.month}' : 'يوم آخر…', other, _pickDay),
          ],
        ),
        const SizedBox(height: 10),
        const FieldLabel('الساعة (اختيارية)'),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            _chip('أيّ وقت', time == null, () => _set(clearTime: true)),
            for (final (label, hour) in PostponePicker.hours)
              _chip(
                '$label ${hour > 12 ? hour - 12 : hour}',
                time?.hour == hour && time?.minute == 0,
                () => _set(t: TimeOfDay(hour: hour, minute: 0)),
              ),
            _chip(
              time != null && !commonHour
                  ? '${time!.hourOfPeriod == 0 ? 12 : time!.hourOfPeriod}:${time!.minute.toString().padLeft(2, '0')}'
                  : 'ساعة أخرى…',
              time != null && !commonHour,
              _pickTime,
            ),
          ],
        ),
        if (picked != null) ...[
          const SizedBox(height: 10),
          Align(alignment: AlignmentDirectional.centerStart, child: PostponedChip(picked, size: 12)),
        ],
      ],
    );
  }
}

/// «تأجيل» في المعالجة: اليوم وساعته في نافذة — يعود الموعد، أو null إن تراجعت
Future<DateTime?> pickPostpone(BuildContext context, Brand brand) =>
    showSheet<DateTime>(context, _PostponeSheet(brand: brand));

class _PostponeSheet extends StatefulWidget {
  const _PostponeSheet({required this.brand});

  final Brand brand;

  @override
  State<_PostponeSheet> createState() => _PostponeSheetState();
}

class _PostponeSheetState extends State<_PostponeSheet> {
  DateTime? until;

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'تأجيل',
    text: 'إلى أيّ موعدٍ اتّفقتِ مع الزبون؟ يظهر «مؤجل إلى …» لزميلاتك وللمندوب.',
    children: [
      PostponePicker(brand: widget.brand, onChanged: (v) => setState(() => until = v)),
      const SizedBox(height: 14),
      SheetButton(
        brand: widget.brand,
        label: 'أجّليها',
        icon: Icons.event_rounded,
        onTap: until == null ? null : () => Navigator.of(context).pop(until),
      ),
    ],
  );
}
