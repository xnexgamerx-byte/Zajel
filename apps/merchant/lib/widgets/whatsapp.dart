import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import 'bits.dart';

/// واتساب في التطبيق (docs/plan/74): زرٌّ بشعاره بجانب كلّ اتّصال، يفتح المحادثة في واتساب نفسه.

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

Uri? whatsappUri(String? phone, {String? text}) {
  final n = whatsappNumber(phone);
  if (n == null) return null;
  final t = (text ?? '').trim();
  return Uri.parse('https://wa.me/$n${t.isEmpty ? '' : '?text=${Uri.encodeComponent(t)}'}');
}

Future<void> openWhatsApp(String? phone, {String? text}) async {
  final uri = whatsappUri(phone, text: text);
  if (uri != null) await launchUrl(uri, mode: LaunchMode.externalApplication);
}

/// زرّ واتساب المستدير بشعاره
class WhatsAppButton extends StatelessWidget {
  const WhatsAppButton({super.key, required this.phone, this.size = 40, this.text, this.tooltip = 'واتساب'});

  final String? phone;
  final double size;
  final String? text;
  final String tooltip;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    child: Tap(
      radius: size / 2,
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
