import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/access.dart';
import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/seen.dart';
import '../widgets/art.dart';
import '../widgets/bits.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import 'create_screen.dart';
import 'finance_screen.dart';
import 'home_screen.dart';
import 'import_screen.dart';
import 'labels_screen.dart';
import 'pickups_screen.dart';
import 'processing_screen.dart';
import 'receive_screen.dart';
import 'reader_sheets.dart';
import 'requests_screen.dart';
import 'returns_screen.dart';
import 'scan_screen.dart';
import 'shipment_screen.dart' show shipmentsChanged;
import 'shipments_screen.dart';
import 'staff_screen.dart';
import 'status_screen.dart';
import 'support_screen.dart';
import 'waybills_screen.dart';

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
  /// الصفحة المفتوحة باسمها: home · shipments · create · finance · more
  String tab = 'home';

  /// صفحات الشريط بما يفتحه الحساب (docs/plan/87): الموظّف لا يرى صفحةً لم تُفتح له، ولا تُحمَّل
  List<String> get _tabs => [
    'home',
    if (Access.can('shipments')) 'shipments',
    if (Access.can('create')) 'create',
    if (Access.can('finance')) 'finance',
    'more',
  ];

  void _go(String key) => setState(() => (tab = key, picker = false));

  /// «+» في الوسط مفتوحة: طرق الإدخال فوق الشريط (docs/plan/123)
  bool picker = false;

  /// ما تفتحه الشركة من الإدخال: القراءة بالذكاء الاصطناعي والصوت، والوصولات المطبوعة
  CreateForm? form;

  @override
  void initState() {
    super.initState();
    Access.changed.addListener(_access);
    shipmentsChanged.addListener(_changed);
    // ما فتحه التاجر من شحناته على هذا الهاتف (docs/plan/123)
    Seen.load();
    _loadForm();
  }

  Future<void> _loadForm() async {
    if (!Access.can('create')) return;
    try {
      final f = await Api.instance.createForm();
      if (mounted) setState(() => form = f);
    } on ApiError {
      // تظهر الطرق كلّها، وكلٌّ يفحص حين يُضغط
    }
  }

  /// حُذفت شحنةٌ من تعديلها (docs/plan/117): تُعاد الرئيسية و«شحناتي»
  void _changed() => refresh.value++;

  /// تغيّرت صلاحياته: صفحةٌ أُغلقت تعود إلى الرئيسية
  void _access() {
    if (!mounted) return;
    setState(() {
      if (!_tabs.contains(tab)) tab = 'home';
    });
  }

  /// شريحة «شحناتي» التي تفتحها الرئيسية (عدّادٌ أو بطاقةٌ أو «عرض الكل»)
  final shipmentsFilter = ValueNotifier<String>('all');

  /// بحث الرئيسية يفتح «شحناتي» والمؤشّر في خانة البحث (docs/plan/76)
  final searchNow = ValueNotifier<int>(0);

  /// حُفظت شحنةٌ جديدة: تُعاد الرئيسية و«شحناتي» بأرقامها
  final refresh = ValueNotifier<int>(0);

  /// الوصل المطبوع الممسوح بالزرّ الأوسط — يُكتب عليه «طلب جديد»
  final scanned = ValueNotifier<String?>(null);

  /// ما قرأه الذكاء الاصطناعي أو سُمع — يملأ «طلب جديد» (docs/plan/55)
  final reading = ValueNotifier<OrderReading?>(null);

  Brand get brand => widget.brand;

  @override
  void dispose() {
    Access.changed.removeListener(_access);
    shipmentsChanged.removeListener(_changed);
    shipmentsFilter.dispose();
    searchNow.dispose();
    refresh.dispose();
    scanned.dispose();
    reading.dispose();
    super.dispose();
  }

  void _open(String screen) {
    // ما لم يُفتح للحساب لا يُفتح، ولو وصل إليه زرٌّ قديم (docs/plan/87)
    if (!Access.opens(screen)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('لا تملك هذه الصلاحية. اطلبها من صاحب المتجر.'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }
    final parts = screen.split(':');
    if (screen == 'staff') {
      openStaff(context, brand);
      return;
    }
    // «للمعالجة» شاشةٌ وحدها (docs/plan/54): يقرّر التاجر فيها، ثم تُعاد الأرقام
    if (parts.first == 'processing') {
      openProcessing(context, brand, onChanged: () => refresh.value++);
      return;
    }
    // الأدوات السريعة (docs/plan/56): شاشاتٌ فوق الشريط
    final tool = switch (screen) {
      'pickups' => openPickups,
      'requests' => openRequests,
      'support' => openSupport,
      'waybills' => openWaybills,
      'import' => openImport,
      'labels' => openLabels,
      'returns' => openReturns,
      _ => null,
    };
    if (tool != null) {
      tool(context, brand).then((_) => refresh.value++);
      return;
    }
    // «حالة الشحنات» في الرئيسية (docs/plan/79): كلّ حالةٍ قائمتها وحدها
    if (parts.first == 'status' && parts.length > 1) {
      openStatus(context, brand, parts[1]).then((_) => refresh.value++);
      return;
    }
    // من الرئيسية (docs/plan/76): المسح، و«المزيد»
    if (screen == 'scan') {
      _scan();
      return;
    }
    if (screen == 'account') {
      _go('more');
      return;
    }
    if (screen == 'create:ai' || screen == 'create:voice') {
      _read(voice: screen == 'create:voice');
      return;
    }
    final target = switch (parts.first) {
      'shipments' || 'attention' => 'shipments',
      'create' => 'create',
      'finance' => 'finance',
      _ => 'more',
    };
    if (target == 'shipments') {
      shipmentsFilter.value = switch (parts.length > 1 ? parts[1] : parts.first) {
        'delivered' => 'delivered',
        'new' => 'new',
        'open' => 'open',
        'warehouse' => 'warehouse',
        'returns' => 'returns',
        'processing' => 'processing',
        'attention' => 'attention',
        _ => 'all',
      };
    }
    _go(target);
    if (parts.length > 1 && parts[1] == 'search') searchNow.value++;
  }

  /// «إنشاء بالذكاء الاصطناعي» و«بالتسجيل الصوتي»: يُقرأ الطلب ثم يُفتح «طلب جديد» مملوءاً
  Future<void> _read({required bool voice}) async {
    final messenger = ScaffoldMessenger.of(context);
    CreateForm form;
    try {
      form = await Api.instance.createForm();
    } on ApiError catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating));
      return;
    }
    if (!mounted) return;
    if (!form.reading) {
      messenger.showSnackBar(
        const SnackBar(
          content: Text('القراءة بالذكاء الاصطناعي غير مفعّلة لشركتك — أدخل الطلب يدوياً.'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      _go('create');
      return;
    }
    final result = voice
        ? await showVoiceReader(context, brand, listening: form.listening)
        : await showAiReader(context, brand);
    if (!mounted || result == null) return;
    reading.value = result;
    _go('create');
  }

  /// الزرّ الأوسط (docs/plan/52): يمسح الوصل المطبوع بالكاميرا ثم يفتح «طلب جديد» عليه.
  /// شركةٌ بلا وصولاتٍ مطبوعة: يفتح النموذج مباشرة
  Future<void> _scan() async {
    final create = Access.can('create');
    bool waybills;
    try {
      // موظّفٌ لا يُدخل شحنات (docs/plan/87): الكاميرا لورقة الحساب والراجع وحدها
      waybills = create && (await Api.instance.createForm()).waybills;
    } on ApiError {
      waybills = false;
    }
    if (!mounted) return;
    if (create && !waybills) {
      _go('create');
      return;
    }

    final result = await Navigator.of(context)
        .push<ScanResult>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (!mounted || result == null) return;
    if (result.receipt != null) {
      openReturnReceipt(context, brand, result.receipt!);
      return;
    }
    // باركود وصل حسابٍ أو إيصال راجع (docs/plan/69): يُؤكَّد استلامه
    if (result.receive != null) {
      await openReceive(context, brand, code: result.receive);
      refresh.value++;
      return;
    }
    if (!create) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('إدخال الشحنات غير مفتوحٍ لحسابك.'), behavior: SnackBarBehavior.floating),
      );
      return;
    }
    scanned.value = result.code;
    _go('create');
  }

  @override
  Widget build(BuildContext context) {
    final all = <String, Widget Function()>{
      'home': () => HomeScreen(
        brand: brand,
        companyName: widget.session?.companyName ?? AppConfig.companyName,
        onOpen: _open,
        refresh: refresh,
      ),
      'shipments': () =>
          ShipmentsScreen(brand: brand, filter: shipmentsFilter, refresh: refresh, focusSearch: searchNow),
      'create': () => CreateScreen(
        brand: brand,
        onCreated: () => refresh.value++,
        scanned: scanned,
        onScan: _scan,
        reading: reading,
        onRead: ({required bool voice}) => _read(voice: voice),
      ),
      'finance': () => FinanceScreen(brand: brand, refresh: refresh),
      'more': () =>
          _Account(brand: brand, session: widget.session, onLogout: widget.onLogout, onOpen: _open, refresh: refresh),
    };
    final tabs = _tabs;
    final pages = [for (final key in tabs) KeyedSubtree(key: ValueKey(key), child: all[key]!())];

    // زرّ رجوع الهاتف: يغلق خيارات «+» أوّلاً، ومن أيّ صفحةٍ إلى الرئيسية، ومن الرئيسية يخرج
    return PopScope(
      canPop: tab == 'home' && !picker,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (picker) {
          setState(() => picker = false);
        } else {
          _go('home');
        }
      },
      child: ShellNav(home: () => _go('home'), child: _scaffold(tabs, pages)),
    );
  }

  /// زرّ الوسط (docs/plan/123): «+» لمن يُدخل الشحنات يفتح طرق الإدخال، والمسح لموظّفٍ يستلم الأوراق وحدها
  bool get _creates => Access.can('create');
  bool get _center => _creates || Access.opens('scan');

  void _centerTap() {
    if (_creates) {
      setState(() => picker = !picker);
    } else {
      _scan();
    }
  }

  Widget _scaffold(List<String> tabs, List<Widget> pages) {
    final nav = [
      for (final key in tabs)
        if (key != 'create') key,
    ];
    if (_center) nav.insert(nav.length > 2 ? 2 : nav.length - 1, 'center');
    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          IndexedStack(index: math.max(0, tabs.indexOf(tab)), children: pages),
          if (AppConfig.demo) const _DemoStatusBar(),
          if (picker)
            _Picker(
              brand: brand,
              form: form,
              onClose: () => setState(() => picker = false),
              onPick: (way) {
                setState(() => picker = false);
                switch (way) {
                  case 'ai':
                    _read(voice: false);
                  case 'voice':
                    _read(voice: true);
                  case 'scan':
                    _scan();
                  default:
                    _go('create');
                }
              },
            ),
        ],
      ),
      bottomNavigationBar: _NavBar(
        brand: brand,
        items: nav,
        active: tab == 'create' ? 'center' : tab,
        open: picker,
        scanOnly: !_creates,
        onTap: (key) => key == 'center' ? _centerTap() : _go(key),
      ),
    );
  }
}

/// الشريط السفلي في «وصل» (docs/plan/123): أبيض بخطٍّ رفيعٍ فوقه، ونقطةٌ فاتحةٌ بلون الشركة تنزلق تحت الصفحة
/// المفتوحة، وفي وسطه «+» مرفوعاً — يدور حين تُفتح طرق الإدخال ويصير داكناً.
class _NavBar extends StatelessWidget {
  const _NavBar({
    required this.brand,
    required this.items,
    required this.active,
    required this.open,
    required this.scanOnly,
    required this.onTap,
  });

  final Brand brand;

  /// ما يفتحه الحساب منها، بترتيبها (docs/plan/87)
  final List<String> items;
  final String active;

  /// خيارات «+» مفتوحة
  final bool open;

  /// موظّفٌ لا يُدخل الشحنات: الزرّ للمسح وحده
  final bool scanOnly;
  final ValueChanged<String> onTap;

  static const _items = {
    'home': ('الرئيسية', Icons.home_outlined),
    'shipments': ('الشحنات', Icons.inventory_2_outlined),
    'finance': ('المالية', Icons.account_balance_wallet_outlined),
    'more': ('حسابي', Icons.person_outline_rounded),
  };

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final at = items.indexOf(active);
    return Container(
      padding: EdgeInsets.fromLTRB(8, 6, 8, bottom > 0 ? bottom : 18),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Palette.line)),
      ),
      child: SizedBox(
        height: 56,
        child: LayoutBuilder(
          builder: (context, box) {
            final w = box.maxWidth / items.length;
            return Stack(
              clipBehavior: Clip.none,
              children: [
                // النقطة الفاتحة تحت المفتوحة، تنزلق إلى ما يُضغط
                if (at >= 0 && active != 'center')
                  AnimatedPositionedDirectional(
                    duration: const Duration(milliseconds: 450),
                    curve: const Cubic(.2, .8, .2, 1),
                    start: at * w + (w - 56) / 2,
                    top: 0,
                    child: Container(
                      width: 56,
                      height: 32,
                      decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(16)),
                    ),
                  ),
                Row(children: [for (final key in items) Expanded(child: _item(key))]),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _item(String key) {
    if (key == 'center') return _plus();
    final (label, icon) = _items[key]!;
    final on = key == active;
    final color = on ? brand.main : Palette.muted;
    return Semantics(
      selected: on,
      button: true,
      label: label,
      excludeSemantics: true,
      onTap: () => onTap(key),
      child: GestureDetector(
        onTap: () => onTap(key),
        behavior: HitTestBehavior.opaque,
        child: Column(
          children: [
            SizedBox(
              height: 32,
              child: Center(child: Icon(icon, size: 20, color: color)),
            ),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: AnimatedDefaultTextStyle(
                duration: const Duration(milliseconds: 300),
                style: kf(12, color, bold: true, height: 1.3),
                child: Text(label, maxLines: 1),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// «+» المرفوع: مربّعٌ بلون الشركة بحافّةٍ بيضاء، يدور ربع دورةٍ ويغمق حين تُفتح الخيارات
  Widget _plus() => Semantics(
    button: true,
    label: scanOnly ? 'امسح الوصل أو الإيصال' : (open ? 'إغلاق' : 'شحنة جديدة'),
    expanded: scanOnly ? null : open,
    excludeSemantics: true,
    onTap: () => onTap('center'),
    child: GestureDetector(
      onTap: () => onTap('center'),
      behavior: HitTestBehavior.opaque,
      child: Stack(
        clipBehavior: Clip.none,
        alignment: Alignment.topCenter,
        children: [
          Positioned(
            top: -15,
            child: AnimatedRotation(
              turns: open ? .125 : 0,
              duration: const Duration(milliseconds: 400),
              curve: const Cubic(.2, .9, .3, 1.3),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 300),
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: open ? Palette.ink : brand.main,
                  borderRadius: BorderRadius.circular(17),
                  border: Border.all(color: Colors.white, width: 3.5),
                  boxShadow: const [
                    BoxShadow(color: Color(0x731C1416), blurRadius: 20, spreadRadius: -8, offset: Offset(0, 10)),
                  ],
                ),
                child: Icon(
                  scanOnly ? Icons.qr_code_scanner_rounded : Icons.add_rounded,
                  size: 22,
                  color: Colors.white,
                ),
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

/// طرق إدخال الشحنة (docs/plan/123): فوق الشريط بستارةٍ داكنة — من صورة أو رسالة بالذكاء الاصطناعي، وبالمسح،
/// وبالصوت، ويدوياً. ما لم تفتحه الشركة لا يظهر (القراءة، والوصولات المطبوعة)
class _Picker extends StatelessWidget {
  const _Picker({required this.brand, required this.form, required this.onClose, required this.onPick});

  final Brand brand;

  /// ما تفتحه الشركة — وقبل أن يصل تظهر كلّها، وكلٌّ يفحص حين يُضغط
  final CreateForm? form;
  final VoidCallback onClose;
  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) {
    final reading = form?.reading ?? true;
    final waybills = form?.waybills ?? true;
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Positioned.fill(
      child: Stack(
        children: [
          Positioned.fill(
            child: Semantics(
              button: true,
              label: 'إغلاق',
              child: GestureDetector(
                onTap: onClose,
                child: const Rise(dy: 0, child: ColoredBox(color: Color(0x801C1416))),
              ),
            ),
          ),
          PositionedDirectional(
            start: 12,
            end: 12,
            bottom: bottom + 12,
            child: Rise(
              dy: 28,
              child: Material(
                color: Colors.white,
                borderRadius: BorderRadius.circular(28),
                elevation: 0,
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(28),
                    boxShadow: const [
                      BoxShadow(color: Color(0x8C1C1416), blurRadius: 60, spreadRadius: -24, offset: Offset(0, 30)),
                    ],
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Padding(
                        padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('شحنة جديدة', style: kf(16, Palette.ink, bold: true)),
                            Text('اختر طريقة الإدخال', style: kf(12, Palette.muted)),
                          ],
                        ),
                      ),
                      if (reading) Rise(delay: 60, child: _ai()),
                      if (reading || waybills) ...[
                        const SizedBox(height: 10),
                        IntrinsicHeight(
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              if (waybills)
                                Expanded(
                                  child: Rise(
                                    delay: 120,
                                    child: _small('scan', 'بالمسح', 'امسح وصلاً مطبوعاً', () => onPick('scan')),
                                  ),
                                ),
                              if (waybills && reading) const SizedBox(width: 10),
                              if (reading)
                                Expanded(
                                  child: Rise(
                                    delay: 170,
                                    child: _small('voice', 'بصوتك', 'قل الطلب ويُكتب', () => onPick('voice')),
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ],
                      const SizedBox(height: 10),
                      Rise(delay: 220, child: _manual()),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _ai() => Tap(
    radius: 22,
    onTap: () => onPick('ai'),
    child: DeepCard(
      brand: brand,
      radius: 22,
      padding: const EdgeInsets.all(16),
      child: SizedBox(
        height: 100,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            PositionedDirectional(end: -8, bottom: -10, child: Art('ai', brand: brand, width: 110, height: 100)),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 186),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      height: 24,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: .16),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.auto_awesome_rounded, size: 14, color: Colors.white),
                          const SizedBox(width: 6),
                          Text('بالذكاء الاصطناعي', style: kf(12, Colors.white, bold: true, height: 1)),
                        ],
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text('من صورة أو رسالة', style: kf(16, Colors.white, bold: true)),
                    Text(
                      'صوّر الطلب أو لقطة المحادثة، وتتعبّى الحقول وحدها',
                      style: kf(12, Colors.white.withValues(alpha: .86)),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _small(String art, String title, String sub, VoidCallback onTap) => Tap(
    radius: 22,
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Palette.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            height: 70,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(16)),
            child: Art(art, brand: brand, width: 76, height: art == 'scan' ? 60 : 55),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: kf(14, Palette.ink, bold: true)),
                Text(sub, style: kf(12, Palette.muted)),
              ],
            ),
          ),
        ],
      ),
    ),
  );

  Widget _manual() => Tap(
    radius: 20,
    onTap: () => onPick('manual'),
    child: Container(
      constraints: const BoxConstraints(minHeight: 64),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Palette.line),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
            child: Icon(Icons.edit_outlined, size: 18, color: brand.main),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('أكتبها بنفسي', style: kf(14, Palette.ink, bold: true)),
                Text('الاسم والهاتف والعنوان والمبلغ', style: kf(12, Palette.muted)),
              ],
            ),
          ),
          const Icon(Icons.chevron_right_rounded, color: Palette.muted),
        ],
      ),
    ),
  );
}

/// «حسابي» في «وصل» (docs/plan/123): بطاقة المتجر الداكنة، ثم «كشوفات الرواجع» و«استلام راجع بالباركود» برسميهما
/// (docs/plan/69)، ثم الأدوات بطاقاتٍ في عمودين، والخروج.
class _Account extends StatefulWidget {
  const _Account({
    required this.brand,
    required this.session,
    required this.onLogout,
    required this.onOpen,
    required this.refresh,
  });

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;
  final void Function(String screen) onOpen;
  final Listenable refresh;

  @override
  State<_Account> createState() => _AccountState();
}

class _AccountState extends State<_Account> {
  RequestsPage? returns;

  /// الأدوات بما يفتحه الحساب (docs/plan/87)، و«موظّفو المتجر» لصاحبه
  static List<(String, String, IconData, String)> get _items => [
    for (final item in _all)
      if (Access.opens(item.$4) && (item.$4 != 'staff' || Access.staffFeature)) item,
  ];

  static const _all = [
    ('موظّفو المتجر', '', Icons.groups_2_outlined, 'staff'),
    ('الطابعة', 'وصولات الطلبات الجديدة', Icons.print_outlined, 'labels'),
    ('للمعالجة', '', Icons.schedule_rounded, 'processing'),
    ('طلبات الاستلام', '', Icons.hail_rounded, 'pickups'),
    ('طلباتي', '', Icons.assignment_outlined, 'requests'),
    ('الدعم', '', Icons.headset_mic_outlined, 'support'),
    ('دفاتر الوصولات', 'فارغة للطباعة', Icons.receipt_long_outlined, 'waybills'),
    ('رفع شحنات من ملف', '', Icons.upload_file_outlined, 'import'),
  ];

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    if (!Access.can('finance')) return;
    try {
      final r = await Api.instance.requests();
      if (mounted) setState(() => returns = r);
    } on ApiError {
      // تبقى الأدوات ظاهرةً بلا الكشوفات
    }
  }

  Future<void> _scan() async {
    final done = await openReceive(context, brand);
    if (done != null) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final name = session?.name ?? '';
    final top = MediaQuery.paddingOf(context).top;
    final waiting = returns?.batches.where((b) => b.received == null).length ?? 0;
    final items = _items;
    final hasReturns = Access.opens('returns');
    final hasReceive = Access.opens('receive');
    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 8 : 36, 16, 130),
        children: [
          Text('حسابي', style: kf(24, Palette.ink, bold: true)),
          const SizedBox(height: 16),
          Rise(
            delay: 60,
            child: DeepCard(
              brand: brand,
              child: Row(
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
                    child: Text(name.isEmpty ? '؟' : name.characters.first, style: kf(24, brand.main, bold: true)),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: kf(16, Colors.white, bold: true),
                        ),
                        if (session != null)
                          Text(session.companyName, style: kf(12, Colors.white.withValues(alpha: .85))),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (AppConfig.demo) ...[const SizedBox(height: 8), const DemoNote()],
          if (hasReturns || hasReceive) ...[
            const SizedBox(height: 16),
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (hasReturns)
                    Expanded(
                      child: Rise(
                        delay: 120,
                        child: _bigCard(
                          art: 'returns',
                          color: Tone.red.tint,
                          title: 'كشوفات الرواجع',
                          line: waiting > 0 ? '${money(waiting)} بانتظارك' : 'لا شيء بانتظارك',
                          lineColor: waiting > 0 ? brand.main : Palette.slate,
                          onTap: () => widget.onOpen('returns'),
                        ),
                      ),
                    ),
                  if (hasReturns && hasReceive) const SizedBox(width: 12),
                  if (hasReceive)
                    Expanded(
                      child: Rise(
                        delay: 170,
                        child: _bigCard(
                          art: 'scan',
                          color: brand.soft,
                          title: 'استلام راجع بالباركود',
                          line: 'امسح إيصال الراجع',
                          lineColor: Palette.slate,
                          onTap: _scan,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 12),
          for (var i = 0; i < items.length; i += 2) ...[
            if (i > 0) const SizedBox(height: 10),
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    child: Rise(delay: 240 + i * 40, child: _tool(items[i], waiting)),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: i + 1 < items.length
                        ? Rise(delay: 280 + i * 40, child: _tool(items[i + 1], waiting))
                        : const SizedBox.shrink(),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 16),
          Tap(
            radius: 16,
            onTap: widget.onLogout,
            child: Container(
              height: 52,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: brand.border),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.logout_rounded, size: 18, color: brand.main),
                  const SizedBox(width: 8),
                  Text('تسجيل الخروج', style: kf(14, brand.main, bold: true)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// بطاقةٌ كبيرة برسمها في أعلاها: كشوفات الرواجع، واستلام الراجع بالمسح
  Widget _bigCard({
    required String art,
    required Color color,
    required String title,
    required String line,
    required Color lineColor,
    required VoidCallback onTap,
  }) => Tap(
    radius: 24,
    onTap: onTap,
    child: Container(
      constraints: const BoxConstraints(minHeight: 160),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(24)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: Rise(delay: 300, scale: true, child: Art(art, brand: brand, width: 74, height: 65)),
          ),
          const SizedBox(height: 8),
          Text(title, style: kf(14, Palette.ink, bold: true)),
          Text(line, style: kf(12, lineColor, bold: lineColor != Palette.slate)),
        ],
      ),
    ),
  );

  Widget _tool((String, String, IconData, String) item, int waiting) {
    final (label, sub, icon, screen) = item;
    return Tap(
      radius: 20,
      shadow: cardShadow,
      onTap: () => widget.onOpen(screen),
      child: Ink(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 52),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
                child: Icon(icon, size: 18, color: brand.main),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: kf(14, Palette.ink, bold: true, height: 1.4)),
                    if (sub.isNotEmpty) Text(sub, style: kf(12, Palette.muted)),
                  ],
                ),
              ),
            ],
          ),
        ),
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
