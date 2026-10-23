import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import 'bits.dart';
import 'page.dart';

/// اتّصالٌ مباشر: الرقم يُطلب ولا يُنسخ
Future<void> callPhone(String? phone) async {
  if (phone == null || phone.isEmpty) return;
  await launchUrl(Uri(scheme: 'tel', path: phone));
}

/// لون واتساب نفسه: يُعرف زرّه من بعيد
const whatsappGreen = Color(0xFF25D366);

/// الرقم كما يريده واتساب: دوليٌّ بلا «+» ولا صفر — 07701234567 ← 9647701234567
String? whatsappNumber(String? phone) {
  var n = (phone ?? '').replaceAll(RegExp(r'\D'), '');
  if (n.startsWith('00')) n = n.substring(2);
  if (n.startsWith('0')) n = '964${n.substring(1)}';
  if (n.length == 10 && n.startsWith('7')) n = '964$n';
  return n.length < 8 ? null : n;
}

/// محادثة واتساب مباشرةً مع الرقم — في تطبيق واتساب لا في المتصفّح
Future<void> openWhatsApp(String? phone, {String? text}) async {
  final n = whatsappNumber(phone);
  if (n == null) return;
  final query = (text ?? '').trim().isEmpty ? '' : '?text=${Uri.encodeComponent(text!.trim())}';
  await launchUrl(Uri.parse('https://wa.me/$n$query'), mode: LaunchMode.externalApplication);
}

/// زرّ واتساب المستدير، بجانب كلّ زرّ اتّصال
class WhatsAppButton extends StatelessWidget {
  const WhatsAppButton({super.key, required this.phone, this.size = 38, this.text, this.shadow = false});

  final String? phone;
  final double size;
  final String? text;
  final bool shadow;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: 'واتساب',
    child: Tap(
      radius: size / 2,
      shadow: shadow
          ? const [BoxShadow(color: Color(0x5525D366), blurRadius: 18, spreadRadius: -6, offset: Offset(0, 8))]
          : null,
      onTap: () => openWhatsApp(phone, text: text),
      child: Ink(
        width: size,
        height: size,
        decoration: const BoxDecoration(color: whatsappGreen, shape: BoxShape.circle),
        child: Center(
          child: FaIcon(FontAwesomeIcons.whatsapp, color: Colors.white, size: size * .5),
        ),
      ),
    ),
  );
}

/// من تعالج الطلب، في سطر القائمة: «تعالجها أنت» أخضر، و«تعالجها زينب» بقفلٍ كهرمانيّ
class ClaimChip extends StatelessWidget {
  const ClaimChip(this.claim, {super.key});

  final ClaimInfo? claim;

  @override
  Widget build(BuildContext context) {
    final c = claim;
    if (c == null) return const SizedBox.shrink();
    final color = c.mine ? Palette.ok : Queue.lock;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: c.mine ? Palette.okSoft : Queue.lockSoft,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(c.mine ? Icons.person_rounded : Icons.lock_rounded, size: 13, color: color),
          const SizedBox(width: 4),
          Text(c.mine ? 'تعالجها أنت' : 'تعالجها ${c.by}', style: font(11, w8, color, height: 1.3)),
        ],
      ),
    );
  }
}

/// «كم ينتظر»: رماديّ، ويحمرّ إذا تأخّر
class WaitChip extends StatelessWidget {
  const WaitChip(this.minutes, {super.key, this.late = false});

  final int minutes;
  final bool late;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(Icons.schedule_rounded, size: 14, color: late ? Palette.returnRed : Palette.muted),
      const SizedBox(width: 3),
      Text(ago(minutes), style: font(11.5, late ? w8 : w6, late ? Palette.returnRed : Palette.muted)),
    ],
  );
}

/// شريط الحجز في أسفل التفاصيل: «أعالجها» إن كان حرّاً، وقفل زميلتها إن كان لها، وأدواتها إن كان لها هي
class LockBanner extends StatelessWidget {
  const LockBanner({super.key, required this.claim, this.onRefresh});

  final ClaimInfo claim;
  final VoidCallback? onRefresh;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: Queue.lockSoft,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: const Color(0xFFF2D9A6)),
    ),
    child: Row(
      children: [
        Container(
          width: 42,
          height: 42,
          decoration: BoxDecoration(color: Queue.lock, borderRadius: BorderRadius.circular(14)),
          child: const Icon(Icons.lock_rounded, color: Colors.white, size: 21),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('تعالجها ${claim.by}', style: font(14.5, w8, Queue.lock)),
              Text(
                'لا تُعالَج مرّتين. تصير لك حين تحسمها أو تفلتها، أو بعد ربع ساعةٍ بلا عملٍ عليها.',
                style: font(11.5, w6, Palette.slate, height: 1.45),
              ),
            ],
          ),
        ),
        if (onRefresh != null)
          IconButton(
            tooltip: 'تحديث',
            onPressed: onRefresh,
            icon: const Icon(Icons.refresh_rounded, color: Queue.lock),
          ),
      ],
    ),
  );
}

/// سطر شخصٍ يُتّصل به: المندوب، التاجر، الزبون
class PersonRow extends StatelessWidget {
  const PersonRow({super.key, required this.role, required this.party, required this.icon, required this.color});

  final String role;
  final Party party;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(
      children: [
        Container(
          width: 38,
          height: 38,
          decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(13)),
          child: Icon(icon, size: 19, color: color),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(role, style: font(11, w6, Palette.muted)),
              Text(party.name ?? '—', maxLines: 1, overflow: TextOverflow.ellipsis, style: font(14, w8, Palette.ink)),
            ],
          ),
        ),
        if ((party.phone ?? '').isNotEmpty) ...[
          WhatsAppButton(phone: party.phone, size: 36),
          const SizedBox(width: 6),
          Tooltip(
            message: 'اتصل بـ${party.name ?? role}',
            child: Tap(
              radius: 20,
              onTap: () => callPhone(party.phone),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(color: Palette.okSoft, borderRadius: BorderRadius.circular(20)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.call_rounded, size: 16, color: Palette.ok),
                    const SizedBox(width: 5),
                    Text('اتصل', style: font(12.5, w8, Palette.ok)),
                  ],
                ),
              ),
            ),
          ),
        ],
      ],
    ),
  );
}

/// فقاعات محادثة: ما كتبته الشركة بلون الشركة في آخر السطر، وما كتبه الطرف الآخر أبيض في أوّله
class ChatBubbles extends StatelessWidget {
  const ChatBubbles({super.key, required this.brand, required this.lines, this.empty = 'لا رسالة بعد.'});

  final Brand brand;
  final List<ChatLine> lines;
  final String empty;

  @override
  Widget build(BuildContext context) {
    if (lines.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Text(empty, textAlign: TextAlign.center, style: font(12.5, w6, Palette.muted)),
      );
    }
    final width = MediaQuery.sizeOf(context).width * .78;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final m in lines)
          Align(
            alignment: m.mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              constraints: BoxConstraints(maxWidth: width),
              padding: const EdgeInsets.fromLTRB(12, 9, 12, 7),
              decoration: BoxDecoration(
                gradient: m.mine ? brand.hero : null,
                color: m.mine ? null : Colors.white,
                border: m.mine ? null : Border.all(color: Palette.line),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (m.body.isNotEmpty)
                    Text(m.body, style: font(13.5, w6, m.mine ? Colors.white : Palette.ink, height: 1.55)),
                  if (m.file != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4, bottom: 2),
                      child: ChatAttachment(file: m.file!, mine: m.mine, brand: brand),
                    ),
                  Text(
                    '${m.author} · ${when(m.at)}',
                    style: font(10.5, w6, m.mine ? Colors.white.withValues(alpha: .78) : Palette.muted, height: 1.6),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// خانة الكتابة وزرّ الإرسال
class Composer extends StatelessWidget {
  const Composer({
    super.key,
    required this.brand,
    required this.controller,
    required this.onSend,
    this.hint = 'اكتب…',
    this.busy = false,
  });

  final Brand brand;
  final TextEditingController controller;
  final VoidCallback onSend;
  final String hint;
  final bool busy;

  @override
  Widget build(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.end,
    children: [
      Expanded(
        child: InputBox(controller: controller, hint: hint, maxLength: 2000),
      ),
      const SizedBox(width: 8),
      Tooltip(
        message: 'أرسل',
        child: Tap(
          radius: 23,
          shadow: brand.glow(.35),
          onTap: busy ? null : onSend,
          child: Ink(
            width: 46,
            height: 46,
            decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle),
            child: busy
                ? const Padding(
                    padding: EdgeInsets.all(13),
                    child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                  )
                : const Icon(Icons.send_rounded, color: Colors.white, size: 20),
          ),
        ),
      ),
    ],
  );
}

/// غلافٌ أبيض بعنوانٍ صغير فوق محتواه
class Panel extends StatelessWidget {
  const Panel({super.key, this.title, required this.child, this.padding = const EdgeInsets.all(14)});

  final String? title;
  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => WhiteCard(
    radius: 22,
    padding: padding,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (title != null) ...[Text(title!, style: font(12.5, w8, Palette.muted)), const SizedBox(height: 6)],
        child,
      ],
    ),
  );
}

/// صورة التاجر في المحادثة تُرى في مكانها، وتُكبَّر بلمسة. وما ليس صورة يُذكر باسمه وحجمه
class ChatAttachment extends StatelessWidget {
  const ChatAttachment({super.key, required this.file, required this.mine, required this.brand});

  final ChatFile file;
  final bool mine;
  final Brand brand;

  @override
  Widget build(BuildContext context) {
    if (!file.image || file.url.isEmpty) return _name();
    return Tooltip(
      message: 'كبّر الصورة',
      child: GestureDetector(
        onTap: () =>
            Navigator.of(context)
                .push(MaterialPageRoute(fullscreenDialog: true, builder: (_) => PhotoScreen(file: file))),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Image.network(
            file.url,
            headers: Api.instance.fileHeaders,
            width: 230,
            height: 230,
            fit: BoxFit.cover,
            loadingBuilder: (_, child, progress) => progress == null
                ? child
                : Container(
                    width: 230,
                    height: 230,
                    color: mine ? Colors.white.withValues(alpha: .15) : Palette.eye,
                    alignment: Alignment.center,
                    child: CircularProgressIndicator(strokeWidth: 2.2, color: mine ? Colors.white : brand.main),
                  ),
            errorBuilder: (_, _, _) => _name(),
          ),
        ),
      ),
    );
  }

  Widget _name() => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(
        file.image ? Icons.image_outlined : Icons.attach_file_rounded,
        size: 16,
        color: mine ? Colors.white : brand.deep,
      ),
      const SizedBox(width: 4),
      Flexible(
        child: Text(
          [file.name, if (file.size.isNotEmpty) file.size].join(' · '),
          style: font(11.5, w7, mine ? Colors.white : brand.deep, height: 1.5),
        ),
      ),
    ],
  );
}

/// الصورة بملء الشاشة: تُقرّب بالأصابع
class PhotoScreen extends StatelessWidget {
  const PhotoScreen({super.key, required this.file});

  final ChatFile file;

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Colors.black,
    appBar: AppBar(
      backgroundColor: Colors.black,
      foregroundColor: Colors.white,
      title: Text(file.name, style: font(13, w7, Colors.white)),
    ),
    body: InteractiveViewer(
      maxScale: 5,
      child: Center(
        child: Image.network(file.url, headers: Api.instance.fileHeaders, fit: BoxFit.contain),
      ),
    ),
  );
}
