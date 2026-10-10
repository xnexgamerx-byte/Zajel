import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'notifications_screen.dart';
import 'shipment_screen.dart';

/// «صباح الخير» حتى الظهر بتوقيت بغداد، ثم «مساء الخير» — أيّاً كانت ساعة الهاتف (docs/plan/59)
String baghdadGreeting([DateTime? now]) =>
    (now ?? DateTime.now()).toUtc().add(const Duration(hours: 3)).hour < 12 ? 'صباح الخير' : 'مساء الخير';

/// رئيسية التاجر: التحيّة بصورته، والإعلانات تنزلق، والرصيد، والعدّادات الأربعة، وإنشاء شحنة
/// بثلاث طرق، و«للمعالجة» و«تحتاج انتباهك»، ثم آخر الشحنات. والأدوات كلّها في «المزيد».
///
/// القياسات من ملف التصميم مكبّرةً لتُقرأ على الهاتف (docs/plan/59): لا نصّ أصغر من ١١، والاسم
/// الطويل ينكسر سطرين ولا يصغر.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.brand, required this.companyName, this.onOpen, this.refresh});

  final Brand brand;

  /// اسم الشركة كما وصل من النظام
  final String companyName;

  /// يفتح شاشةً من شاشات التطبيق باسمها (shipments, create, finance…)
  final void Function(String screen)? onOpen;

  /// يُعاد التحميل حين يتغيّر — بعد حفظ شحنةٍ جديدة
  final Listenable? refresh;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  HomeData? data;
  String? error;
  bool hidden = false;

  /// الصورة كما اختارها الآن — تظهر قبل أن يعود رابطها من النظام
  Uint8List? picked;
  bool uploading = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh?.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh?.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.home();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _open(String screen) => widget.onOpen?.call(screen);

  Future<void> _bell() async {
    await openNotifications(context, brand);
    _load();
  }

  // ------------------------------------------------------------------ صورته أو شعاره

  Future<void> _logo(HomeData d) async {
    final has = picked != null || d.logo != null;
    final action = await showSheet<String>(
      context,
      SheetFrame(
        title: has ? 'صورتك' : 'ضع صورتك أو شعار متجرك',
        text: 'تظهر في رأس رئيسيتك. صورةٌ مربّعة تبدو أجمل.',
        children: [
          SheetButton(
            brand: brand,
            label: has ? 'غيّرها' : 'اختر صورة',
            icon: Icons.add_photo_alternate_outlined,
            onTap: () => Navigator.of(context).pop('pick'),
          ),
          if (has) ...[
            const SizedBox(height: 8),
            SheetButton(
              brand: brand,
              label: 'احذفها',
              icon: Icons.delete_outline_rounded,
              outlined: true,
              onTap: () => Navigator.of(context).pop('delete'),
            ),
          ],
        ],
      ),
    );
    if (!mounted || action == null) return;
    if (action == 'delete') {
      setState(() => uploading = true);
      try {
        await Api.instance.deleteLogo();
        if (mounted) setState(() => picked = null);
        await _load();
      } on ApiError catch (e) {
        if (mounted) toast(context, e.message, bad: true);
      } finally {
        if (mounted) setState(() => uploading = false);
      }
      return;
    }

    Uint8List bytes;
    String name;
    // نسخة العرض على الويب بلا معرض صور؛ وعلى الهاتف تفتح المعرض كالحقيقية
    if (AppConfig.demo && kIsWeb) {
      bytes = (await rootBundle.load('assets/images/demo-avatar.jpg')).buffer.asUint8List();
      name = 'logo.jpg';
    } else {
      try {
        final image = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 600, imageQuality: 88);
        if (image == null) return;
        bytes = await image.readAsBytes();
        name = image.name.contains('.') ? image.name : '${image.name}.jpg';
      } on PlatformException {
        if (mounted) toast(context, 'تعذّر فتح الصور. اسمح للتطبيق بها من إعدادات الهاتف.', bad: true);
        return;
      }
    }
    setState(() => (picked = bytes, uploading = true));
    try {
      await Api.instance.uploadLogo(bytes, name);
      if (mounted) toast(context, 'حُفظت صورتك.');
      if (!AppConfig.demo) await _load();
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => picked = null);
        toast(context, e.message, bad: true);
      }
    } finally {
      if (mounted) setState(() => uploading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) return Waiting(brand: brand, error: error, onRetry: _load);

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.only(top: _top(context), bottom: 110),
        children: [
          // نسخة العرض على الهاتف تقول ذلك صراحةً: لا تُحسب على النظام
          if (AppConfig.demo && !kIsWeb) _pad(const DemoNote()),
          _header(d),
          const SizedBox(height: 10),
          _pad(_banner(d)),
          const SizedBox(height: 10),
          _pad(_balance(d)),
          const SizedBox(height: 10),
          _pad(_stats(d.stats)),
          const SizedBox(height: 10),
          _pad(_create()),
          const SizedBox(height: 10),
          _pad(_alerts(d)),
          const SizedBox(height: 10),
          _pad(_recent(d)),
        ],
      ),
    );
  }

  static double _top(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return top > 0 ? top + 6 : 32;
  }

  Widget _pad(Widget child) => Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: child);

  // ------------------------------------------------------------------ الترويسة

  Widget _header(HomeData d) {
    final hasLogo = picked != null || d.logo != null;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: [
          _avatar(d),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${baghdadGreeting()}، ${d.name}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(16, w8, Palette.ink, height: 1.3),
                ),
                Text(
                  hasLogo ? 'هنا ملخّص أعمالك اليوم' : 'اضغط الدائرة لتضع صورتك أو شعارك',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(12, w5, Palette.slate, height: 1.3),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          _bellButton(d.unread),
        ],
      ),
    );
  }

  Widget _avatar(HomeData d) {
    final Widget image;
    if (picked != null) {
      image = Image.memory(picked!, fit: BoxFit.cover);
    } else if (d.logo != null) {
      image = Image.network(
        d.logo!,
        headers: Api.instance.imageHeaders,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => _emptyLogo(),
      );
    } else {
      image = _emptyLogo();
    }

    return Tooltip(
      message: 'صورتك أو شعارك',
      child: Tap(
        radius: 24,
        onTap: uploading ? null : () => _logo(d),
        child: Container(
          width: 48,
          height: 48,
          padding: const EdgeInsets.all(2),
          decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
          child: ClipOval(
            child: uploading
                ? Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: brand.main),
                    ),
                  )
                : image,
          ),
        ),
      ),
    );
  }

  /// بلا صورة: دائرةٌ فارغة بأيقونة كاميرا — يضغطها فيضع صورته
  Widget _emptyLogo() => Container(
    color: brand.softer,
    alignment: Alignment.center,
    child: Icon(Icons.add_a_photo_outlined, size: 21, color: brand.main),
  );

  Widget _bellButton(int unread) {
    return Tooltip(
      message: 'الإشعارات',
      child: Tap(
        radius: 22,
        onTap: _bell,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
                child: const Icon(Icons.notifications_none_rounded, size: 24, color: Palette.ink),
              ),
              if (unread > 0)
                Positioned(
                  left: 27,
                  top: -2,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 18),
                    height: 18,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: brand.main,
                      borderRadius: BorderRadius.circular(9),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    child: Text(unread > 9 ? '9+' : '$unread', style: font(10.5, w8, Colors.white, height: 1)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ الإعلانات

  /// الصورة بنسبتها كما صُمّمت — لا تُقصّ على هاتفٍ أضيق — وتنزلق وحدها كلّ خمس ثوانٍ
  Widget _banner(HomeData d) => ClipRRect(
    borderRadius: BorderRadius.circular(16),
    child: AspectRatio(
      aspectRatio: 1095 / 336,
      child: _Ads(brand: brand, banners: d.banners, companyName: widget.companyName, onOpen: _open),
    ),
  );

  // ------------------------------------------------------------------ الرصيد

  Widget _balance(HomeData d) {
    // الصفّ العلويّ، وتحته «المتاح للسحب» و«قيد المطابقة» (docs/plan/49)
    return WhiteCard(
      child: Column(
        children: [
          _balanceTop(d),
          const Divider(height: 1, thickness: 1, indent: 12, endIndent: 12, color: Color(0xFFEEF1F5)),
          _balanceSplit(d),
        ],
      ),
    );
  }

  Widget _balanceTop(HomeData d) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
      child: Row(
        children: [
          SolidIcon(brand: brand, icon: Icons.account_balance_wallet_outlined, size: 42, iconSize: 23, radius: 12),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(d.owed ? 'إجمالي المستحقات' : 'عليك للشركة', style: font(12.5, w7, Palette.slate, height: 1.3)),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: Text(
                    hidden ? '••••••  د.ع' : '${money(d.balance)}  د.ع',
                    style: font(22, w8, d.owed ? brand.main : Palette.ink, height: 1.25),
                  ),
                ),
              ],
            ),
          ),
          Tooltip(
            message: hidden ? 'أظهر الرصيد' : 'أخفِ الرصيد',
            child: Tap(
              radius: 18,
              onTap: () => setState(() => hidden = !hidden),
              child: Container(
                width: 36,
                height: 36,
                decoration: const BoxDecoration(color: Palette.eye, shape: BoxShape.circle),
                child: Icon(
                  hidden ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                  size: 19,
                  color: Palette.ink,
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Tap(
            radius: 18,
            onTap: () => _open('finance'),
            child: Container(
              height: 36,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: brand.coral.withValues(alpha: .75), width: 1),
              ),
              child: Text('التفاصيل', style: font(12.5, w8, brand.main, height: 1)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _balanceSplit(HomeData d) {
    String shown(int v) => hidden ? '••••' : money(v);
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 9, 12, 10),
      child: Wrap(
        spacing: 12,
        runSpacing: 4,
        alignment: WrapAlignment.spaceBetween,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text.rich(
            TextSpan(
              text: 'المتاح للسحب ',
              style: font(12.5, w6, Palette.slate, height: 1.3),
              children: [
                TextSpan(text: shown(d.available), style: font(15, w8, const Color(0xFF15803D))),
                const TextSpan(text: ' د.ع'),
              ],
            ),
          ),
          if (d.pending > 0)
            Tooltip(
              message: d.pendingReason ?? '',
              triggerMode: TooltipTriggerMode.tap,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.schedule_rounded, size: 16, color: Color(0xFFB45309)),
                  const SizedBox(width: 4),
                  Text('${shown(d.pending)} قيد المطابقة', style: font(12.5, w8, const Color(0xFFB45309), height: 1.3)),
                ],
              ),
            ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ العدّادات

  Widget _stats(Stats s) {
    double of(int v) => s.total == 0 ? 0 : v / s.total;
    final tiles = [
      _stat('إجمالي الشحنات', s.total, of(s.delivered + s.returns), Icons.inventory_2_rounded, 'shipments'),
      _stat('مسلّمة', s.delivered, of(s.delivered), Icons.check_box_rounded, 'delivered'),
      _stat('قيد التوصيل', s.inDelivery, of(s.inDelivery), Icons.local_shipping_rounded, 'open'),
      _stat('راجع مؤكّدة', s.returns, of(s.returns), Icons.undo_rounded, 'returns'),
    ];
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < tiles.length; i++) ...[if (i > 0) const SizedBox(width: 6), Expanded(child: tiles[i])],
      ],
    );
  }

  Widget _stat(String label, int value, double ratio, IconData icon, String screen) {
    return Tap(
      radius: 14,
      onTap: () => _open('shipments:$screen'),
      child: WhiteCard(
        height: 118,
        radius: 14,
        padding: const EdgeInsets.fromLTRB(9, 9, 9, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SoftIcon(
              brand: brand,
              size: 30,
              radius: 9,
              child: Icon(icon, size: 18, color: brand.main),
            ),
            const SizedBox(height: 5),
            // الاسم الطويل ينكسر سطرين — لا يصغر حتى لا يُقرأ
            SizedBox(
              height: 32,
              child: Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: font(11.5, w7, Palette.ink, height: 1.35),
              ),
            ),
            const Spacer(),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: AlignmentDirectional.centerStart,
              child: Text(money(value), style: font(18, w8, Palette.ink, height: 1)),
            ),
            const SizedBox(height: 6),
            _Bar(ratio: ratio, brand: brand),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ إنشاء شحنة

  Widget _create() {
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(10, 12, 10, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text('إنشاء شحنة', style: font(15, w8, Palette.ink, height: 1.3)),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text('اختر الطريقة الأنسب لك', style: font(12, w5, Palette.slate, height: 1.3)),
          ),
          const SizedBox(height: 10),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: _option('إدخال يدوي', 'اكتب بياناته', _boxPlus(), 'create', highlighted: true)),
                const SizedBox(width: 6),
                Expanded(
                  child: _option(
                    'بالذكاء الاصطناعي',
                    'رسالة أو لقطة شاشة',
                    Icon(Icons.auto_awesome_rounded, size: 24, color: brand.main),
                    'create:ai',
                  ),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: _option(
                    'بالتسجيل الصوتي',
                    'قل بيانات الشحنة',
                    Icon(Icons.mic_rounded, size: 24, color: brand.main),
                    'create:voice',
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _boxPlus() => SizedBox(
    width: 26,
    height: 26,
    child: Stack(
      clipBehavior: Clip.none,
      children: [
        Icon(Icons.inventory_2_rounded, size: 23, color: brand.main),
        Positioned(
          right: -2,
          bottom: -1,
          child: Container(
            width: 13,
            height: 13,
            decoration: BoxDecoration(
              color: brand.main,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 1.2),
            ),
            child: const Icon(Icons.add_rounded, size: 10, color: Colors.white),
          ),
        ),
      ],
    ),
  );

  Widget _option(String title, String sub, Widget icon, String screen, {bool highlighted = false}) {
    return Tap(
      radius: 14,
      onTap: () => _open(screen),
      child: Container(
        padding: const EdgeInsets.fromLTRB(6, 10, 6, 10),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: highlighted ? brand.border : Palette.line, width: 1),
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: highlighted ? [brand.softer, brand.soft] : [Colors.white, const Color(0xFFFBFCFD)],
          ),
        ),
        child: Column(
          children: [
            SoftIcon(brand: brand, size: 42, radius: 12, child: icon),
            const SizedBox(height: 7),
            Text(title, textAlign: TextAlign.center, maxLines: 2, style: font(12.5, w8, Palette.ink, height: 1.3)),
            const SizedBox(height: 2),
            Text(sub, textAlign: TextAlign.center, maxLines: 2, style: font(11, w5, Palette.slate, height: 1.3)),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ للمعالجة / تحتاج انتباهك

  Widget _alerts(HomeData d) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _alert(
              'للمعالجة',
              d.processing,
              'لم يستلمها زبونك — اتّصل به وقرّر',
              Icons.schedule_rounded,
              'processing',
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _alert(
              'تحتاج انتباهك',
              d.attention,
              'تعثّرت — مكالمةٌ منك قد تحلّها',
              Icons.priority_high_rounded,
              'attention',
            ),
          ),
        ],
      ),
    );
  }

  Widget _alert(String title, int count, String text, IconData icon, String screen) {
    return Tap(
      radius: 16,
      onTap: () => _open(screen),
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 11, 12, 12),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(
            begin: Alignment.topRight,
            end: Alignment.bottomLeft,
            colors: [Color.lerp(Colors.white, brand.main, .07)!, Color.lerp(Colors.white, brand.main, .025)!],
          ),
          boxShadow: cardShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(title, style: font(13.5, w8, brand.main, height: 1.3))),
                SolidIcon(brand: brand, icon: icon, size: 28, iconSize: 17, radius: 8),
              ],
            ),
            Text(money(count), style: font(22, w8, Palette.ink, height: 1.25)),
            const SizedBox(height: 2),
            Text(text, style: font(11.5, w5, Palette.slate, height: 1.45)),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ آخر الشحنات

  Widget _recent(HomeData d) {
    return WhiteCard(
      padding: const EdgeInsets.only(top: 10, bottom: 4),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              children: [
                Expanded(child: Text('آخر الشحنات', style: font(15, w8, Palette.ink, height: 1.3))),
                Tap(
                  radius: 10,
                  onTap: () => _open('shipments'),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                    child: Row(
                      children: [
                        Text('عرض الكل', style: font(12.5, w8, brand.main, height: 1.2)),
                        const SizedBox(width: 4),
                        Chev(size: 8, color: brand.main, left: true, stroke: 1.7),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),
          if (d.recent.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
              child: Text(
                'لا شحنات بعد — أنشئ أوّل شحنة من الزرّ الأحمر.',
                textAlign: TextAlign.center,
                style: font(12.5, w5, Palette.slate),
              ),
            ),
          for (var i = 0; i < d.recent.length; i++) ...[
            const Divider(height: 1, thickness: .6, indent: 12, endIndent: 12, color: Color(0xFFE9EDF2)),
            _row(d.recent[i]),
          ],
        ],
      ),
    );
  }

  Widget _row(RecentShipment s) {
    return InkWell(
      onTap: s.id == 0 ? null : () => openShipment(context, brand, s.id),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    s.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(13.5, w7, Palette.ink, height: 1.3),
                  ),
                  Text(
                    '${s.area} · ${when(s.at)}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(11.5, w5, Palette.slate, height: 1.3),
                  ),
                  Text(
                    '#${s.number}',
                    textDirection: TextDirection.ltr,
                    style: font(11, w5, Palette.muted, height: 1.3),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text('${money(s.amount)} د.ع', style: font(14, w8, brand.main, height: 1.3)),
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    color: s.urgent ? brand.coral : Palette.pill,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(s.status, style: font(11, w8, Colors.white, height: 1.3)),
                ),
              ],
            ),
            const SizedBox(width: 8),
            const Chev(size: 8),
          ],
        ),
      ),
    );
  }
}

/// شريط النسبة تحت كل عدّاد
class _Bar extends StatelessWidget {
  const _Bar({required this.ratio, required this.brand});

  final double ratio;
  final Brand brand;

  @override
  Widget build(BuildContext context) => Container(
    height: 5,
    decoration: BoxDecoration(color: brand.track, borderRadius: BorderRadius.circular(3)),
    child: FractionallySizedBox(
      alignment: AlignmentDirectional.centerStart,
      widthFactor: ratio.clamp(0, 1).toDouble(),
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(colors: [brand.main, brand.coral]),
          borderRadius: BorderRadius.circular(3),
        ),
      ),
    ),
  );
}

/// الإعلانات تنزلق بالإصبع، وتتقلّب وحدها كلّ خمس ثوانٍ، ونقاطها أسفلها. إعلانات الشركة من
/// «إعلانات التطبيق» في النظام؛ وإلى أن ترفعها: صورة التوصيل وشريحتان بلونها.
class _Ads extends StatefulWidget {
  const _Ads({required this.brand, required this.banners, required this.companyName, required this.onOpen});

  final Brand brand;
  final List<AdBanner> banners;
  final String companyName;
  final void Function(String screen) onOpen;

  @override
  State<_Ads> createState() => _AdsState();
}

class _AdsState extends State<_Ads> {
  final controller = PageController();
  Timer? timer;
  int page = 0;

  List<Widget> get slides => widget.banners.isNotEmpty
      ? [
          for (final b in widget.banners)
            Image.network(
              b.image,
              headers: Api.instance.imageHeaders,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => Image.asset('assets/images/banner.jpg', fit: BoxFit.cover),
            ),
        ]
      : [
          Image.asset('assets/images/banner.jpg', fit: BoxFit.cover),
          _slide('اطلب مندوب استلام', 'مندوبنا يأتيك ويأخذ طرودك من باب محلّك', Icons.hail_rounded, 'pickups'),
          _slide(
            'تابع شحناتك لحظة بلحظة',
            'كلّ حالةٍ تصلك أوّلاً بأوّل، وأرسل التتبّع لزبونك',
            Icons.local_shipping_rounded,
            'shipments',
          ),
        ];

  @override
  void initState() {
    super.initState();
    // في الاختبار لا مؤقّت يبقى بعده
    if (!AppConfig.testing) {
      timer = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!mounted || !controller.hasClients) return;
        final next = (page + 1) % slides.length;
        controller.animateToPage(next, duration: const Duration(milliseconds: 450), curve: Curves.easeOut);
      });
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    controller.dispose();
    super.dispose();
  }

  Widget _slide(String title, String text, IconData icon, String screen) {
    final brand = widget.brand;
    return GestureDetector(
      onTap: () => widget.onOpen(screen),
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: AlignmentDirectional.centerStart,
            end: AlignmentDirectional.centerEnd,
            colors: [brand.main, brand.coral],
          ),
        ),
        padding: const EdgeInsets.fromLTRB(18, 12, 18, 22),
        child: Row(
          children: [
            Expanded(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(title, style: font(19, w8, Colors.white, height: 1.3)),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    text,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: font(12, w6, Colors.white.withValues(alpha: .92), height: 1.45),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Icon(icon, size: 54, color: Colors.white.withValues(alpha: .9)),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final all = slides;
    return Stack(
      children: [
        PageView(controller: controller, onPageChanged: (i) => setState(() => page = i), children: all),
        if (all.length > 1)
          PositionedDirectional(
            end: 16,
            bottom: 9,
            child: Row(
              children: [
                for (var i = 0; i < all.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    width: i == page ? 16 : 7,
                    height: 7,
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    decoration: BoxDecoration(
                      color: i == page ? widget.brand.main : Colors.white.withValues(alpha: .9),
                      borderRadius: BorderRadius.circular(4),
                      boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 3)],
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// «نسخة عرض»: بياناتٌ تجريبية، لا رفع ولا محادثات تصل الشركة
class DemoNote extends StatelessWidget {
  const DemoNote({super.key});

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 8),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    decoration: BoxDecoration(color: const Color(0xFFFFF4E5), borderRadius: BorderRadius.circular(12)),
    child: Text(
      'نسخة عرض ببياناتٍ تجريبية — غير مربوطة بالنظام. لا يصل منها شيءٌ للشركة.',
      style: font(12, w7, const Color(0xFF9A5B00), height: 1.5),
    ),
  );
}
