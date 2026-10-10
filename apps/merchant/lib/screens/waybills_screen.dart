import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'create_screen.dart' show LatinDigits;

Future<void> openWaybills(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => WaybillsScreen(brand: brand)));

/// صفحة الطباعة في متصفّح الهاتف: يطبعها بطابعته أو يحفظها PDF
Future<void> _openPrint(BuildContext context, String url) async {
  if (AppConfig.demo) return;
  if (!await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication) && context.mounted) {
    toast(context, 'تعذّر فتح صفحة الطباعة على هذا الجهاز.', bad: true);
  }
}

/// «وصولات للطباعة» (docs/plan/57): دفاتر وصولاتٍ بأرقامٍ له وحده، يكتب عليها بيده ويلصقها على طروده،
/// ثم يمسحها بالزرّ الأوسط ويكتب الطلب عليها. الطباعة صفحة البوابة نفسها في متصفّح الهاتف.
class WaybillsScreen extends StatefulWidget {
  const WaybillsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<WaybillsScreen> createState() => _WaybillsScreenState();
}

class _WaybillsScreenState extends State<WaybillsScreen> {
  WaybillsPage? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.waybills();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _new(WaybillsPage d) async {
    final made = await showSheet<IssuedBook>(context, _BookSheet(brand: brand, page: d));
    if (made == null || !mounted) return;
    toast(context, made.message);
    _load();
    await _openPrint(context, made.print);
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'وصلات للطباعة', subtitle: 'دفاتر وصولاتٍ برقمك تلصقها على طرودك'),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          SheetButton(
                            brand: brand,
                            label: 'اطبع دفتراً جديداً',
                            icon: Icons.print_rounded,
                            onTap: () => _new(d),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'تُفتح صفحة الطباعة في المتصفّح: اطبعها بطابعة الملصقات أو احفظها PDF. '
                            'وما استُعمل من الدفتر لا يُطبع ثانيةً.',
                            style: font(11.5, w5, Palette.slate, height: 1.6),
                          ),
                          const SectionTitle('دفاترك'),
                          if (d.books.isEmpty)
                            const Empty(
                              icon: Icons.receipt_long_outlined,
                              title: 'لا دفاتر بعد',
                              text: 'اطبع دفتراً، واكتب على كلّ وصلٍ بيدك، ثم امسحه بالزرّ الأوسط واكتب طلبه.',
                            ),
                          for (final b in d.books) ...[_book(b, d.sizes), const SizedBox(height: 10)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _book(WaybillBookRow b, List<Choice> sizes) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 11, 12, 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(b.range, textDirection: TextDirection.ltr, style: font(14, w8, Palette.ink)),
            const Spacer(),
            Text(when(b.at), style: font(11, w5, Palette.slate)),
          ],
        ),
        const SizedBox(height: 4),
        Text('${b.label} · استُعمل ${b.used} من ${b.size}', style: font(12, w6, Palette.slate)),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: b.size == 0 ? 0 : b.used / b.size,
            minHeight: 6,
            backgroundColor: brand.track,
            color: brand.main,
          ),
        ),
        if (b.print.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8, bottom: 4),
            child: Text('استُعمل كلّه', style: font(12, w7, Palette.slate)),
          )
        else
          Wrap(
            spacing: 4,
            children: [
              for (final s in sizes)
                if (b.print[s.key] != null)
                  TextButton.icon(
                    onPressed: () => _openPrint(context, b.print[s.key]!),
                    icon: Icon(Icons.print_outlined, size: 17, color: brand.main),
                    label: Text('اطبع الباقي ${s.label}', style: font(12, w8, brand.main)),
                  ),
            ],
          ),
      ],
    ),
  );
}

class _BookSheet extends StatefulWidget {
  const _BookSheet({required this.brand, required this.page});

  final Brand brand;
  final WaybillsPage page;

  @override
  State<_BookSheet> createState() => _BookSheetState();
}

class _BookSheetState extends State<_BookSheet> {
  final count = TextEditingController(text: '50');
  late String size = widget.page.sizes.firstOrNull?.key ?? '80x120';
  bool busy = false;
  String? error;

  @override
  void dispose() {
    count.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final n = int.tryParse(count.text.trim());
    if (n == null || n < 1 || n > widget.page.max) {
      setState(() => error = 'الدفتر من وصلٍ واحد إلى ${widget.page.max} وصل.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => (busy = true, error = null));
    try {
      final made = await Api.instance.issueWaybills(n, size);
      if (mounted) Navigator.of(context).pop(made);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'اطبع دفتراً جديداً',
      text: 'أرقامٌ جديدة لك وحدك، بمقاس ملصقات طابعتك.',
      children: [
        const FieldLabel('كم وصلاً؟'),
        Row(
          children: [
            SizedBox(
              width: 110,
              child: InputBox(
                controller: count,
                hint: '50',
                keyboard: TextInputType.number,
                formatters: const [LatinDigits()],
                ltr: true,
                maxLength: 3,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Wrap(
                spacing: 6,
                children: [
                  for (final n in [25, 50, 100, 200])
                    if (n <= widget.page.max)
                      ActionChip(
                        label: Text('$n', style: font(12.5, w7, Palette.ink)),
                        onPressed: () => setState(() => count.text = '$n'),
                      ),
                ],
              ),
            ),
          ],
        ),
        const FieldLabel('المقاس'),
        Wrap(
          spacing: 6,
          children: [
            for (final s in widget.page.sizes)
              ChoiceChip(
                label: Text(
                  s.label,
                  style: font(12.5, size == s.key ? w8 : w6, size == s.key ? brand.main : Palette.ink),
                ),
                selected: size == s.key,
                selectedColor: brand.soft,
                onSelected: (_) => setState(() => size = s.key),
              ),
          ],
        ),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 14),
        SheetButton(brand: brand, label: 'جهّز واطبع', icon: Icons.print_rounded, busy: busy, onTap: _send),
      ],
    );
  }
}
