import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';

/// الاستلام من التاجر بمسح الشحنات (docs/plan/72): يمسح المندوب باركود كلّ طردٍ يأخذه، أو يكتب رقمه،
/// فتُضاف الشحنة إلى القائمة إن كانت من شحنات هذا التاجر بانتظار الاستلام — ثم يُستلم ما مُسح وحده،
/// وما لم يُمسح يبقى عند التاجر. ويعود بنصّ النظام بعد التسجيل.
class PickupScanScreen extends StatefulWidget {
  const PickupScanScreen({super.key, required this.brand, required this.pickup});

  final Brand brand;
  final PickupItem pickup;

  @override
  State<PickupScanScreen> createState() => _PickupScanScreenState();
}

class _PickupScanScreenState extends State<PickupScanScreen> {
  final camera = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.code128, BarcodeFormat.qrCode],
  );
  final typed = TextEditingController();
  final scanned = <ScannedParcel>[];

  bool checking = false;
  bool sending = false;

  /// آخر ما قيل بعد المسح: أُضيفت، أو لماذا لم تُضف
  String? note;
  bool noteBad = false;

  Brand get brand => widget.brand;

  @override
  void dispose() {
    camera.dispose();
    typed.dispose();
    super.dispose();
  }

  Future<void> _check(String raw) async {
    final code = LatinDigits.convert(raw).trim();
    if (checking || code.isEmpty) return;
    setState(() => checking = true);
    try {
      final parcel = await Api.instance.pickupScan(widget.pickup.id, code);
      if (!mounted) return;
      if (scanned.any((p) => p.id == parcel.id)) {
        setState(() => (note = 'الشحنة ${parcel.number} ممسوحةٌ سلفاً.', noteBad = true));
      } else {
        HapticFeedback.lightImpact();
        typed.clear();
        setState(() {
          scanned.insert(0, parcel);
          note = 'أُضيفت الشحنة ${parcel.number}.';
          noteBad = false;
        });
      }
    } on ApiError catch (e) {
      HapticFeedback.heavyImpact();
      if (mounted) setState(() => (note = e.message, noteBad = true));
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  Future<void> _send() async {
    if (scanned.isEmpty || sending) return;
    setState(() => sending = true);
    try {
      final message = await Api.instance.completePickup(
        widget.pickup.id,
        scanned.length,
        shipments: [for (final p in scanned) p.id],
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (sending = false, note = e.message, noteBad = true));
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.pickup;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(title: 'استلام من ${p.merchant}', subtitle: 'امسح كلّ طردٍ تأخذه — ما لم يُمسح يبقى عند التاجر'),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(20),
                child: SizedBox(
                  height: 190,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      if (AppConfig.testing)
                        const ColoredBox(color: Color(0xFF241517))
                      else
                        MobileScanner(
                          controller: camera,
                          onDetect: (capture) {
                            final value = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
                            if (value != null) _check(value);
                          },
                          errorBuilder: (context, e) => _noCamera(),
                          placeholderBuilder: (context) => const ColoredBox(color: Color(0xFF241517)),
                        ),
                      // خطّ الماسح في الوسط: يوجّه الباركود إليه
                      Center(
                        child: Container(
                          height: 2,
                          margin: const EdgeInsets.symmetric(horizontal: 40),
                          decoration: BoxDecoration(
                            color: brand.coral,
                            boxShadow: [BoxShadow(color: brand.coral.withValues(alpha: .7), blurRadius: 10)],
                          ),
                        ),
                      ),
                      if (checking)
                        const Align(
                          alignment: Alignment(0, .85),
                          child: SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 10),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: Row(
                children: [
                  Expanded(
                    child: Container(
                      height: 46,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(14),
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
                          hintText: 'أو اكتب رقم الوصل',
                          hintStyle: font(13, w5, Palette.muted),
                          border: InputBorder.none,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  SizedBox(
                    width: 90,
                    child: SheetButton(brand: brand, label: 'أضف', onTap: () => _check(typed.text)),
                  ),
                ],
              ),
            ),
            if (note != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 0),
                child: Row(
                  children: [
                    Icon(
                      noteBad ? Icons.error_outline_rounded : Icons.check_circle_rounded,
                      size: 17,
                      color: noteBad ? Palette.returnRed : Palette.ok,
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(note!, style: font(12.5, w7, noteBad ? Palette.returnRed : Palette.ok, height: 1.4)),
                    ),
                  ],
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
              child: Row(
                children: [
                  Text('ما مسحته', style: font(14.5, w8, Palette.ink)),
                  const Spacer(),
                  Text(
                    '${scanned.length} من ${p.expected} متوقَّع',
                    style: font(12.5, w7, scanned.length == p.expected ? Palette.ok : Palette.slate),
                  ),
                ],
              ),
            ),
            Expanded(
              child: scanned.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text(
                          'لم تمسح شيئاً بعد. وجّه الكاميرا على باركود الوصل الملصق على الطرد.',
                          textAlign: TextAlign.center,
                          style: font(13, w6, Palette.muted, height: 1.6),
                        ),
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
                      itemCount: scanned.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 6),
                      itemBuilder: (context, i) => _row(scanned[i]),
                    ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
              decoration: const BoxDecoration(
                color: Colors.white,
                border: Border(top: BorderSide(color: Palette.line)),
              ),
              child: SheetButton(
                brand: brand,
                icon: Icons.check_rounded,
                label: scanned.isEmpty ? 'امسح الطرود أوّلاً' : 'سجّل استلام ${parcels(scanned.length)}',
                busy: sending,
                onTap: scanned.isEmpty ? null : _send,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(ScannedParcel p) => WhiteCard(
    radius: 16,
    padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
    child: Row(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(color: Palette.okSoft, borderRadius: BorderRadius.circular(10)),
          child: const Icon(Icons.inventory_2_rounded, size: 17, color: Palette.ok),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(tag(p.number), style: font(13.5, w8, Palette.ink)),
              if ((p.recipient ?? '').isNotEmpty) Text(p.recipient!, style: font(11.5, w6, Palette.slate)),
            ],
          ),
        ),
        IconButton(
          tooltip: 'أزل ${p.number}',
          onPressed: () => setState(() => scanned.remove(p)),
          icon: const Icon(Icons.close_rounded, size: 19, color: Palette.muted),
        ),
      ],
    ),
  );

  Widget _noCamera() => ColoredBox(
    color: const Color(0xFF241517),
    // تحت خطّ الماسح لا عليه
    child: Align(
      alignment: const Alignment(0, .7),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Text(
          'الكاميرا غير متاحة. اسمح للتطبيق بها من إعدادات الهاتف، أو اكتب رقم الوصل أدناه.',
          textAlign: TextAlign.center,
          style: font(12.5, w6, Colors.white, height: 1.6),
        ),
      ),
    ),
  );
}
