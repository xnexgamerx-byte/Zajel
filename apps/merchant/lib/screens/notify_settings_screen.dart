import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
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
      if (mounted) setState(() => (settings = s, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
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
