import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'requests_screen.dart' show ReturnReceiptScreen;
import 'scan_screen.dart' show ScanFrame, receiptCode, scanWindow;

/// يفتح «استلام بالباركود» — ويعود بما أُكّد، أو null ولم يُؤكَّد شيء
Future<Received?> openReceive(BuildContext context, Brand brand, {String? code}) => Navigator.of(context)
    .push<Received>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => ReceiveScreen(brand: brand, code: code),
      ),
    );

/// «استلام بالباركود» (docs/plan/69): وصل الحساب يأتي مع المبلغ، وإيصال الراجع مع الرواجع، وعلى كلٍّ
/// باركودٌ يُمسح فيُؤكَّد استلام ما عليه — لا يُختار كشف: الرمز يدلّ على ورقته. وإن تعذّرت الكاميرا
/// يُكتب الرمز المطبوع تحت الباركود.
class ReceiveScreen extends StatefulWidget {
  const ReceiveScreen({super.key, required this.brand, this.code});

  final Brand brand;

  /// رمزٌ مُسح قبل فتحها (من الزرّ الأوسط): يُؤكَّد حالاً
  final String? code;

  @override
  State<ReceiveScreen> createState() => _ReceiveScreenState();
}

class _ReceiveScreenState extends State<ReceiveScreen> with SingleTickerProviderStateMixin {
  final camera = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.qrCode],
  );
  final typed = TextEditingController();

  /// خطّ المسح يصعد وينزل داخل النافذة — ساكنٌ في الاختبار
  late final AnimationController sweep = AnimationController(vsync: this, duration: const Duration(milliseconds: 1800));

  bool busy = false;
  bool torch = false;
  String? error;
  Received? done;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    if (!AppConfig.testing) sweep.repeat(reverse: true);
    if (widget.code != null) WidgetsBinding.instance.addPostFrameCallback((_) => _submit(widget.code!));
  }

  @override
  void dispose() {
    sweep.dispose();
    camera.dispose();
    typed.dispose();
    super.dispose();
  }

  Future<void> _submit(String raw) async {
    final text = raw.trim();
    if (busy || done != null || text.isEmpty) return;

    // إيصال راجعٍ طُبع قبل رموز الاستلام: يُفتح بشحناته كما كان (docs/plan/61)
    final old = receiptCode(text);
    if (old != null) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => ReturnReceiptScreen(brand: brand, id: old),
        ),
      );
      return;
    }

    setState(() => (busy = true, error = null));
    try {
      final received = await Api.instance.receive(text);
      if (mounted) setState(() => done = received);
      await camera.stop();
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final glass = BoxDecoration(
      color: const Color(0x8C140A0B),
      border: Border.all(color: Colors.white.withValues(alpha: .16)),
      borderRadius: BorderRadius.circular(22),
    );
    return Scaffold(
      backgroundColor: const Color(0xFF140C0A),
      body: Stack(
        fit: StackFit.expand,
        children: [
          if (done == null)
            MobileScanner(
              controller: camera,
              onDetect: (capture) {
                final value = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
                if (value != null) _submit(value);
              },
              errorBuilder: (context, e) => _noCamera(),
              placeholderBuilder: (context) => const ColoredBox(color: Color(0xFF140C0A)),
            ),
          ScanFrame(color: Color.lerp(brand.main, Colors.white, .18)!, center: .36),
          if (done == null) _sweepLine(),
          SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
                  child: Row(
                    children: [
                      _round(Icons.close_rounded, 'إغلاق', () => Navigator.of(context).pop(done), glass),
                      Expanded(
                        child: Text(
                          'استلام بالباركود',
                          textAlign: TextAlign.center,
                          style: display(18, w7, Colors.white),
                        ),
                      ),
                      _round(torch ? Icons.flash_on_rounded : Icons.flash_off_rounded, 'الضوء', () {
                        camera.toggleTorch();
                        setState(() => torch = !torch);
                      }, glass),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                  decoration: glass,
                  child: Text(
                    'وجّه الكاميرا على باركود الوصل أو إيصال الراجع',
                    style: font(13, w6, Colors.white, height: 1.3),
                  ),
                ),
                const Spacer(),
                _sheet(),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _round(IconData icon, String tip, VoidCallback onTap, BoxDecoration glass) => Tooltip(
    message: tip,
    child: Tap(
      radius: 22,
      onTap: onTap,
      child: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(color: glass.color, border: glass.border, shape: BoxShape.circle),
        child: Icon(icon, color: Colors.white, size: 21),
      ),
    ),
  );

  Widget _sweepLine() => IgnorePointer(
    child: LayoutBuilder(
      builder: (context, box) {
        final window = scanWindow(box.biggest, center: .36);
        return AnimatedBuilder(
          animation: sweep,
          builder: (context, _) => Stack(
            children: [
              Positioned(
                left: window.left + 12,
                width: window.width - 24,
                top: window.top + 16 + (window.height - 32) * sweep.value,
                height: 3,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(2),
                    gradient: LinearGradient(
                      colors: [
                        brand.coral.withValues(alpha: 0),
                        Color.lerp(brand.coral, Colors.white, .15)!,
                        brand.coral.withValues(alpha: 0),
                      ],
                    ),
                    boxShadow: [BoxShadow(color: brand.coral.withValues(alpha: .7), blurRadius: 12)],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    ),
  );

  Widget _sheet() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(18, 10, 18, 18),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(30)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(color: const Color(0xFFE9D6D4), borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 14),
          if (done != null) ..._success(done!) else ..._typing(),
        ],
      ),
    );
  }

  List<Widget> _success(Received r) => [
    Row(
      children: [
        Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(
            color: Palette.okSoft,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: const Color(0xFFC6E5CF)),
          ),
          child: Icon(
            r.statement ? Icons.receipt_long_rounded : Icons.inventory_2_rounded,
            size: 26,
            color: Palette.ok,
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.check_circle_rounded, size: 15, color: Palette.ok),
                  const SizedBox(width: 4),
                  Text('تمّ تأكيد الاستلام', style: font(12, w8, Palette.ok)),
                ],
              ),
              Text(r.statement ? 'دفعة كشف حسابك' : 'رواجع الإيصال', style: display(18, w7, Palette.ink)),
            ],
          ),
        ),
      ],
    ),
    const SizedBox(height: 10),
    Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Palette.bg,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Palette.line),
      ),
      child: Text(r.message, style: font(13.5, w6, Palette.ink, height: 1.5)),
    ),
    const SizedBox(height: 14),
    HeroButton(brand: brand, label: 'تمّ', icon: Icons.check_rounded, onTap: () => Navigator.of(context).pop(r)),
  ];

  List<Widget> _typing() => [
    if (busy)
      Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          children: [
            SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: brand.main)),
            const SizedBox(width: 8),
            Text('يتحقّق من الورقة…', style: font(12.5, w6, Palette.slate)),
          ],
        ),
      ),
    if (error != null)
      Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
        child: Text(error!, style: font(12.5, w6, Palette.returnRed, height: 1.5)),
      ),
    Text('أو اكتب الرمز المطبوع تحت الباركود', style: font(12.5, w7, Palette.ink)),
    const SizedBox(height: 6),
    Row(
      children: [
        Expanded(
          child: Container(
            height: 48,
            decoration: BoxDecoration(
              color: Palette.bg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Palette.line),
            ),
            child: TextField(
              controller: typed,
              textDirection: TextDirection.ltr,
              textCapitalization: TextCapitalization.characters,
              onSubmitted: _submit,
              style: font(16, w8, Palette.ink),
              decoration: InputDecoration(
                isDense: true,
                hintText: 'K7M2P-QX9RT',
                hintStyle: font(14, w6, Palette.muted),
                border: InputBorder.none,
                contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        SizedBox(
          width: 96,
          child: HeroButton(brand: brand, label: 'أكّد', height: 48, onTap: () => _submit(typed.text)),
        ),
      ],
    ),
  ];

  Widget _noCamera() => ColoredBox(
    color: const Color(0xFF241517),
    child: Align(
      alignment: const Alignment(0, -.28),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 48),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.no_photography_outlined, color: Colors.white.withValues(alpha: .7), size: 40),
            const SizedBox(height: 10),
            Text(
              'الكاميرا غير متاحة. اسمح للتطبيق بها من إعدادات الهاتف، أو اكتب الرمز أدناه.',
              textAlign: TextAlign.center,
              style: font(13, w6, Colors.white, height: 1.6),
            ),
          ],
        ),
      ),
    ),
  );
}
