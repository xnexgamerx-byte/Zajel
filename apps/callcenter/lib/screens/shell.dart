import 'dart:async';

import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp_template.dart';
import '../widgets/page.dart';
import 'cases_screen.dart';
import 'conversations_screen.dart';
import 'home_screen.dart';
import 'search_screen.dart';
import 'tickets_screen.dart';

/// هيكل تطبيق الكول سنتر (docs/plan/73): الرئيسية · التذاكر · [ابحث] · المعالجة · المحادثات — وعلى كلّ
/// طابورٍ عدد ما ينتظر فيه. والطوابير تُعاد قراءتها وحدها كلّ عشرين ثانية والتطبيق مفتوح.
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
  HomeData? home;
  Timer? _poll;
  static const _every = Duration(seconds: 20);

  /// حُسم طلبٌ أو حُجز: تُعاد الطوابير كلّها
  final refresh = ValueNotifier<int>(0);

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _startPolling();
    // رسالة واتساب للزبون باسمها وشركتها (docs/plan/80)
    WhatsAppTemplate.agent = widget.session?.name ?? '';
    WhatsAppTemplate.company = widget.session?.companyName ?? '';
    WhatsAppTemplate.load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    refresh.dispose();
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

  void _startPolling() {
    if (AppConfig.demo || AppConfig.testing || _poll != null) return;
    _poll = Timer.periodic(_every, (_) => refresh.value++);
  }

  Future<void> _push(Future<String?> Function() open) async {
    final message = await open();
    if (message != null && mounted) toast(context, message);
    refresh.value++;
  }

  void _open(String screen) {
    final parts = screen.split(':');
    final id = parts.length > 1 ? int.tryParse(parts[1]) : null;
    if (id != null) {
      switch (parts.first) {
        case 'ticket':
          _push(() => openTicket(context, brand, id));
        case 'processing':
          _push(() => openCase(context, brand, id));
        default:
          _push(() => openConversation(context, brand, id));
      }
      return;
    }
    if (screen == 'search') {
      _search();
      return;
    }
    setState(
      () => tab = switch (screen) {
        'tickets' => 1,
        'processing' => 3,
        'conversations' => 4,
        _ => 0,
      },
    );
  }

  Future<void> _search({bool scan = false}) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => SearchScreen(brand: brand, scanFirst: scan),
      ),
    );
    refresh.value++;
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      HomeScreen(
        brand: brand,
        refresh: refresh,
        onOpen: _open,
        onData: (d) => setState(() => home = d),
        onLogout: widget.onLogout,
      ),
      TicketsScreen(brand: brand, refresh: refresh),
      const SizedBox.shrink(),
      CasesScreen(brand: brand, refresh: refresh),
      ConversationsScreen(brand: brand, refresh: refresh),
    ];

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
              if (AppConfig.demo) _DemoStatusBar(light: tab == 0),
            ],
          ),
          bottomNavigationBar: _NavBar(
            brand: brand,
            index: tab,
            badges: [
              null,
              home?.tickets?.count,
              null,
              home?.processing?.free ?? home?.processing?.count,
              home?.conversations?.count,
            ],
            alert: (home?.tickets?.late ?? 0) > 0,
            onTap: (i) => i == 2 ? _search(scan: true) : setState(() => tab = i),
          ),
        ),
      ),
    );
  }
}

/// الشريط السفلي (تصميم ز): أبيض بعرض الشاشة، وزرّ «امسح» مرفوعٌ في وسطه، وعلى كلّ طابورٍ عدده
class _NavBar extends StatelessWidget {
  const _NavBar({
    required this.brand,
    required this.index,
    required this.badges,
    required this.onTap,
    this.alert = false,
  });

  final Brand brand;
  final int index;
  final List<int?> badges;
  final ValueChanged<int> onTap;

  /// تذكرةٌ تأخّرت: شارة التذاكر تنبض بلون الشركة
  final bool alert;

  static const _items = [
    ('الرئيسية', Icons.space_dashboard_outlined, Icons.space_dashboard_rounded),
    ('التذاكر', Icons.price_change_outlined, Icons.price_change_rounded),
    ('امسح', Icons.qr_code_scanner_rounded, Icons.qr_code_scanner_rounded),
    ('المعالجة', Icons.support_agent_outlined, Icons.support_agent_rounded),
    ('المحادثات', Icons.forum_outlined, Icons.forum_rounded),
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
        message: 'امسح الوصل أو ابحث',
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
              Positioned(bottom: 8, child: Text(label, style: font(11.5, w8, Palette.ink, height: 1))),
            ],
          ),
        ),
      );
    }

    final badge = badges[i];
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
          Stack(
            clipBehavior: Clip.none,
            children: [
              Icon(active ? filled : icon, size: 23, color: color),
              if (badge != null && badge > 0)
                PositionedDirectional(
                  top: -6,
                  end: -12,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 18),
                    height: 18,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: i == 1 && alert ? brand.main : Palette.ink,
                      borderRadius: BorderRadius.circular(9),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    child: Text(badge > 99 ? '99+' : '$badge', style: font(10, w8, Colors.white, height: 1)),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(label, style: font(11.5, active ? w8 : w6, color, height: 1)),
        ],
      ),
    );
  }
}

/// شريط الحالة في نسخة العرض على الويب — لتطابق اللقطة الهاتف
class _DemoStatusBar extends StatelessWidget {
  const _DemoStatusBar({required this.light});

  /// فوق رأس الرئيسية الأحمر أبيض، وفوق غيره داكن
  final bool light;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.paddingOf(context).top > 0) return const SizedBox.shrink();
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      height: 28,
      // رسمٌ فقط: لا يحجب لمس ما تحته
      child: IgnorePointer(
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(29, 5, 24, 0),
            child: Row(
              children: [
                Text('9:41', style: font(15, w7, light ? Colors.white : Colors.black, height: 1)),
                const Spacer(),
                Icon(Icons.signal_cellular_alt_rounded, size: 17, color: (light ? Colors.white : Colors.black)),
                const SizedBox(width: 4),
                Icon(Icons.wifi_rounded, size: 17, color: (light ? Colors.white : Colors.black)),
                const SizedBox(width: 5),
                Icon(Icons.battery_full_rounded, size: 18, color: (light ? Colors.white : Colors.black)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
