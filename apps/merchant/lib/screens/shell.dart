import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import 'create_screen.dart';
import 'home_screen.dart';
import 'scan_screen.dart';
import 'shipments_screen.dart';

/// هيكل التطبيق: الصفحات الخمس والشريط السفلي العائم بزرّ «طلب جديد» في وسطه.
class Shell extends StatefulWidget {
  const Shell({super.key, required this.brand, required this.session, required this.onLogout});

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int tab = 0;

  /// شريحة «شحناتي» التي تفتحها الرئيسية (عدّادٌ أو بطاقةٌ أو «عرض الكل»)
  final shipmentsFilter = ValueNotifier<String>('all');

  /// حُفظت شحنةٌ جديدة: تُعاد الرئيسية و«شحناتي» بأرقامها
  final refresh = ValueNotifier<int>(0);

  /// الوصل المطبوع الممسوح بالزرّ الأوسط — يُكتب عليه «طلب جديد»
  final scanned = ValueNotifier<String?>(null);

  Brand get brand => widget.brand;

  @override
  void dispose() {
    shipmentsFilter.dispose();
    refresh.dispose();
    scanned.dispose();
    super.dispose();
  }

  void _open(String screen) {
    final parts = screen.split(':');
    final target = switch (parts.first) {
      'shipments' || 'processing' || 'attention' => 1,
      'create' => 2,
      'finance' => 3,
      _ => 4,
    };
    if (target == 1) {
      shipmentsFilter.value = switch (parts.length > 1 ? parts[1] : parts.first) {
        'delivered' => 'delivered',
        'open' => 'open',
        'returns' => 'returns',
        'processing' => 'processing',
        'attention' => 'attention',
        _ => 'all',
      };
    }
    setState(() => tab = target);
  }

  /// الزرّ الأوسط (docs/plan/52): يمسح الوصل المطبوع بالكاميرا ثم يفتح «طلب جديد» عليه.
  /// شركةٌ بلا وصولاتٍ مطبوعة: يفتح النموذج مباشرة
  Future<void> _scan() async {
    bool waybills;
    try {
      waybills = (await Api.instance.createForm()).waybills;
    } on ApiError {
      waybills = false;
    }
    if (!mounted) return;
    if (!waybills) {
      setState(() => tab = 2);
      return;
    }

    final result = await Navigator.of(context)
        .push<ScanResult>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (!mounted || result == null) return;
    scanned.value = result.code;
    setState(() => tab = 2);
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      HomeScreen(
        brand: brand,
        companyName: widget.session?.companyName ?? AppConfig.companyName,
        onOpen: _open,
        refresh: refresh,
      ),
      ShipmentsScreen(brand: brand, filter: shipmentsFilter, refresh: refresh),
      CreateScreen(brand: brand, onCreated: () => refresh.value++, scanned: scanned, onScan: _scan),
      const _Soon(title: 'المالية', text: 'كشف حسابك وطلب المحاسبة — المرحلة التالية.'),
      _More(brand: brand, session: widget.session, onLogout: widget.onLogout),
    ];

    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          IndexedStack(index: tab, children: pages),
          if (AppConfig.demo) const _DemoStatusBar(),
        ],
      ),
      bottomNavigationBar: _NavBar(brand: brand, index: tab, onTap: (i) => i == 2 ? _scan() : setState(() => tab = i)),
    );
  }
}

/// الشريط السفلي: أبيض عائم بحوافٍّ مدوّرة، وحدبةٌ تحتضن الزرّ الأحمر في الوسط
class _NavBar extends StatelessWidget {
  const _NavBar({required this.brand, required this.index, required this.onTap});

  final Brand brand;
  final int index;
  final ValueChanged<int> onTap;

  static const _items = [
    ('الرئيسية', Icons.home_rounded),
    ('شحناتي', Icons.assignment_outlined),
    ('طلب جديد', Icons.add_rounded),
    ('المالية', Icons.account_balance_wallet_outlined),
    ('المزيد', Icons.apps_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(7, 0, 7, bottom > 0 ? bottom - 6 : 34),
      child: SizedBox(
        height: 67,
        child: CustomPaint(
          painter: _BarPainter(),
          child: Padding(
            padding: const EdgeInsets.only(top: 13),
            child: Row(children: [for (var i = 0; i < _items.length; i++) Expanded(child: _item(i))]),
          ),
        ),
      ),
    );
  }

  Widget _item(int i) {
    final (label, icon) = _items[i];
    final active = i == index;
    final color = active ? brand.main : Palette.muted;

    if (i == 2) {
      return GestureDetector(
        onTap: () => onTap(i),
        behavior: HitTestBehavior.opaque,
        child: Column(
          children: [
            Transform.translate(
              offset: const Offset(0, -4),
              child: Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [brand.coral, Color.lerp(brand.coral, brand.main, .35)!],
                  ),
                  boxShadow: [
                    BoxShadow(color: brand.main.withValues(alpha: .35), blurRadius: 10, offset: const Offset(0, 4)),
                  ],
                ),
                child: const Icon(Icons.add_rounded, size: 25, color: Colors.white),
              ),
            ),
            const SizedBox(height: 2),
            Text(label, style: font(9.6, w6, Palette.muted, height: 1)),
          ],
        ),
      );
    }

    return GestureDetector(
      onTap: () => onTap(i),
      behavior: HitTestBehavior.opaque,
      child: Column(
        children: [
          const SizedBox(height: 8),
          // «المزيد» تسع نقاطٍ كما في التصميم
          i == 4 ? _Dots(color: color) : Icon(icon, size: 22, color: color),
          const SizedBox(height: 4.5),
          Text(label, style: font(active ? 8.6 : 9.2, active ? w8 : w6, color, height: 1)),
          const SizedBox(height: 3.5),
          Container(
            width: 32,
            height: 3,
            decoration: BoxDecoration(
              color: active ? brand.main : Colors.transparent,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
        ],
      ),
    );
  }
}

class _Dots extends StatelessWidget {
  const _Dots({required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 22,
    height: 22,
    child: Padding(
      padding: const EdgeInsets.all(1.5),
      child: GridView.count(
        crossAxisCount: 3,
        mainAxisSpacing: 2.2,
        crossAxisSpacing: 2.2,
        physics: const NeverScrollableScrollPhysics(),
        padding: EdgeInsets.zero,
        children: [
          for (var i = 0; i < 9; i++)
            DecoratedBox(
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            ),
        ],
      ),
    ),
  );
}

class _BarPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    const top = 13.0, r = 18.0, bump = 9.0, half = 40.0;
    final mid = size.width / 2;
    final path = Path()
      ..moveTo(0, top + r)
      ..quadraticBezierTo(0, top, r, top)
      ..lineTo(mid - half, top)
      ..cubicTo(mid - half * .55, top, mid - half * .5, top - bump, mid, top - bump)
      ..cubicTo(mid + half * .5, top - bump, mid + half * .55, top, mid + half, top)
      ..lineTo(size.width - r, top)
      ..quadraticBezierTo(size.width, top, size.width, top + r)
      ..lineTo(size.width, size.height - r)
      ..quadraticBezierTo(size.width, size.height, size.width - r, size.height)
      ..lineTo(r, size.height)
      ..quadraticBezierTo(0, size.height, 0, size.height - r)
      ..close();
    canvas.drawShadow(path, const Color(0x330F2A55), 6, false);
    canvas.drawPath(path, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _Soon extends StatelessWidget {
  const _Soon({required this.title, required this.text});

  final String title;
  final String text;

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: font(20, w8, Palette.ink)),
          const SizedBox(height: 8),
          Text(text, style: font(13, w5, Palette.slate, height: 1.6)),
        ],
      ),
    ),
  );
}

class _More extends StatelessWidget {
  const _More({required this.brand, required this.session, required this.onLogout});

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('المزيد', style: font(20, w8, Palette.ink)),
          const SizedBox(height: 6),
          if (session != null) Text('${session!.name} · ${session!.companyName}', style: font(13, w6, Palette.slate)),
          const SizedBox(height: 20),
          OutlinedButton.icon(
            onPressed: onLogout,
            icon: Icon(Icons.logout_rounded, color: brand.main),
            label: Text('تسجيل الخروج', style: font(14, w7, brand.main)),
          ),
        ],
      ),
    ),
  );
}

/// شريط الحالة في نسخة العرض على الويب — لتطابق اللقطة ملف التصميم
class _DemoStatusBar extends StatelessWidget {
  const _DemoStatusBar();

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.paddingOf(context).top > 0) return const SizedBox.shrink();
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      height: 28,
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(29, 5, 24, 0),
          child: Row(
            children: [
              Text('9:41', style: font(15, w7, Colors.black, height: 1)),
              const Spacer(),
              const Icon(Icons.signal_cellular_alt_rounded, size: 17, color: Colors.black),
              const SizedBox(width: 4),
              const Icon(Icons.wifi_rounded, size: 17, color: Colors.black),
              const SizedBox(width: 5),
              Container(
                width: 24,
                height: 11.5,
                padding: const EdgeInsets.all(1.5),
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.black45, width: 1),
                  borderRadius: BorderRadius.circular(3.5),
                ),
                child: Container(
                  decoration: BoxDecoration(color: Colors.black, borderRadius: BorderRadius.circular(2)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
