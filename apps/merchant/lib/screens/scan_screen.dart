import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../core/api.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'create_screen.dart' show LatinDigits;

/// ما يعود من شاشة المسح: رقم وصلٍ مقبول، أو «بلا وصل» للإدخال اليدوي
class ScanResult {
  const ScanResult.waybill(String this.code) : manual = false;
  const ScanResult.manual() : code = null, manual = true;

  final String? code;
  final bool manual;
}

/// الزرّ الأوسط (docs/plan/52): كاميرا تقرأ باركود الوصل المطبوع أو رمزه، فيتحقّق النظام أنه
/// من وصولات التاجر ولم يُستعمل — ثم تُكتب بيانات الطلب في «طلب جديد» على هذا الوصل.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  final camera = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.code128, BarcodeFormat.qrCode],
  );
  final typed = TextEditingController();

  bool checking = false;
  String? error;
  bool torch = false;

  Brand get brand => widget.brand;

  @override
  void dispose() {
    camera.dispose();
    typed.dispose();
    super.dispose();
  }

  Future<void> _check(String raw) async {
    final code = LatinDigits.convert(raw).replaceAll(RegExp(r'\s'), '');
    if (checking || code.isEmpty) return;
    setState(() => (checking = true, error = null));
    try {
      final ok = await Api.instance.checkWaybill(code);
      if (mounted) Navigator.of(context).pop(ScanResult.waybill(ok));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          MobileScanner(
            controller: camera,
            onDetect: (capture) {
              final value = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
              if (value != null) _check(value);
            },
            errorBuilder: (context, e) => _noCamera(),
            placeholderBuilder: (context) => const ColoredBox(color: Colors.black),
          ),
          const _Frame(),
          SafeArea(child: Column(children: [_top(), const Spacer(), _bottom()])),
        ],
      ),
    );
  }

  Widget _top() {
    Widget round(IconData icon, VoidCallback onTap, String tip) => Tooltip(
      message: tip,
      child: Tap(
        radius: 20,
        onTap: onTap,
        child: Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(color: Colors.black.withValues(alpha: .45), shape: BoxShape.circle),
          child: Icon(icon, color: Colors.white, size: 21),
        ),
      ),
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
      child: Column(
        children: [
          Row(
            children: [
              round(Icons.close_rounded, () => Navigator.of(context).pop(), 'إغلاق'),
              const Spacer(),
              round(torch ? Icons.flash_on_rounded : Icons.flash_off_rounded, () {
                camera.toggleTorch();
                setState(() => torch = !torch);
              }, 'الضوء'),
            ],
          ),
          const SizedBox(height: 14),
          Text('امسح الوصل المطبوع', style: font(19, w8, Colors.white)),
          const SizedBox(height: 4),
          Text(
            'وجّه الكاميرا إلى الباركود أو رمز QR على الوصل، ثم اكتب بيانات الطلب.',
            textAlign: TextAlign.center,
            style: font(12.5, w5, Colors.white.withValues(alpha: .85), height: 1.5),
          ),
        ],
      ),
    );
  }

  Widget _bottom() {
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (checking)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                children: [
                  SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: brand.main)),
                  const SizedBox(width: 8),
                  Text('يتحقّق من الوصل…', style: font(12.5, w6, Palette.slate)),
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
          Text('أو اكتب رقم الوصل', style: font(12, w7, Palette.ink)),
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Container(
                  height: 46,
                  decoration: BoxDecoration(
                    color: const Color(0xFFF6F8FB),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Palette.line),
                  ),
                  child: TextField(
                    controller: typed,
                    keyboardType: TextInputType.number,
                    textDirection: TextDirection.ltr,
                    inputFormatters: const [LatinDigits()],
                    onSubmitted: _check,
                    style: font(15, w7, Palette.ink),
                    decoration: InputDecoration(
                      isDense: true,
                      hintText: '90000001',
                      hintStyle: font(13, w5, Palette.muted),
                      border: InputBorder.none,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Tap(
                radius: 12,
                onTap: () => _check(typed.text),
                child: Container(
                  height: 46,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(12)),
                  child: Text('تحقّق', style: font(14, w8, Colors.white)),
                ),
              ),
            ],
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(const ScanResult.manual()),
            child: Text('بلا وصلٍ مطبوع — إدخال يدوي', style: font(13, w7, brand.main)),
          ),
        ],
      ),
    );
  }

  Widget _noCamera() => ColoredBox(
    color: const Color(0xFF1B2333),
    // تحت إطار المسح لا فوقه
    child: Align(
      alignment: const Alignment(0, .42),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.no_photography_outlined, color: Colors.white.withValues(alpha: .7), size: 40),
            const SizedBox(height: 10),
            Text(
              'الكاميرا غير متاحة. اسمح للتطبيق باستعمالها من إعدادات الهاتف، أو اكتب رقم الوصل أدناه.',
              textAlign: TextAlign.center,
              style: font(13, w6, Colors.white, height: 1.6),
            ),
          ],
        ),
      ),
    ),
  );
}

/// إطار المسح: ظلٌّ حول نافذةٍ عريضة تناسب الباركود، بزوايا بيضاء
class _Frame extends StatelessWidget {
  const _Frame();

  @override
  Widget build(BuildContext context) => IgnorePointer(child: CustomPaint(painter: _FramePainter()));
}

class _FramePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width * .78;
    final h = w * .62;
    final window = Rect.fromCenter(center: Offset(size.width / 2, size.height * .42), width: w, height: h);
    final hole = RRect.fromRectAndRadius(window, const Radius.circular(18));

    canvas.drawPath(
      Path.combine(PathOperation.difference, Path()..addRect(Offset.zero & size), Path()..addRRect(hole)),
      Paint()..color = Colors.black.withValues(alpha: .5),
    );

    final corner = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4
      ..strokeCap = StrokeCap.round;
    const l = 26.0;
    for (final (x, y, dx, dy) in [
      (window.left, window.top, 1.0, 1.0),
      (window.right, window.top, -1.0, 1.0),
      (window.left, window.bottom, 1.0, -1.0),
      (window.right, window.bottom, -1.0, -1.0),
    ]) {
      canvas.drawPath(
        Path()
          ..moveTo(x, y + dy * l)
          ..lineTo(x, y + dy * 8)
          ..quadraticBezierTo(x, y, x + dx * 8, y)
          ..lineTo(x + dx * l, y),
        corner,
      );
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
