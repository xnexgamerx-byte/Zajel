import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../core/whatsapp.dart';
import 'bits.dart';

/// لون واتساب نفسه: يُعرف زرّه من بعيد
const whatsappGreen = Color(0xFF25D366);

/// زرّ واتساب المستدير، بجانب كلّ زرّ اتّصال. وللزبون رسالته جاهزة من القالب
class WhatsAppButton extends StatelessWidget {
  const WhatsAppButton({super.key, required this.phone, this.size = 40, this.text, this.tooltip = 'واتساب'});

  final String? phone;
  final double size;

  /// الرسالة مكتوبةً سلفاً — تُحسب عند الضغط
  final String Function()? text;
  final String tooltip;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tooltip,
    child: Tap(
      radius: size / 2,
      shadow: const [BoxShadow(color: Color(0x4425D366), blurRadius: 14, spreadRadius: -6, offset: Offset(0, 6))],
      onTap: () => openWhatsApp(phone, text: text?.call()),
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
