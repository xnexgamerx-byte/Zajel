import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// يفتح «رسالتي الثابتة للتاجر»، ثم يعيد ما يعرضها مملوءةً إن حُفظت
Future<void> editMyMessage(BuildContext context, Brand brand, {Future<void> Function()? then}) async {
  final saved = await Navigator.of(context)
      .push<bool>(MaterialPageRoute(builder: (_) => MyMessageScreen(brand: brand)));
  if (saved == true) await then?.call();
}

/// «رسالتي الثابتة للتاجر» من التطبيق (docs/plan/83): نصّها نفسه الذي في الموقع — ما تعدّله هنا تجده هناك.
/// الخانات بين القوسين تُملأ من الشحنة، وتُدرَج بلمسة فوق حقل التاجر في كلّ شحنةٍ ومحادثة.
class MyMessageScreen extends StatefulWidget {
  const MyMessageScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<MyMessageScreen> createState() => _MyMessageScreenState();
}

class _MyMessageScreenState extends State<MyMessageScreen> {
  final text = TextEditingController();
  MyMessage? my;
  String? error;
  bool busy = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final m = await Api.instance.myMessage();
      if (!mounted) return;
      text.text = m.text;
      setState(() => (my = m, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  /// الخانة تُكتب حيث المؤشّر
  void _insert(String field) {
    final sel = text.selection;
    final at = sel.isValid ? sel.start : text.text.length;
    final end = sel.isValid ? sel.end : text.text.length;
    text.text = text.text.replaceRange(at, end, field);
    text.selection = TextSelection.collapsed(offset: at + field.length);
  }

  Future<void> _save({bool reset = false}) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final (message, _) = await Api.instance.saveMyMessage(text.text, reset: reset);
      if (!mounted) return;
      toast(context, message);
      Navigator.of(context).pop(true);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final m = my;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'رسالتي الثابتة للتاجر', subtitle: 'نصّك نفسه في الموقع'),
            Expanded(
              child: m == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                      children: [
                        Text(
                          'تُدرَج بلمسة فوق حقل التاجر في كلّ شحنةٍ ومحادثة، مملوءةً منها، وتُعدَّل قبل الإرسال.',
                          style: font(12.5, w6, Palette.slate, height: 1.6),
                        ),
                        const SizedBox(height: 10),
                        InputBox(controller: text, hint: 'السلام عليكم {التاجر}…', lines: 7, maxLength: 1000),
                        const SizedBox(height: 10),
                        const FieldLabel('الخانات — تُملأ وحدها من الشحنة'),
                        Wrap(
                          spacing: 6,
                          runSpacing: 6,
                          children: [
                            for (final (field, meaning) in m.fields)
                              Tooltip(
                                message: meaning,
                                child: ActionChip(
                                  onPressed: () => _insert(field),
                                  backgroundColor: Queue.merchantsSoft,
                                  side: BorderSide.none,
                                  visualDensity: VisualDensity.compact,
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                                  label: Text(field, style: font(12, w8, Queue.merchants)),
                                ),
                              ),
                          ],
                        ),
                        if (error != null) ErrorNote(error!),
                        if (m.custom)
                          Padding(
                            padding: const EdgeInsets.only(top: 10),
                            child: TextButton.icon(
                              onPressed: busy ? null : () => _save(reset: true),
                              icon: Icon(Icons.restart_alt_rounded, size: 18, color: brand.main),
                              label: Text('أرجعيها للقالب', style: font(13, w7, brand.main)),
                            ),
                          ),
                      ],
                    ),
            ),
            if (m != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
                child: HeroButton(
                  brand: brand,
                  label: busy ? '…' : 'احفظي رسالتي',
                  icon: Icons.check_rounded,
                  onTap: busy ? null : _save,
                ),
              ),
          ],
        ),
      ),
    );
  }
}
