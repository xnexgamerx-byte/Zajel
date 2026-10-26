import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../core/config.dart';
import '../core/palette.dart';
import '../core/whatsapp_template.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/work.dart';

/// «رسالة واتساب للزبون» من حسابها (docs/plan/80): تكتبها الموظّفة مرّة، وبعدها «تواصلي ← واتساب برسالة
/// الشحنة» يفتح محادثة الزبون وفيها الرسالة باسمه ووصله ومبلغه وسبب آخر محاولة — تضغط «إرسال» وحسب.
class WhatsAppTemplateScreen extends StatefulWidget {
  const WhatsAppTemplateScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<WhatsAppTemplateScreen> createState() => _WhatsAppTemplateScreenState();
}

class _WhatsAppTemplateScreenState extends State<WhatsAppTemplateScreen> {
  late final text = TextEditingController(text: WhatsAppTemplate.text.value);

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    text.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  /// يُدرج الحقل حيث المؤشّر
  void _insert(String field) {
    final sel = text.selection;
    final at = sel.isValid ? sel.start : text.text.length;
    final end = sel.isValid ? sel.end : text.text.length;
    text.value = TextEditingValue(
      text: text.text.replaceRange(at, end, field),
      selection: TextSelection.collapsed(offset: at + field.length),
    );
  }

  Future<void> _save() async {
    await WhatsAppTemplate.save(text.text);
    if (!mounted) return;
    toast(context, text.text.trim().isEmpty ? 'مُسحت الرسالة: واتساب يُفتح فارغاً.' : 'حُفظت رسالة واتساب.');
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final preview = WhatsAppTemplate.fillWith(text.text, {
      '{الزبون}': 'علي حسين',
      '{الوصل}': '180240',
      '{المبلغ}': money(35000),
      '{التاجر}': 'متجر الوردة',
      '{المنطقة}': 'بغداد · الكرادة',
      '{السبب}': 'لا يرد على الهاتف',
      '{الموظفة}': WhatsAppTemplate.agent.isEmpty ? 'سارة' : WhatsAppTemplate.agent,
      '{الشركة}': WhatsAppTemplate.company.isEmpty ? AppConfig.companyName : WhatsAppTemplate.company,
    });
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'رسالة واتساب للزبون', subtitle: 'تُكتب مرّة، وتُملأ من كلّ شحنة'),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                children: [
                  WhiteCard(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      children: [
                        const WhatsAppButton(phone: null, size: 42),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'زرّ واتساب الزبون في الشحنة والمعالجة يفتح محادثته وفيها هذه الرسالة مملوءةً من شحنته. '
                            'لا يُرسل شيءٌ وحده: أنتِ تضغطين «إرسال» في واتساب.',
                            style: font(12.5, w6, Palette.slate, height: 1.6),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SectionTitle('الرسالة'),
                  InputBox(controller: text, hint: 'اكتبي رسالتك للزبون…', lines: 5, maxLength: 600),
                  const SizedBox(height: 8),
                  Text('اضغطي لتضيفي من الشحنة:', style: font(12, w7, Palette.slate)),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final (field, label) in WhatsAppTemplate.fields)
                        ActionChip(
                          onPressed: () => _insert(field),
                          backgroundColor: brand.soft,
                          side: BorderSide.none,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                          avatar: Icon(Icons.add_rounded, size: 16, color: brand.deep),
                          label: Text(label, style: font(12, w8, brand.deep)),
                        ),
                    ],
                  ),
                  const SectionTitle('كما يراها الزبون'),
                  _bubble(preview),
                  const SizedBox(height: 10),
                  if (text.text.trim() != WhatsAppTemplate.suggested)
                    TextButton.icon(
                      onPressed: () => text.text = WhatsAppTemplate.suggested,
                      icon: Icon(Icons.restart_alt_rounded, size: 18, color: brand.main),
                      label: Text('الرسالة المقترحة', style: font(13, w7, brand.main)),
                    ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
              child: HeroButton(brand: brand, label: 'احفظ الرسالة', icon: Icons.check_rounded, onTap: _save),
            ),
          ],
        ),
      ),
    );
  }

  /// فقاعة واتساب الخضراء: الرسالة كما تُرسل
  Widget _bubble(String preview) => Align(
    alignment: AlignmentDirectional.centerEnd,
    child: Container(
      constraints: const BoxConstraints(maxWidth: 320),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
      decoration: BoxDecoration(
        color: const Color(0xFFDCF8C6),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFC5E8AE)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            preview.isEmpty ? 'بلا رسالة: تُفتح المحادثة فارغة.' : preview,
            style: font(13.5, w6, preview.isEmpty ? Palette.muted : Palette.ink, height: 1.6),
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const FaIcon(FontAwesomeIcons.whatsapp, size: 12, color: whatsappGreen),
              const SizedBox(width: 4),
              Text('واتساب', style: font(10.5, w7, Palette.muted)),
            ],
          ),
        ],
      ),
    ),
  );
}
