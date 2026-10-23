import 'dart:async';

import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp.dart';
import '../widgets/page.dart';
import 'cash_screen.dart';
import 'home_screen.dart';
import 'inbox_screens.dart';
import 'more_screen.dart';
import 'scan_screen.dart';
import 'shipment_screen.dart';
import 'tasks_screen.dart';
import 'work_screens.dart';

/// هيكل التطبيق (docs/plan/66): الرئيسية · مهامي · [مسح الشحنة] · حسابي · المزيد — بشريط تطبيق
/// التاجر نفسه، والزرّ الأحمر في وسطه يفتح الماسح.
class Shell extends StatefulWidget {
  const Shell({super.key, required this.brand, required this.session, required this.onLogout});

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> with WidgetsBindingObserver {
  int tab = 0;

  /// الشحنات تصل من الموقع في أي وقت: والتطبيق مفتوحٌ يعيد قراءتها كلّ دقائق، وحين يُفتح من الخلفية
  Timer? _poll;
  static const _every = Duration(seconds: 20);

  /// سُجّلت شحنةٌ أو طُلب تغيير مبلغ: تُعاد الرئيسية و«مهامي» و«حسابي» بأرقامها
  final refresh = ValueNotifier<int>(0);

  /// شريحة «مهامي» التي تفتحها الرئيسية: delivery أو returns
  final tasksKind = ValueNotifier<String>('delivery');

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    LocationShare.instance.start();
    // رسالة واتساب للزبون تُملأ باسمه وشركته (docs/plan/74)
    WhatsAppTemplate.courier = widget.session?.name ?? '';
    WhatsAppTemplate.company = widget.session?.companyName ?? '';
    WhatsAppTemplate.load();
    WidgetsBinding.instance.addObserver(this);
    _startPolling();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    LocationShare.instance.stop();
    refresh.dispose();
    tasksKind.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      refresh.value++;
      _startPolling();
    } else {
      _poll?.cancel();
      _poll = null;
    }
  }

  /// تحت الاختبار أو بالعرض لا نظام يُسأل: لا مؤقّت
  void _startPolling() {
    if (AppConfig.demo || AppConfig.testing || _poll != null) return;
    _poll = Timer.periodic(_every, (_) => refresh.value++);
  }

  /// الشحنة فوق الشريط — وعند الرجوع تُعاد الأرقام
  Future<void> _openShipment(int id) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ShipmentScreen(brand: brand, id: id),
      ),
    );
    refresh.value++;
  }

  void _open(String screen) {
    if (screen.startsWith('shipment:')) {
      _openShipment(int.parse(screen.split(':')[1]));
      return;
    }
    // شاشاته الأخرى فوق الشريط (docs/plan/71) — وعند الرجوع تُعاد الأرقام
    final page = switch (screen) {
      'today' => () => openToday(context, brand, onShipment: _openShipment),
      'pickups' => () => openPickups(context, brand),
      'shares' => () => openShares(context, brand),
      'notifications' => () => openNotifications(context, brand),
      'chat' => () => openChat(context, brand, onShipment: _openShipment),
      _ => null,
    };
    if (page != null) {
      page().then((_) => refresh.value++);
      return;
    }
    if (screen == 'tasks' || screen == 'returns') tasksKind.value = screen == 'returns' ? 'returns' : 'delivery';
    setState(
      () => tab = switch (screen) {
        'tasks' || 'returns' => 1,
        'cash' => 3,
        'more' => 4,
        _ => 0,
      },
    );
  }

  /// الزرّ الأوسط: الماسح يجد الشحنة برقمها أو باركودها أو رمز QR، ثم تُفتح
  Future<void> _scan() async {
    final id = await Navigator.of(context)
        .push<int>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (mounted && id != null) _openShipment(id);
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      HomeScreen(brand: brand, onOpen: _open, refresh: refresh),
      TasksScreen(brand: brand, kind: tasksKind, refresh: refresh, onOpen: _openShipment),
      const SizedBox.shrink(),
      CashScreen(brand: brand, refresh: refresh),
      MoreScreen(brand: brand, session: widget.session, onLogout: widget.onLogout, onOpen: _open, refresh: refresh),
    ];

    // زرّ رجوع الهاتف: من أيّ صفحةٍ إلى الرئيسية، ومن الرئيسية يخرج
    return PopScope(
      canPop: tab == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => tab = 0);
      },
      child: ShellNav(
        home: () => setState(() => tab = 0),
        child: Scaffold(
          extendBody: true,
          body: Stack(
            children: [
              IndexedStack(index: tab, children: pages),
              if (AppConfig.demo) const _DemoStatusBar(),
            ],
          ),
          bottomNavigationBar: _NavBar(
            brand: brand,
            index: tab,
            onTap: (i) => i == 2 ? _scan() : setState(() => tab = i),
          ),
        ),
      ),
    );
  }
}

/// الشريط السفلي (تصميم ز كتطبيق التاجر): أبيض بعرض الشاشة، وزرّ المسح مرفوعٌ في وسطه بتدرّج لون الشركة
class _NavBar extends StatelessWidget {
  const _NavBar({required this.brand, required this.index, required this.onTap});

  final Brand brand;
  final int index;
  final ValueChanged<int> onTap;

  static const _items = [
    ('الرئيسية', Icons.home_outlined, Icons.home_rounded),
    ('مهامي', Icons.format_list_bulleted_rounded, Icons.format_list_bulleted_rounded),
    ('مسح الشحنة', Icons.qr_code_scanner_rounded, Icons.qr_code_scanner_rounded),
    ('حسابي', Icons.account_balance_wallet_outlined, Icons.account_balance_wallet_rounded),
    ('المزيد', Icons.grid_view_outlined, Icons.grid_view_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Container(
      padding: EdgeInsets.only(bottom: bottom > 0 ? bottom : 12),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Palette.line)),
        boxShadow: [BoxShadow(color: Color(0x2226181A), blurRadius: 28, spreadRadius: -14, offset: Offset(0, -10))],
      ),
      child: SizedBox(
        height: 62,
        child: Row(children: [for (var i = 0; i < _items.length; i++) Expanded(child: _item(i))]),
      ),
    );
  }

  Widget _item(int i) {
    final (label, icon, filled) = _items[i];
    final active = i == index;
    final color = active ? brand.deep : Palette.muted;

    if (i == 2) {
      return Tooltip(
        message: label,
        child: GestureDetector(
          onTap: () => onTap(i),
          behavior: HitTestBehavior.opaque,
          child: Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.topCenter,
            children: [
              Positioned(
                top: -28,
                child: Container(
                  width: 58,
                  height: 58,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: brand.hero,
                    border: Border.all(color: Colors.white, width: 4),
                    boxShadow: brand.glow(.6),
                  ),
                  child: Icon(icon, size: 25, color: Colors.white),
                ),
              ),
              Positioned(bottom: 8, child: Text(label, style: font(11, w8, Palette.ink, height: 1))),
            ],
          ),
        ),
      );
    }

    return GestureDetector(
      onTap: () => onTap(i),
      behavior: HitTestBehavior.opaque,
      child: Column(
        children: [
          Container(
            width: 24,
            height: 3,
            decoration: BoxDecoration(gradient: active ? brand.hero : null, borderRadius: BorderRadius.circular(2)),
          ),
          const SizedBox(height: 7),
          Icon(active ? filled : icon, size: 23, color: color),
          const SizedBox(height: 4),
          Text(label, style: font(11.5, active ? w8 : w6, color, height: 1)),
        ],
      ),
    );
  }
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
      // رسمٌ فقط: لا يحجب لمس ما تحته حين تُمرَّر الصفحة
      child: IgnorePointer(
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
      ),
    );
  }
}
