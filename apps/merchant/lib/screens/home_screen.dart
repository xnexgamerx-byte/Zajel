import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

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
import 'calendar_screen.dart';
import 'notifications_screen.dart';
import 'processing_screen.dart';
import 'states_screen.dart';

/// «صباح الخير» حتى الظهر بتوقيت بغداد، ثم «مساء الخير» — أيّاً كانت ساعة الهاتف (docs/plan/59)
String baghdadGreeting([DateTime? now]) =>
    (now ?? DateTime.now()).toUtc().add(const Duration(hours: 3)).hour < 12 ? 'صباح الخير' : 'مساء الخير';

/// رئيسية التاجر في «وصل» (docs/plan/123): التحيّة واسم المتجر والدعم الفني والجرس، ثم الإعلانات، ثم «شحناتك
/// الآن» بستّ بطاقاتٍ برسومها (الطابعة أوّلاً) و«المزيد» لكلّ الحالات، ثم «تحتاج انتباهك»: رزمة ما ينتظر قراره
/// يقرّر فيها من مكانها، وبطاقتا «سُلّمت اليوم» و«إجمالي الشحنات»، وشريط «عالجها». وتقويم الشحنات في آخرها
/// (docs/plan/87).
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

  /// رزمة «تنتظر قرارك»: أوّل ما لم يُسلَّم، يقرّر فيه من الرئيسية
  List<ProcessingItem> deck = [];

  /// الصورة كما اختارها الآن — تظهر قبل أن يعود رابطها من النظام
  Uint8List? picked;
  bool uploading = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh?.addListener(_load);
    Access.changed.addListener(_access);
    _load();
  }

  @override
  void dispose() {
    widget.refresh?.removeListener(_load);
    Access.changed.removeListener(_access);
    super.dispose();
  }

  /// تغيّر ما يفتحه الحساب (docs/plan/87): تُرسم الرئيسية بما فُتح
  void _access() {
    if (mounted) setState(() {});
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.home();
      // ما يفتحه الحساب الآن (docs/plan/87): صاحب المتجر قد غيّره منذ الدخول
      Access.set(d.permissions, staff: d.staffTool);
      var items = <ProcessingItem>[];
      if (d.canProcess && d.processing > 0 && Access.can('processing')) {
        try {
          items = (await Api.instance.processing()).items;
        } on ApiError {
          // تبقى الرئيسية بلا الرزمة، وشريط «عالجها» يفتح القائمة
        }
      }
      if (mounted) setState(() => (data = d, error = null, deck = items));
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
    final tiles = _tiles(d);
    final processing = d.processing > 0 && Access.can('processing');

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.only(top: _top(context), bottom: 120),
        children: [
          // نسخة العرض على الهاتف تقول ذلك صراحةً: لا تُحسب على النظام
          if (AppConfig.demo && !kIsWeb) _pad(const DemoNote()),
          _pad(_header(d)),
          const SizedBox(height: 16),
          _pad(
            Rise(
              delay: 60,
              child: _Ads(brand: brand, banners: d.banners, onOpen: _open),
            ),
          ),
          if (tiles.isNotEmpty) ...[
            const SizedBox(height: 24),
            _pad(
              Rise(
                delay: 140,
                child: SectionHead(
                  'شحناتك الآن',
                  note: d.stats == null ? null : _count(d.stats!.total),
                  action: d.stats == null ? null : 'المزيد',
                  actionColor: brand.main,
                  onAction: () => openStates(context, brand).then((_) => _load()),
                ),
              ),
            ),
            const SizedBox(height: 12),
            _pad(_grid(tiles)),
          ],
          // ما ينتظر قراره، وأرقام اليوم (docs/plan/123)
          if (d.stats != null || processing) ...[
            const SizedBox(height: 24),
            _pad(
              Rise(
                delay: 520,
                child: SectionHead(
                  'تحتاج انتباهك',
                  badge: processing ? CountBadge(brand: brand, count: d.processing) : null,
                ),
              ),
            ),
            const SizedBox(height: 12),
            if (d.canProcess && processing) ...[_pad(_deck()), const SizedBox(height: 12)],
            if (d.stats != null) ...[_pad(_stats(d.stats!)), const SizedBox(height: 12)],
            if (processing) _pad(Rise(delay: 700, child: _attn(d))),
          ],
          if (d.stats != null) ...[
            const SizedBox(height: 18),
            // تقويم الشحنات وأداؤها (docs/plan/87): بطاقةٌ صغيرة بآخر سبعة أيام، وتفتح التقويم كلّه
            _pad(CalendarCard(brand: brand, refresh: widget.refresh)),
          ],
        ],
      ),
    );
  }

  static double _top(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return top > 0 ? top + 12 : 36;
  }

  Widget _pad(Widget child) => Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: child);

  /// «30 شحنة» بتمييزها العربي
  static String _count(int n) => switch (n) {
    0 => 'لا شحنات',
    1 => 'شحنة واحدة',
    2 => 'شحنتان',
    >= 3 && <= 10 => '$n شحنات',
    _ => '${money(n)} شحنة',
  };

  // ------------------------------------------------------------------ الترويسة

  /// صورة المتجر أو أوّل حرفٍ من اسمه، والتحيّة واسمه، ثم «الدعم الفني» والجرس
  Widget _header(HomeData d) => Row(
    children: [
      _avatar(d),
      const SizedBox(width: 8),
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(baghdadGreeting(), maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(12, Palette.muted)),
            Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(16, Palette.ink, bold: true)),
          ],
        ),
      ),
      const SizedBox(width: 8),
      // الدعم الفني مكان البحث (docs/plan/79، 123): البحث في «الشحنات»
      if (Access.can('support')) ...[
        Tooltip(
          message: 'الدعم الفني',
          child: Tap(
            radius: 14,
            onTap: () => _open('support'),
            child: Container(
              height: 44,
              padding: const EdgeInsets.symmetric(horizontal: 10),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Palette.line),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.headset_mic_outlined, size: 16, color: brand.main),
                  const SizedBox(width: 6),
                  Text('الدعم الفني', style: kf(14, Palette.ink, bold: true, height: 1.3)),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(width: 6),
      ],
      _bellButton(d.unread),
    ],
  );

  /// صورته أو شعاره (يضعها صاحب المتجر بلمسة)، أو أوّل حرفٍ من اسم المتجر على لون الشركة
  Widget _avatar(HomeData d) {
    final Widget image;
    if (picked != null) {
      image = Image.memory(picked!, fit: BoxFit.cover);
    } else if (d.logo != null) {
      image = Image.network(
        d.logo!,
        headers: Api.instance.imageHeaders,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => _initial(d),
      );
    } else {
      image = _initial(d);
    }
    return Tooltip(
      message: 'صورتك أو شعارك',
      child: Tap(
        radius: 12,
        // صورة المتجر لصاحبه وحده (docs/plan/87)
        onTap: uploading || !Access.isOwner ? null : () => _logo(d),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: SizedBox(
            width: 40,
            height: 40,
            child: uploading
                ? ColoredBox(
                    color: brand.soft,
                    child: Center(
                      child: SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: brand.main),
                      ),
                    ),
                  )
                : image,
          ),
        ),
      ),
    );
  }

  Widget _initial(HomeData d) => ColoredBox(
    color: brand.main,
    child: Center(
      child: Text(d.name.isEmpty ? '؟' : d.name.characters.first, style: kf(16, Colors.white, bold: true, height: 1)),
    ),
  );

  Widget _bellButton(int unread) => SquareButton(
    tip: unread > 0 ? 'الإشعارات، فيها جديد' : 'الإشعارات',
    onTap: _bell,
    child: Stack(
      clipBehavior: Clip.none,
      children: [
        const Icon(Icons.notifications_none_rounded, size: 19, color: Palette.ink),
        if (unread > 0)
          PositionedDirectional(
            end: -6,
            top: -6,
            child: Container(
              constraints: const BoxConstraints(minWidth: 18),
              height: 18,
              padding: const EdgeInsets.symmetric(horizontal: 4),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: brand.main,
                borderRadius: BorderRadius.circular(9),
                border: Border.all(color: Colors.white, width: 2),
              ),
              child: Text(unread > 9 ? '9+' : '$unread', style: kf(10, Colors.white, bold: true, height: 1)),
            ),
          ),
      ],
    ),
  );

  // ------------------------------------------------------------------ شحناتك الآن

  /// البطاقات الستّ بما يفتحه الحساب (docs/plan/87): الطابعة أوّلاً بلون الشركة، ثم الحالات بألوانها
  List<_TileSpec> _tiles(HomeData d) {
    final s = d.stats;
    return [
      if (Access.can('print'))
        _TileSpec(
          'printer',
          'الطابعة',
          d.toPrint,
          brand.soft,
          brand.main,
          'labels',
          semantics: 'الطابعة: ${d.toPrint == 0 ? 'لا وصولات' : money(d.toPrint)} للطباعة',
        ),
      if (s != null) ...[
        _TileSpec('warehouse', 'بالمخزن', s.warehouse, Tone.teal.tint, Tone.teal.ink, 'status:warehouse'),
        _TileSpec('courier', 'مع المندوب', s.courier, Tone.blue.tint, Tone.blue.ink, 'status:courier'),
      ],
      if (Access.can('processing'))
        _TileSpec('processing', 'للمعالجة', d.processing, Tone.orange.tint, Tone.orange.ink, 'processing'),
      if (s != null) ...[
        _TileSpec('returnsAll', 'راجعة', s.returning, Tone.red.tint, Tone.red.ink, 'status:returning'),
        _TileSpec('delivered', 'مسلّمة', s.delivered, Tone.green.tint, Tone.green.ink, 'status:delivered'),
      ],
    ];
  }

  Widget _grid(List<_TileSpec> tiles) => Column(
    children: [
      for (var r = 0; r < tiles.length; r += 3) ...[
        if (r > 0) const SizedBox(height: 12),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var i = r; i < r + 3; i++) ...[
                if (i > r) const SizedBox(width: 12),
                Expanded(
                  child: i < tiles.length
                      ? Rise(delay: 200 + i * 60, child: _tile(tiles[i], i))
                      : const SizedBox.shrink(),
                ),
              ],
            ],
          ),
        ),
      ],
    ],
  );

  /// بطاقة حالة: رسمها على هالةٍ بيضاء، ثم عددها، ثم اسمها بلونها
  Widget _tile(_TileSpec t, int i) => Semantics(
    button: true,
    label: t.semantics ?? '${t.label}: ${money(t.count)}',
    excludeSemantics: true,
    onTap: () => _open(t.screen),
    child: Tap(
      radius: 24,
      onTap: () => _open(t.screen),
      child: Ink(
        decoration: BoxDecoration(color: t.tint, borderRadius: BorderRadius.circular(24)),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 124),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(6, 10, 6, 12),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                SizedBox(
                  width: 64,
                  height: 56,
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      Positioned.fill(
                        top: 6,
                        left: 4,
                        right: 4,
                        child: const DecoratedBox(
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            gradient: RadialGradient(colors: [Color(0xF2FFFFFF), Color(0x00FFFFFF)]),
                          ),
                        ),
                      ),
                      Rise(
                        delay: 320 + i * 60,
                        scale: true,
                        child: Art(t.art, brand: brand, width: 64, height: 56),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 4),
                _CountUp(value: t.count, style: kf(20, Palette.ink, bold: true, height: 1.3)),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(t.label, maxLines: 1, style: kf(12, t.ink, bold: true, height: 1.35)),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );

  // ------------------------------------------------------------------ تحتاج انتباهك

  /// رزمة «تنتظر قرارك»: أوّل شحنةٍ لم تُسلَّم بسببها ومحاولاتها، وخلفها ظلّ ما بعدها. يقرّر فيها من هنا:
  /// إعادة توصيل، أو تأجيلٌ بموعده، أو إرجاع — بنافذة القرار نفسها التي في «للمعالجة»
  Widget _deck() {
    if (deck.isEmpty) {
      return Rise(
        delay: 560,
        child: WhiteCard(
          radius: 24,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
          child: Row(
            children: [
              const Icon(Icons.check_circle_outline_rounded, size: 34, color: Palette.ok),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('أنهيت قراراتك', style: kf(16, Palette.ink, bold: true)),
                    Text('لا شحنة تنتظرك الآن', style: kf(14, Palette.muted)),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }
    final item = deck.first;
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          if (deck.length >= 3)
            PositionedDirectional(
              start: 28,
              end: 28,
              bottom: -16,
              height: 48,
              child: Opacity(
                opacity: .5,
                child: DecoratedBox(
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
                ),
              ),
            ),
          if (deck.length >= 2)
            PositionedDirectional(
              start: 14,
              end: 14,
              bottom: -8,
              height: 48,
              child: Opacity(
                opacity: .8,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(22),
                    boxShadow: const [
                      BoxShadow(color: Color(0x591C1416), blurRadius: 20, spreadRadius: -14, offset: Offset(0, 8)),
                    ],
                  ),
                ),
              ),
            ),
          Rise(key: ValueKey(item.row.id), delay: 0, child: _deckCard(item)),
        ],
      ),
    );
  }

  Widget _deckCard(ProcessingItem item) {
    final s = item.row;
    final waited = item.waiting >= 24
        ? 'منذ ${_plural(item.waiting ~/ 24, 'يوم', 'يومين', 'أيام', 'يوماً')}'
        : 'منذ ${_plural(item.waiting, 'ساعة', 'ساعتين', 'ساعات', 'ساعة')}';
    final tries = item.attempts <= 1 ? 'محاولة واحدة' : (item.attempts == 2 ? 'محاولتان' : '${item.attempts} محاولات');
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        boxShadow: const [
          BoxShadow(color: Color(0x0D1C1416), blurRadius: 2, offset: Offset(0, 1)),
          BoxShadow(color: Color(0x661C1416), blurRadius: 36, spreadRadius: -22, offset: Offset(0, 18)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (item.reason != null)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                        decoration: BoxDecoration(color: Tone.orange.tint, borderRadius: BorderRadius.circular(12)),
                        child: Text(item.reason!, style: kf(12, Tone.orange.ink, bold: true)),
                      ),
                    const SizedBox(height: 4),
                    Text('$tries، $waited', style: kf(12, Palette.muted)),
                  ],
                ),
              ),
              if (item.phone.isNotEmpty)
                Tooltip(
                  message: 'اتصل بالزبون',
                  child: Tap(
                    radius: 22,
                    onTap: () => launchUrl(Uri(scheme: 'tel', path: item.phone)),
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: Palette.line),
                      ),
                      child: const Icon(Icons.call_outlined, size: 18, color: Palette.ink),
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(16, Palette.ink, bold: true)),
                    Text(s.place, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(14, Palette.muted)),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(money(s.amount), style: kf(16, Palette.ink, bold: true)),
                  Text('وصل \u2066${s.number}\u2069', style: kf(12, Palette.muted)),
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _decision('أعد التوصيل', item, 'redeliver', fill: brand.main, ink: Colors.white),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _decision('أجّلها', item, 'postpone', fill: brand.soft, ink: brand.main),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _decision('أرجعها', item, 'return', fill: Colors.white, ink: Palette.ink, line: true),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _decision(
    String label,
    ProcessingItem item,
    String action, {
    required Color fill,
    required Color ink,
    bool line = false,
  }) => Tap(
    radius: 14,
    onTap: () => _decide(item, action),
    child: Container(
      height: 44,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(14),
        border: line ? Border.all(color: const Color(0xFFDCD4D8)) : null,
      ),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Text(label, maxLines: 1, style: kf(14, ink, bold: true, height: 1.3)),
      ),
    ),
  );

  /// القرار بنافذة «للمعالجة» نفسها: ما قاله الزبون وموعد التأجيل، ثم «أكّد». بعده تذهب البطاقة وتأتي التي بعدها
  Future<void> _decide(ProcessingItem item, String action) async {
    final result = await decide(context, brand, item, action);
    if (result == null || !mounted) return;
    toast(context, result);
    Seen.open(item.row.id);
    setState(
      () => deck = [
        for (final i in deck)
          if (i.row.id != item.row.id) i,
      ],
    );
    _load();
  }

  /// «سُلّمت اليوم» و«إجمالي الشحنات»: الرقم، واسمه، وأعمدةٌ تمتلئ بنسبةٍ حقيقيّة، وسطرٌ يقول ما وراءه
  Widget _stats(Stats s) {
    final out = s.deliveredToday + s.courier;
    final done = s.delivered + s.returning;
    final rate = done == 0 ? null : (s.delivered * 100 / done).round();
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: Rise(
              delay: 600,
              child: _stat(
                'سُلّمت اليوم',
                s.deliveredToday,
                Icons.local_shipping_outlined,
                out == 0 ? 0 : s.deliveredToday / out,
                s.courier == 0 ? 'لا شيء مع المندوب' : '${money(s.courier)} مع المندوب الآن',
                'shipments:delivered',
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Rise(
              delay: 660,
              child: _stat(
                'إجمالي الشحنات',
                s.total,
                Icons.inventory_2_outlined,
                (rate ?? 0) / 100,
                rate == null ? 'لا شحنات منتهية بعد' : 'نسبة التسليم \u2066$rate٪\u2069',
                'shipments',
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(String label, int value, IconData icon, double fill, String note, String screen) => Tap(
    radius: 20,
    shadow: cardShadow,
    onTap: () => _open(screen),
    child: Ink(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(11)),
                child: Icon(icon, size: 16, color: brand.main),
              ),
              const Spacer(),
              Flexible(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: _CountUp(value: value, style: kf(20, Palette.ink, bold: true, height: 1.3)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          // سطرٌ واحد لكلٍّ منهما، ويصغر الخطّ قليلاً إن ضاق الهاتف
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(label, maxLines: 1, style: kf(13, Palette.ink, bold: true)),
          ),
          const SizedBox(height: 6),
          _Bars(fill: fill, brand: brand),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(note, maxLines: 1, style: kf(12, Palette.slate)),
          ),
        ],
      ),
    ),
  );

  /// «N لم يستلمها زبونها — قرّر فيها» بلون الشركة، وزرّ «عالجها» يفتح «للمعالجة»
  Widget _attn(HomeData d) => Container(
    padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 12, 12),
    decoration: BoxDecoration(
      color: brand.main,
      borderRadius: BorderRadius.circular(20),
      boxShadow: [BoxShadow(color: brand.halo, blurRadius: 24, spreadRadius: -14, offset: const Offset(0, 14))],
    ),
    child: Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(color: Colors.white.withValues(alpha: .16), shape: BoxShape.circle),
          child: const Icon(Icons.headset_mic_outlined, size: 20, color: Colors.white),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Text('${money(d.processing)} لم يستلمها زبونها — قرّر فيها', style: kf(14, Colors.white, bold: true)),
        ),
        const SizedBox(width: 8),
        Tap(
          radius: 22,
          onTap: () => _open('processing'),
          child: Container(
            height: 44,
            padding: const EdgeInsets.symmetric(horizontal: 18),
            alignment: Alignment.center,
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22)),
            child: Text('عالجها', style: kf(14, brand.main, bold: true, height: 1.3)),
          ),
        ),
      ],
    ),
  );
}

/// العدد بتمييزه العربي: ١ «ساعة» · ٢ «ساعتين» · ٣–١٠ «ساعات» · ١١+ «ساعة»
String _plural(int n, String one, String two, String few, String many) => switch (n) {
  1 => one,
  2 => two,
  >= 3 && <= 10 => '$n $few',
  _ => '$n $many',
};

/// بطاقةٌ من «شحناتك الآن»
class _TileSpec {
  const _TileSpec(this.art, this.label, this.count, this.tint, this.ink, this.screen, {this.semantics});

  final String art;
  final String label;
  final int count;
  final Color tint;
  final Color ink;
  final String screen;
  final String? semantics;
}

/// الرقم يعدّ من الصفر إليه حين يظهر (docs/plan/123)، وبلا حركةٍ لمن أطفأها
class _CountUp extends StatelessWidget {
  const _CountUp({required this.value, required this.style});

  final int value;
  final TextStyle style;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) return Text(money(value), style: style);
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: value.toDouble()),
      duration: const Duration(milliseconds: 900),
      curve: Curves.easeOutCubic,
      builder: (_, v, _) => Text(money(v.round()), style: style),
    );
  }
}

/// ستّة أعمدةٍ صغيرة تمتلئ بلون الشركة بنسبةٍ حقيقيّة، وما بقي فاتح
class _Bars extends StatelessWidget {
  const _Bars({required this.fill, required this.brand});

  final double fill;
  final Brand brand;

  static const _n = 6;

  @override
  Widget build(BuildContext context) {
    final on = (fill.clamp(0, 1) * _n).round();
    return SizedBox(
      height: 24,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        textDirection: TextDirection.ltr,
        children: [
          for (var i = 0; i < _n; i++) ...[
            if (i > 0) const SizedBox(width: 6),
            Container(
              width: 12,
              height: 6 + i * 3.6,
              decoration: BoxDecoration(
                color: i < on ? brand.main : brand.track,
                borderRadius: BorderRadius.circular(3),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// الإعلانات (docs/plan/93، 123): ما ترفعه الشركة صورةً تملأ البطاقة وتفتح رابطها، وبلاها ثلاث شرائح برسوم «وصل»
/// عمّا في التطبيق نفسه. تنزلق وحدها كلّ خمس ثوانٍ، وتحتها شرائط تمتلئ بالوقت
class _Ads extends StatefulWidget {
  const _Ads({required this.brand, required this.banners, required this.onOpen});

  final Brand brand;
  final List<AdBanner> banners;
  final void Function(String screen) onOpen;

  @override
  State<_Ads> createState() => _AdsState();
}

class _AdsState extends State<_Ads> {
  final controller = PageController();
  Timer? timer;
  int page = 0;

  Brand get brand => widget.brand;

  int get _length => widget.banners.isNotEmpty ? widget.banners.length : _slides.length;

  /// ما في التطبيق نفسه، بلا وعدٍ لا تعرفه الشركة: لا سعر ولا عرض
  List<(String, String, String, String, Color)> get _slides => [
    ('مندوبٌ يمرّ عليك', 'اطلب الاستلام، ويأخذ طرودك من باب محلّك', 'ad_van', 'pickups', brand.night),
    (
      'تابع شحناتك لحظة بلحظة',
      'كلّ حالةٍ تصلك أوّلاً بأوّل، وأرسل التتبّع لزبونك',
      'ad_stack',
      'shipments',
      Palette.ink,
    ),
    ('اطبع وصولاتك', 'من هاتفك إلى طابعتك مباشرة، أو ملفّاً تشاركه', 'ad_printer', 'labels', brand.main),
  ];

  @override
  void initState() {
    super.initState();
    // في الاختبار لا مؤقّت يبقى بعده
    if (!AppConfig.testing) {
      timer = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!mounted || !controller.hasClients) return;
        controller.animateToPage(
          (page + 1) % _length,
          duration: const Duration(milliseconds: 450),
          curve: Curves.easeOut,
        );
      });
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    controller.dispose();
    super.dispose();
  }

  /// رابط الإعلان في المتصفّح، خارج التطبيق — http وhttps وحدهما
  Future<void> _visit(String link) async {
    final uri = Uri.tryParse(link.trim());
    if (uri == null || !(uri.scheme == 'https' || uri.scheme == 'http')) return;
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && mounted) {
      toast(context, 'تعذّر فتح الرابط على هذا الجهاز.');
    }
  }

  Widget _slide(String title, String text, String art, String screen, Color color) => Semantics(
    button: true,
    label: '$title. $text',
    excludeSemantics: true,
    onTap: () => widget.onOpen(screen),
    child: GestureDetector(
      onTap: () => widget.onOpen(screen),
      child: DeepCard(
        brand: brand,
        color: color,
        radius: 0,
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            PositionedDirectional(end: -16, bottom: -30, child: Art(art, brand: brand, width: 156, height: 119)),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 180),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('من الشركة', style: kf(12, Colors.white.withValues(alpha: .78), bold: true)),
                    const SizedBox(height: 4),
                    Text(title, style: kf(20, Colors.white, bold: true, height: 1.35)),
                    const SizedBox(height: 4),
                    Text(
                      text,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: kf(14, Colors.white.withValues(alpha: .88)),
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

  @override
  Widget build(BuildContext context) {
    final banners = widget.banners;
    final pages = banners.isNotEmpty
        ? [
            for (final b in banners)
              // إعلانٌ له رابط: يفتح موقعه بلمسة (docs/plan/93)
              Semantics(
                image: true,
                label: b.title,
                child: GestureDetector(
                  onTap: (b.link ?? '').trim().isEmpty ? null : () => _visit(b.link!),
                  child: Image.network(
                    b.image,
                    headers: Api.instance.imageHeaders,
                    fit: BoxFit.cover,
                    errorBuilder: (_, _, _) => ColoredBox(color: brand.night),
                  ),
                ),
              ),
          ]
        : [for (final s in _slides) _slide(s.$1, s.$2, s.$3, s.$4, s.$5)];
    return ClipRRect(
      borderRadius: BorderRadius.circular(24),
      child: SizedBox(
        height: 176,
        child: Stack(
          children: [
            PageView(controller: controller, onPageChanged: (i) => setState(() => page = i), children: pages),
            if (pages.length > 1)
              PositionedDirectional(
                end: 8,
                bottom: 0,
                child: Row(
                  children: [
                    for (var i = 0; i < pages.length; i++)
                      Semantics(
                        button: true,
                        label: 'الإعلان ${i + 1} من ${pages.length}',
                        selected: i == page,
                        excludeSemantics: true,
                        onTap: () => controller.animateToPage(
                          i,
                          duration: const Duration(milliseconds: 450),
                          curve: Curves.easeOut,
                        ),
                        child: GestureDetector(
                          onTap: () => controller.animateToPage(
                            i,
                            duration: const Duration(milliseconds: 450),
                            curve: Curves.easeOut,
                          ),
                          behavior: HitTestBehavior.opaque,
                          child: SizedBox(
                            width: 40,
                            height: 40,
                            child: Center(
                              child: Container(
                                width: 28,
                                height: 3,
                                alignment: AlignmentDirectional.centerStart,
                                decoration: BoxDecoration(
                                  color: Colors.white.withValues(alpha: .32),
                                  borderRadius: BorderRadius.circular(2),
                                ),
                                child: i < page
                                    ? Container(color: Colors.white)
                                    : i == page
                                    ? (AppConfig.testing
                                          ? Container(color: Colors.white)
                                          : TweenAnimationBuilder<double>(
                                              key: ValueKey(page),
                                              tween: Tween(begin: 0, end: 1),
                                              duration: const Duration(seconds: 5),
                                              builder: (_, v, _) => FractionallySizedBox(
                                                widthFactor: v,
                                                child: Container(
                                                  decoration: BoxDecoration(
                                                    color: Colors.white,
                                                    borderRadius: BorderRadius.circular(2),
                                                  ),
                                                ),
                                              ),
                                            ))
                                    : null,
                              ),
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
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
