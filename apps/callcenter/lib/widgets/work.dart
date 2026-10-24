import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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

/// ينسخ النصّ ويقول ما نُسخ (docs/plan/80): رقم الوصل، وهاتف الزبون، والوصل كاملاً
Future<void> copyText(BuildContext context, String text, String what) async {
  await Clipboard.setData(ClipboardData(text: text));
  HapticFeedback.selectionClick();
  if (context.mounted) toast(context, 'نُسخ $what');
}

/// ما يُنسخ بـ«انسخي الوصل كاملاً»: كلّ ما في الوصل سطراً سطراً، يُلصق في واتساب أو رسالة
String waybillText({
  required String number,
  required String recipient,
  String? phone,
  String? phoneAlt,
  String? place,
  String? address,
  String? landmark,
  required int amount,
  String? merchant,
  String? status,
  String? description,
  int? pieces,
  String? notes,
}) => [
  'الوصل: $number',
  'الزبون: $recipient',
  if ((phone ?? '').isNotEmpty) 'الهاتف: $phone',
  if ((phoneAlt ?? '').isNotEmpty) 'الهاتف البديل: $phoneAlt',
  if ((place ?? '').isNotEmpty) 'المنطقة: $place',
  if ((address ?? '').isNotEmpty || (landmark ?? '').isNotEmpty)
    'العنوان: ${[address, landmark].whereType<String>().where((t) => t.isNotEmpty).join(' — ')}',
  'المبلغ: ${money(amount)} د.ع',
  if ((merchant ?? '').isNotEmpty) 'المتجر: $merchant',
  if ((description ?? '').isNotEmpty) 'المحتوى: $description${pieces != null ? ' · $pieces قطعة' : ''}',
  if ((status ?? '').isNotEmpty) 'الحالة: $status',
  if ((notes ?? '').isNotEmpty) 'ملاحظة: $notes',
].join('\n');

/// «اتصلي بالزبون» (docs/plan/80): بالخط، أو واتساب برسالة الشحنة من القالب، أو واتساب بلا رسالة —
/// تختار ولا تنسخ رقماً
Future<void> contactCustomer(
  BuildContext context,
  Brand brand, {
  required String? phone,
  required String message,
}) async {
  if ((phone ?? '').isEmpty) return;
  final choice = await showSheet<String>(
    context,
    SheetFrame(
      title: 'التواصل مع الزبون',
      text: phone!,
      children: [
        SheetButton(
          brand: brand,
          label: 'اتصال بالخط',
          icon: Icons.call_rounded,
          onTap: () => Navigator.of(context).pop('call'),
        ),
        const SizedBox(height: 8),
        _WhatsAppChoice(
          label: 'واتساب برسالة الشحنة',
          sub: message.isEmpty ? 'القالب فارغ: تُفتح المحادثة بلا رسالة' : message,
          onTap: () => Navigator.of(context).pop('message'),
        ),
        const SizedBox(height: 8),
        SheetButton(
          brand: brand,
          label: 'واتساب بلا رسالة',
          icon: Icons.chat_outlined,
          outlined: true,
          onTap: () => Navigator.of(context).pop('whatsapp'),
        ),
      ],
    ),
  );
  switch (choice) {
    case 'call':
      await callPhone(phone);
    case 'message':
      await openWhatsApp(phone, text: message);
    case 'whatsapp':
      await openWhatsApp(phone);
  }
}

/// خيار واتساب الأخضر، ونصّ الرسالة تحته كما ستُرسل
class _WhatsAppChoice extends StatelessWidget {
  const _WhatsAppChoice({required this.label, required this.sub, required this.onTap});

  final String label;
  final String sub;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Tap(
    radius: 16,
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFE9F9EE),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFBDE9CB)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: const BoxDecoration(color: whatsappGreen, shape: BoxShape.circle),
            child: const Center(child: FaIcon(FontAwesomeIcons.whatsapp, color: Colors.white, size: 19)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: font(14, w8, const Color(0xFF13743A))),
                Text(
                  sub,
                  maxLines: 4,
                  overflow: TextOverflow.ellipsis,
                  style: font(11.5, w6, Palette.slate, height: 1.5),
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

/// الزبون كما يُعرض في الشحنة والمعالجة (docs/plan/80): اسمه ومنطقته، وهاتفه ظاهراً يُنسخ بلمسة، وزرّ
/// «تواصلي» يسأل: بالخط أم واتساب برسالة الشحنة
class CustomerCard extends StatelessWidget {
  const CustomerCard({
    super.key,
    required this.brand,
    required this.name,
    required this.place,
    required this.phone,
    required this.message,
    this.phoneAlt,
    this.big = false,
  });

  final Brand brand;
  final String name;
  final String place;
  final String? phone;
  final String? phoneAlt;

  /// رسالة واتساب من القالب، مملوءةً من الشحنة
  final String message;

  /// في المعالجة: الزبون أوّل ما في الصفحة
  final bool big;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Row(
        children: [
          Container(
            width: big ? 46 : 38,
            height: big ? 46 : 38,
            decoration: BoxDecoration(
              color: Queue.couriers.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(big ? 16 : 13),
            ),
            child: Icon(Icons.person_rounded, size: big ? 23 : 19, color: Queue.couriers),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name.isEmpty ? 'الزبون' : name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: big ? display(19, w7, Palette.ink) : font(14.5, w8, Palette.ink),
                ),
                if (place.isNotEmpty) Text(place, style: font(12, w7, Palette.slate, height: 1.4)),
              ],
            ),
          ),
          if ((phone ?? '').isNotEmpty) ...[
            WhatsAppButton(phone: phone, size: big ? 50 : 38, text: message, shadow: big),
            const SizedBox(width: 8),
            Tooltip(
              message: 'تواصلي مع الزبون',
              child: Tap(
                radius: 30,
                shadow: big
                    ? const [
                        BoxShadow(color: Color(0x551F8A4C), blurRadius: 18, spreadRadius: -6, offset: Offset(0, 8)),
                      ]
                    : null,
                onTap: () => contactCustomer(context, brand, phone: phone, message: message),
                child: Ink(
                  width: big ? 50 : 38,
                  height: big ? 50 : 38,
                  decoration: const BoxDecoration(color: Queue.couriers, shape: BoxShape.circle),
                  child: Icon(Icons.call_rounded, color: Colors.white, size: big ? 24 : 19),
                ),
              ),
            ),
          ],
        ],
      ),
      if ((phone ?? '').isNotEmpty) ...[const SizedBox(height: 8), _PhoneLine(label: 'الهاتف', phone: phone!)],
      if ((phoneAlt ?? '').isNotEmpty) ...[
        const SizedBox(height: 6),
        _PhoneLine(label: 'الهاتف البديل', phone: phoneAlt!, brand: brand, message: message),
      ],
    ],
  );
}

/// رقمٌ ظاهرٌ يُنسخ بلمسة، ونسخه بزرّه
class _PhoneLine extends StatelessWidget {
  const _PhoneLine({required this.label, required this.phone, this.brand, this.message});

  final String label;
  final String phone;
  final Brand? brand;
  final String? message;

  @override
  Widget build(BuildContext context) => Tap(
    radius: 12,
    onTap: () => copyText(context, phone, 'رقم الزبون'),
    child: Ink(
      padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .7),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Palette.line),
      ),
      child: Row(
        children: [
          Text(label, style: font(11.5, w6, Palette.muted)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              phone,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.end,
              style: font(15, w8, Palette.ink, height: 1.2),
            ),
          ),
          const SizedBox(width: 6),
          const Icon(Icons.copy_rounded, size: 17, color: Palette.muted),
          if (brand != null) ...[
            const SizedBox(width: 6),
            InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => contactCustomer(context, brand!, phone: phone, message: message ?? ''),
              child: const Padding(
                padding: EdgeInsets.all(4),
                child: Icon(Icons.call_rounded, size: 18, color: Queue.couriers),
              ),
            ),
          ],
        ],
      ),
    ),
  );
}

/// رقم الوصل كبيراً يُنسخ بلمسة، و«انسخي الوصل كاملاً» بجانبه (docs/plan/80)
class WaybillBar extends StatelessWidget {
  const WaybillBar({super.key, required this.brand, required this.number, required this.full, this.status});

  final Brand brand;
  final String number;

  /// الوصل كاملاً نصّاً — waybillText
  final String full;
  final Widget? status;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: Tap(
          radius: 14,
          onTap: () => copyText(context, number, 'رقم الوصل $number'),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Palette.line),
            ),
            child: Row(
              children: [
                Icon(Icons.copy_rounded, size: 17, color: brand.main),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    tag(number),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(15.5, w8, Palette.ink, height: 1.2),
                  ),
                ),
                ?status,
              ],
            ),
          ),
        ),
      ),
      const SizedBox(width: 8),
      Tooltip(
        message: 'انسخي الوصل كاملاً',
        child: Tap(
          radius: 14,
          onTap: () => copyText(context, full, 'الوصل كاملاً'),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.content_copy_rounded, size: 17, color: brand.deep),
                const SizedBox(width: 5),
                Text('الوصل كاملاً', style: font(12.5, w8, brand.deep)),
              ],
            ),
          ),
        ),
      ),
    ],
  );
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
