import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/push.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

Future<void> openNotifySettings(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotifySettingsScreen(brand: brand)));

/// «اختيار الإشعارات» (docs/plan/86): ما يصل هاتفك والتطبيق مغلق — وصلت شحنتك، للمعالجة، تمت معالجتها، تغيّر
/// المبلغ — وبصوتٍ أم بلا صوت. وتحتها كيف يظهر كلٌّ على شاشة الهاتف. الجرس في التطبيق يحمل كلّ شيءٍ كما هو.
class NotifySettingsScreen extends StatefulWidget {
  const NotifySettingsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<NotifySettingsScreen> createState() => _NotifySettingsScreenState();
}

class _NotifySettingsScreenState extends State<NotifySettingsScreen> {
  NotifySettings? settings;
  String? error;
  bool saving = false;

  /// إذن الإشعارات في الهاتف (docs/plan/89)
  String permission = 'unknown';
  bool testing = false;

  Brand get brand => widget.brand;

  /// كيف يظهر كلّ نوعٍ على شاشة الهاتف — بنصّ النظام نفسه
  static const samples = {
    'delivered': ('وصلت شحنتك 000288', 'سُلّمت وقُبض 25,000 د.ع.'),
    'failed': ('شحنتك 000291 لم تُسلَّم — للمعالجة', 'السبب: الزبون لا يرد. نتّصل بالزبون ونخبرك بالقرار.'),
    'processed': ('إعادة توصيل شحنتك 000291', 'اتّصلنا بالزبون، وتخرج إليه ثانيةً.'),
    'amount': ('تغيّر مبلغ الوصل 000295', 'المبلغ صار 30,000 د.ع بدل 35,000 د.ع. وتحدّث في وصلك وحسابك.'),
  };

  static const icons = {
    'delivered': Icons.check_circle_outline_rounded,
    'failed': Icons.support_agent_rounded,
    'processed': Icons.task_alt_rounded,
    'amount': Icons.payments_outlined,
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final s = await Api.instance.notifySettings();
      final p = await Push.permission();
      if (mounted) setState(() => (settings = s, error = null, permission = p));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  /// يطلب الإذن ثانيةً ويسجّل الهاتف، ثم يعيد الحال
  Future<void> _allow() async {
    await Push.register();
    await _load();
    if (mounted && permission == 'denied') {
      toast(
        context,
        'الإشعارات مطفأةٌ للتطبيق: افتح إعدادات الهاتف ← التطبيقات ← هذا التطبيق ← الإشعارات، وشغّلها.',
        bad: true,
      );
    }
  }

  Future<void> _test() async {
    setState(() => testing = true);
    try {
      final message = await Api.instance.testNotification();
      if (mounted) toast(context, message);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => testing = false);
      await _load();
    }
  }

  Future<void> _set(String key, bool on) async {
    if (saving) return;
    setState(() => saving = true);
    try {
      final s = await Api.instance.saveNotifySettings({key: on});
      if (mounted) setState(() => settings = s);
    } on ApiError catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = settings;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'اختيار الإشعارات', subtitle: 'ما يصل هاتفك والتطبيق مغلق'),
            Expanded(
              child: s == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                      children: [
                        _status(s),
                        const SizedBox(height: 14),
                        WhiteCard(
                          padding: EdgeInsets.zero,
                          child: Column(
                            children: [
                              for (final k in s.kinds) _toggle(k.key, k.label, _hint(k.key), icons[k.key], k.on),
                              const Divider(height: 1, color: Palette.line),
                              _toggle(
                                'sound',
                                'صوت الإشعار',
                                s.sound ? 'يرنّ الهاتف ويظهر الإشعار أعلى الشاشة' : 'يصل بصمت في شريط الإشعارات',
                                s.sound ? Icons.volume_up_rounded : Icons.volume_off_rounded,
                                s.sound,
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 18),
                        Text('هكذا تظهر على شاشة هاتفك', style: display(14, w7, Palette.ink)),
                        const SizedBox(height: 8),
                        for (final k in s.kinds.where((k) => k.on)) ...[
                          _preview(k.key, s.sound),
                          const SizedBox(height: 8),
                        ],
                        if (s.kinds.every((k) => !k.on))
                          Text(
                            'لا شيء يصل هاتفك. الجرس في التطبيق يحمل كلّ شيء.',
                            style: font(12.5, w6, Palette.slate, height: 1.6),
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  /// هل تصل إشعاراتك؟ (docs/plan/89): النسخة، وإذن الهاتف، والخادم، وتسجيل هاتفك — كلٌّ بحاله، وما يُصلحه،
  /// و«جرّب إشعاراً الآن»
  Widget _status(NotifySettings s) {
    final built = Push.configured;
    final lines = <(bool, String, String)>[
      (
        built,
        'هذه النسخة',
        AppConfig.demo
            ? 'نسخة العرض: لا تصل الهاتف إشعارات'
            : built
            ? 'إشعارات الهاتف مفعّلةٌ فيها'
            : 'بُنيت بلا إشعارات الهاتف — ثبّت آخر نسخةٍ من التطبيق',
      ),
      if (built)
        (
          permission == 'granted',
          'إذن الهاتف',
          permission == 'granted' ? 'مسموحٌ للتطبيق بالإشعارات' : 'الإشعارات مطفأةٌ للتطبيق في الهاتف',
        ),
      (s.pushServer, 'الخادم', s.pushServer ? 'جاهزٌ للإرسال' : 'غير مهيّأ لإشعارات الهاتف بعد — راجع شركة التوصيل'),
      if (s.devices != null) (s.devices! > 0, 'هاتفك', s.devices! > 0 ? 'مسجّلٌ للإشعارات' : 'غير مسجّلٍ بعد'),
    ];
    final ok = lines.every((l) => l.$1);
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                ok ? Icons.notifications_active_rounded : Icons.notifications_off_outlined,
                color: ok ? Palette.ok : brand.main,
                size: 20,
              ),
              const SizedBox(width: 8),
              Text(
                ok ? 'إشعارات الهاتف تعمل' : 'إشعارات الهاتف لا تصل',
                style: font(14.5, w8, ok ? Palette.ok : brand.main),
              ),
            ],
          ),
          const SizedBox(height: 8),
          for (final (good, label, text) in lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    good ? Icons.check_circle_rounded : Icons.error_rounded,
                    size: 17,
                    color: good ? Palette.ok : Palette.returnRed,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text.rich(
                      TextSpan(
                        children: [
                          TextSpan(text: '$label: ', style: font(12.5, w8, Palette.ink, height: 1.5)),
                          TextSpan(text: text, style: font(12.5, w5, Palette.slate, height: 1.5)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 10),
          if (built && (permission != 'granted' || (s.devices ?? 1) == 0)) ...[
            SheetButton(
              brand: brand,
              label: 'اسمح بالإشعارات',
              icon: Icons.notifications_active_outlined,
              onTap: _allow,
            ),
            const SizedBox(height: 8),
          ],
          SheetButton(
            brand: brand,
            label: 'جرّب إشعاراً الآن',
            icon: Icons.send_rounded,
            outlined: true,
            busy: testing,
            onTap: _test,
          ),
        ],
      ),
    );
  }

  String _hint(String key) => switch (key) {
    'delivered' => 'حين تُسلَّم شحنتك، بما قُبض',
    'failed' => 'حين لا تُسلَّم، بسببها — قبل قرار المتابعة',
    'processed' => 'قرار المتابعة: إعادة توصيل، أو تأجيل بموعده، أو راجع',
    _ => 'حين يتغيّر مبلغ وصلك',
  };

  // Material شفّافةٌ تحت كلّ سطر: البطاقة البيضاء لا تغطّي لمسته
  Widget _toggle(String key, String title, String hint, IconData? icon, bool on) =>
      Material(type: MaterialType.transparency, child: _switch(key, title, hint, icon, on));

  Widget _switch(String key, String title, String hint, IconData? icon, bool on) => SwitchListTile(
    value: on,
    onChanged: saving ? null : (v) => _set(key, v),
    activeThumbColor: Colors.white,
    activeTrackColor: brand.main,
    secondary: Container(
      width: 38,
      height: 38,
      decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
      child: Icon(icon ?? Icons.notifications_none_rounded, size: 20, color: brand.main),
    ),
    title: Text(title, style: font(14, w8, Palette.ink)),
    subtitle: Text(hint, style: font(11.5, w6, Palette.slate, height: 1.45)),
  );

  /// إشعارٌ كما يظهر على الهاتف: أيقونة الجرس بلون الشركة، واسم التطبيق والوقت، والعنوان والنصّ
  Widget _preview(String key, bool sound) {
    final (title, body) = samples[key] ?? ('', '');
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: BoxDecoration(
        color: const Color(0xFFF3F1F4),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Palette.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 22,
                height: 22,
                decoration: BoxDecoration(color: brand.main, shape: BoxShape.circle),
                child: const Icon(Icons.notifications_rounded, size: 14, color: Colors.white),
              ),
              const SizedBox(width: 6),
              Text('التاجر · الآن', style: font(11.5, w6, Palette.slate)),
              const Spacer(),
              Icon(sound ? Icons.volume_up_rounded : Icons.volume_off_rounded, size: 15, color: Palette.muted),
            ],
          ),
          const SizedBox(height: 6),
          Text(title, style: font(13.5, w8, Palette.ink, height: 1.4)),
          Text(body, style: font(12.5, w5, Palette.slate, height: 1.5)),
        ],
      ),
    );
  }
}
