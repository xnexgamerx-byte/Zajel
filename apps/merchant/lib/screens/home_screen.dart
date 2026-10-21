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

/// رئيسية التاجر (تصميم ز، docs/plan/70): التحيّة بصورته، والإعلانات تنزلق، ورحلة شحناته محطّةً
/// محطّة، وإنشاء الطلب، و«للمعالجة» و«تحتاج انتباهك»، ثم آخر الشحنات. والمال في «المالية»، والأدوات
/// في «حسابي».
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
          const SizedBox(height: 12),
          _pad(_banner(d)),
          const SizedBox(height: 12),
          // تصميم ز (docs/plan/70): المال في «المالية» وحدها — والرئيسية رحلة الشحنات، ثم الإنشاء،
          // ثم «للمعالجة» بلونٍ لا يُغفل عنه، ثم «تحتاج انتباهك»
          _pad(_journey(d.stats)),
          const SizedBox(height: 14),
          _pad(_create()),
          const SizedBox(height: 14),
          _pad(_processing(d)),
          const SizedBox(height: 14),
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
                child: const Icon(Icons.notifications_none_rounded, size: 22, color: Palette.ink),
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

  /// أطول قليلاً من نسبة الصورة (1095×336): تملؤه وتُقصّ أطرافها، وتنزلق وحدها كلّ خمس ثوانٍ
  Widget _banner(HomeData d) => ClipRRect(
    borderRadius: BorderRadius.circular(18),
    child: AspectRatio(
      aspectRatio: 1095 / 400,
      child: _Ads(brand: brand, banners: d.banners, companyName: widget.companyName, onOpen: _open),
    ),
  );

  // ------------------------------------------------------------------ رحلة شحناتك

  /// المحطّات الأربع على خطٍّ واحد، لكلٍّ لونها: بانتظار مندوب كهرمانيّ، وبالمخزن أزرق، وقيد التوصيل بلون
  /// الشركة متوهّجاً (هي الآن)، والمسلَّم أخضر
  Widget _journey(Stats s) {
    final stations = [
      (s.waiting, 'بانتظار مندوب', Icons.inventory_2_rounded, 'shipments:open', _Station.waiting),
      (s.warehouse, 'بالمخزن', Icons.warehouse_rounded, 'shipments:open', _Station.warehouse),
      (s.courier, 'قيد التوصيل', Icons.local_shipping_rounded, 'shipments:open', _Station.courier(brand)),
      (s.deliveredToday, 'سُلّمت اليوم', Icons.task_alt_rounded, 'shipments:delivered', _Station.delivered),
    ];
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 14, 8, 12),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.white, Color(0xFFFFF9F8)],
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Palette.line),
        boxShadow: cardShadow,
      ),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(
              children: [
                Text('رحلة شحناتك الآن', style: display(16, w7, Palette.ink, height: 1.3)),
                const Spacer(),
                Text('${money(s.total)} شحنة إجمالاً', style: font(11.5, w6, Palette.muted)),
              ],
            ),
          ),
          const SizedBox(height: 12),
          LayoutBuilder(
            builder: (context, box) {
              final w = box.maxWidth;
              return Stack(
                children: [
                  // الخطّ من أوّل محطّةٍ إلى آخرها، ومملوءٌ حتى المحطّة الحاليّة
                  PositionedDirectional(
                    start: w / 8,
                    end: w / 8,
                    top: _Station.size / 2 - 2,
                    height: 4,
                    child: Container(
                      decoration: BoxDecoration(color: const Color(0xFFF1E6E4), borderRadius: BorderRadius.circular(2)),
                    ),
                  ),
                  // كلّ قطعةٍ بلون المحطّة التي تصل إليها
                  for (final (i, color) in [_Station.warehouse.light, brand.main.withValues(alpha: .55)].indexed)
                    PositionedDirectional(
                      start: w / 8 + i * w / 4,
                      width: w / 4,
                      top: _Station.size / 2 - 2,
                      height: 4,
                      child: ColoredBox(color: color),
                    ),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final (count, label, icon, screen, station) in stations)
                        Expanded(child: _station(count, label, icon, screen, station)),
                    ],
                  ),
                ],
              );
            },
          ),
        ],
      ),
    );
  }

  Widget _station(int count, String label, IconData icon, String screen, _Station station) {
    final now = station.now;
    return Tap(
      radius: 16,
      onTap: () => _open(screen),
      child: Padding(
        padding: const EdgeInsets.only(bottom: 2),
        child: Column(
          children: [
            Container(
              width: _Station.size,
              height: _Station.size,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [station.light, station.main],
                ),
                border: Border.all(color: Colors.white, width: 3),
                boxShadow: [
                  if (now) BoxShadow(color: station.main.withValues(alpha: .16), spreadRadius: 4),
                  BoxShadow(
                    color: station.main.withValues(alpha: .45),
                    blurRadius: 12,
                    spreadRadius: -4,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Icon(
                icon,
                size: 20,
                color: Colors.white,
                shadows: [Shadow(color: station.deep.withValues(alpha: .5), blurRadius: 3, offset: const Offset(0, 1))],
              ),
            ),
            const SizedBox(height: 7),
            Text(money(count), style: display(19, w7, station.deep, height: 1)),
            const SizedBox(height: 3),
            Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              style: font(11, now ? w8 : w6, now ? Palette.ink : Palette.slate, height: 1.3),
            ),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ أنشئ طلباً

  /// زرٌّ أحمر كبير للإدخال، وتحته ثلاث بطاقات: من صورة، وبصوتك، واطلب استلام — ثم طباعة الوصولات
  Widget _create() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Tap(
          shadow: brand.glow(.6),
          radius: 22,
          onTap: () => _open('create'),
          child: Ink(
            height: 68,
            decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(22)),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(22),
              child: Stack(
                children: [
                  PositionedDirectional(end: 30, top: -50, child: _halo(120, .08)),
                  PositionedDirectional(end: -36, bottom: -64, child: _halo(120, .06)),
                  Padding(
                    padding: const EdgeInsetsDirectional.fromSTEB(18, 0, 11, 0),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('طلب جديد', style: display(19, w7, Colors.white, height: 1.25)),
                              Text(
                                'الزبون والمبلغ والعنوان بخطوةٍ واحدة',
                                style: font(11.5, w6, Colors.white.withValues(alpha: .88)),
                              ),
                            ],
                          ),
                        ),
                        Container(
                          width: 42,
                          height: 42,
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(14),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x80280004),
                                blurRadius: 16,
                                spreadRadius: -6,
                                offset: Offset(0, 8),
                              ),
                            ],
                          ),
                          child: Icon(Icons.add_rounded, size: 24, color: brand.main),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 10),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: _option('من صورة', 'لقطة أو رسالة', Icons.photo_camera_rounded, 'create:ai')),
              const SizedBox(width: 8),
              Expanded(child: _option('بصوتك', 'قلها وتتعبّى', Icons.mic_rounded, 'create:voice')),
              const SizedBox(width: 8),
              Expanded(child: _option('اطلب استلام', 'مندوب يمرّ عليك', Icons.hail_rounded, 'pickups')),
            ],
          ),
        ),
        const SizedBox(height: 10),
        // طباعة الوصولات (docs/plan/63): ظاهرةٌ تحت الإنشاء — ما يُدخل يُطبع ويُلصق قبل أن يأتي المندوب
        Tap(
          radius: 16,
          onTap: () => _open('labels'),
          child: Ink(
            height: 48,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Palette.line),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.print_rounded, size: 19, color: brand.deep),
                const SizedBox(width: 8),
                Text('طباعة وصولات الطلبات المدخلة', style: font(13.5, w8, Palette.ink)),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _halo(double size, double alpha) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      shape: BoxShape.circle,
      color: Colors.white.withValues(alpha: alpha),
    ),
  );

  Widget _option(String title, String sub, IconData icon, String screen) {
    return Tap(
      shadow: cardShadow,
      radius: 22,
      onTap: () => _open(screen),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(6, 12, 6, 10),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Colors.white, Color(0xFFFFF8F7)],
          ),
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: Palette.line),
        ),
        child: Column(
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color.lerp(Colors.white, brand.main, .07)!, Color.lerp(Colors.white, brand.main, .22)!],
                ),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: brand.main.withValues(alpha: .10)),
                boxShadow: [
                  BoxShadow(
                    color: brand.main.withValues(alpha: .35),
                    blurRadius: 12,
                    spreadRadius: -6,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Icon(icon, size: 21, color: brand.deep),
            ),
            const SizedBox(height: 7),
            Text(title, textAlign: TextAlign.center, maxLines: 1, style: font(13, w8, Palette.ink, height: 1.3)),
            Text(sub, textAlign: TextAlign.center, maxLines: 2, style: font(11, w5, Palette.muted, height: 1.3)),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ للمعالجة

  /// بطاقةٌ داكنة بشريطٍ مخطّط كشريط التحذير: لا يُغفل عنها، وزرّها يفتح القرار
  Widget _processing(HomeData d) {
    final n = d.processing;
    return Tap(
      shadow: const [BoxShadow(color: Color(0x6626181A), blurRadius: 24, spreadRadius: -10, offset: Offset(0, 12))],
      radius: 22,
      onTap: n == 0 ? null : () => _open('processing'),
      child: Ink(
        decoration: BoxDecoration(color: Palette.ink, borderRadius: BorderRadius.circular(22)),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(22),
          child: Column(
            children: [
              SizedBox(
                height: 8,
                width: double.infinity,
                child: CustomPaint(painter: _Stripes(brand.main)),
              ),
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 14, 14),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('للمعالجة', style: font(12, w8, Color.lerp(brand.coral, Colors.white, .35)!)),
                          Text(
                            n == 0 ? 'لا شيء ينتظر قرارك' : '${money(n)} لم يستلمها زبونها',
                            style: display(18, w7, Colors.white, height: 1.35),
                          ),
                          Text(
                            n == 0 ? 'كلّ ما تعثّر عولج.' : 'اتّصل به وقرّر: إعادة توصيل أو تأجيل أو راجع',
                            style: font(12, w5, Colors.white.withValues(alpha: .8), height: 1.4),
                          ),
                        ],
                      ),
                    ),
                    if (n > 0) ...[
                      const SizedBox(width: 10),
                      Container(
                        height: 44,
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(14)),
                        child: Text('عالجها', style: font(14, w8, Colors.white)),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ تحتاج انتباهك · آخر الشحنات

  /// «تحتاج انتباهك» أوّلاً حين يكون فيها شيء — هي الأهمّ (docs/plan/61) — وزرّان يبدّلان بينهما
  bool? showRecent;

  Widget _switch(HomeData d, bool recent) {
    Widget chip(String label, bool on, VoidCallback tap) => Tap(
      radius: 20,
      onTap: tap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: on ? brand.main : const Color(0xFFF5ECEA),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(label, style: font(12.5, w8, on ? Colors.white : Palette.slate, height: 1.2)),
      ),
    );
    return Row(
      children: [
        chip(
          'تحتاج انتباهك${d.attentionItems.isEmpty ? '' : ' (${d.attentionItems.length})'}',
          !recent,
          () => setState(() => showRecent = false),
        ),
        const SizedBox(width: 6),
        chip('آخر الشحنات', recent, () => setState(() => showRecent = true)),
      ],
    );
  }

  Widget _recent(HomeData d) {
    final recent = showRecent ?? d.attentionItems.isEmpty;
    return WhiteCard(
      padding: const EdgeInsets.only(top: 10, bottom: 4),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              children: [
                Expanded(child: _switch(d, recent)),
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
          if (!recent) ...[
            if (d.attentionItems.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
                child: Text(
                  'لا شيء يحتاج انتباهك الآن.',
                  textAlign: TextAlign.center,
                  style: font(12.5, w5, Palette.slate),
                ),
              ),
            for (final item in d.attentionItems) ...[
              const Divider(height: 1, thickness: .6, indent: 12, endIndent: 12, color: Color(0xFFF0E3E1)),
              _row(item.row, detail: item),
            ],
          ] else if (d.recent.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
              child: Text(
                'لا شحنات بعد — أنشئ أوّل شحنة من الزرّ الأحمر.',
                textAlign: TextAlign.center,
                style: font(12.5, w5, Palette.slate),
              ),
            ),
          if (recent)
            for (var i = 0; i < d.recent.length; i++) ...[
              const Divider(height: 1, thickness: .6, indent: 12, endIndent: 12, color: Color(0xFFF0E3E1)),
              _row(d.recent[i]),
            ],
        ],
      ),
    );
  }

  Widget _row(RecentShipment s, {AttentionItem? detail}) {
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
                  Text(s.place, style: font(12, w7, Palette.ink, height: 1.35)),
                  Text(when(s.at), style: font(11, w5, Palette.slate, height: 1.3)),
                  Text(
                    '#${s.number}',
                    textDirection: TextDirection.ltr,
                    style: font(11, w5, Palette.muted, height: 1.3),
                  ),
                  // لماذا تحتاج انتباهه، وكم حاولنا، وإلى متى أُجّلت
                  if (detail != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 3),
                      child: Text(
                        [
                          if (detail.reason != null) detail.reason!,
                          if (detail.attempts > 0) 'محاولات: ${detail.attempts}',
                          if (detail.until != null) 'مؤجّلة إلى ${when(detail.until)}',
                        ].join(' · '),
                        style: font(11.5, w7, brand.main, height: 1.4),
                      ),
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

/// لون محطّةٍ في «رحلة شحناتك»: فاتحٌ أعلى الدائرة، وأصليٌّ أسفلها، وداكنٌ لرقمها
class _Station {
  const _Station(this.light, this.main, this.deep) : now = false;

  _Station.courier(Brand brand)
    : light = Color.lerp(brand.main, Colors.white, .22)!,
      main = brand.main,
      deep = brand.deep,
      now = true;

  static const size = 46.0;
  static const waiting = _Station(Color(0xFFF5B54A), Color(0xFFD9870B), Color(0xFFA9650A));
  static const warehouse = _Station(Color(0xFF6AA2E0), Color(0xFF2F6DB5), Color(0xFF22528A));
  static const delivered = _Station(Color(0xFF5CC07A), Color(0xFF239048), Color(0xFF1A6B35));

  final Color light;
  final Color main;
  final Color deep;

  /// المحطّة التي فيها أكثر شحناته الآن: بهالةٍ حولها
  final bool now;
}

/// شريط التحذير المخطّط فوق «للمعالجة»
class _Stripes extends CustomPainter {
  _Stripes(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = Palette.ink);
    final paint = Paint()..color = color;
    for (var x = -size.height; x < size.width + size.height; x += 20) {
      canvas.drawPath(
        Path()
          ..moveTo(x, size.height)
          ..lineTo(x + 10, size.height)
          ..lineTo(x + 10 + size.height, 0)
          ..lineTo(x + size.height, 0)
          ..close(),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _Stripes old) => old.color != color;
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
