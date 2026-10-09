import 'package:flutter/material.dart';

/// ألوان التصميم كما في ملف التصميم (docs/plan/48 §التصميم) — والأحمر من لون الشركة.
class Palette {
  static const bg = Color(0xFFF0F5FB);
  static const ink = Color(0xFF0E1A33);
  static const slate = Color(0xFF5B6B86);
  static const muted = Color(0xFF6F86A2);
  static const line = Color(0xFFE6EBF2);
  static const pill = Color(0xFF69758B);
  static const eye = Color(0xFFEBF0F4);
  static const shadow = Color(0x0F0F2A55);
}

/// درجات لون الشركة: الأحمر في التصميم، وغيره لشركةٍ أخرى بالنسب نفسها.
class Brand {
  Brand(this.main);

  factory Brand.hex(String? hex, {String fallback = '#FE0A0E'}) {
    Color parse(String h) => Color(int.parse('FF${h.replaceAll('#', '')}', radix: 16));
    try {
      return Brand(parse(hex == null || hex.isEmpty ? fallback : hex));
    } catch (_) {
      return Brand(parse(fallback));
    }
  }

  final Color main;

  HSLColor get _hsl => HSLColor.fromColor(main);

  Color _shift(double hue, double light, [double sat = 1]) {
    final h = _hsl;
    return h
        .withHue((h.hue + hue) % 360)
        .withLightness((h.lightness + light).clamp(0, 1))
        .withSaturation((h.saturation * sat).clamp(0, 1))
        .toColor();
  }

  /// الزرّ العائم وشارات «للمعالجة»: أفتح قليلاً نحو المرجانيّ
  Color get coral => _shift(7, .10);

  /// خلفية الأيقونات الفاتحة وبطاقة «إنشاء شحنة» المختارة
  Color get soft => Color.lerp(Colors.white, main, .085)!;
  Color get softer => Color.lerp(Colors.white, main, .045)!;
  Color get track => Color.lerp(Colors.white, main, .075)!;
  Color get border => Color.lerp(Colors.white, main, .24)!;

  /// تدرّج مربّعات الأيقونات الحمراء
  LinearGradient get tile => LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [_shift(7, .11), _shift(3, .02, .92)],
  );
}

/// خطّ «القاهرة» بمقاسات التصميم.
TextStyle font(double size, FontWeight weight, Color color, {double height = 1.25}) => TextStyle(
  fontFamily: 'Cairo',
  fontSize: size,
  fontWeight: weight,
  color: color,
  height: height,
  leadingDistribution: TextLeadingDistribution.even,
);

const w5 = FontWeight.w500;
const w6 = FontWeight.w600;
const w7 = FontWeight.w700;
const w8 = FontWeight.w800;

/// ظلّ البطاقات البيضاء الناعم
const cardShadow = [BoxShadow(color: Palette.shadow, blurRadius: 8, offset: Offset(0, 2))];

/// ١٧٥٠٠٠٠ ← «1,750,000» كما في التصميم
String money(int value) {
  final digits = value.abs().toString();
  final out = StringBuffer(value < 0 ? '-' : '');
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) out.write(',');
    out.write(digits[i]);
  }
  return out.toString();
}

/// «اليوم 10:45 ص»، «أمس 04:15 م»، أو التاريخ
String when(DateTime? at) {
  if (at == null) return '';
  final now = DateTime.now();
  final day = DateTime(at.year, at.month, at.day);
  final today = DateTime(now.year, now.month, now.day);
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final time = '${h.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'ص' : 'م'}';
  if (day == today) return 'اليوم $time';
  if (day == today.subtract(const Duration(days: 1))) return 'أمس $time';
  return '${at.day}/${at.month} $time';
}
