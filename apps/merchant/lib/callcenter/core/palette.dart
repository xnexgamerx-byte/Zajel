import 'package:flutter/material.dart';

/// ألوان تصميم ز في تطبيق التاجر نفسها (docs/plan/70، 73): خلفيةٌ دافئة، وحبرٌ نبيذيّ داكن، والأحمر
/// الداكن من لون الشركة. وللطوابير ألوانها: التذاكر بلون الشركة (المندوب عند الباب)، والمعالجة
/// كهرمانيّة، ومحادثات التجّار زرقاء، ورسائل المناديب خضراء.
class Palette {
  static const bg = Color(0xFFFBF6F5);
  static const ink = Color(0xFF26181A);
  static const slate = Color(0xFF5C4644);
  static const muted = Color(0xFF7A6260);
  static const line = Color(0xFFF0E3E1);
  static const pill = Color(0xFF7A6260);
  static const returnRed = Color(0xFFB42318);
  static const eye = Color(0xFFF5ECEA);
  static const shadow = Color(0x24781E24);

  /// حقول الكتابة والمربّعات الفاتحة داخل البطاقات
  static const field = Color(0xFFFBF6F5);

  static const info = Color(0xFF2F6DB5);
  static const ok = Color(0xFF1F6B35);
  static const okSoft = Color(0xFFE6F4EA);
  static const warn = Color(0xFFA9650A);
  static const bad = returnRed;
}

/// لون الحالة على شارتها — من قيمتها في النظام لا من اسمها
Color statusColor(String status) => switch (status) {
  'out_for_delivery' => Palette.info,
  'delivered' => Palette.ok,
  'failed_attempt' || 'postponed' || 'partially_delivered' => Palette.warn,
  'returning' || 'returned' || 'lost' || 'damaged' => Palette.bad,
  _ => Palette.slate,
};

/// درجات لون الشركة: الأحمر في التصميم، وغيره لشركةٍ أخرى بالنسب نفسها.
class Brand {
  Brand(this.main);

  factory Brand.hex(String? hex, {String fallback = '#B3202A'}) {
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

  /// أغمق منه: نصٌّ بلون الشركة يُقرأ على الفاتح
  Color get deep => _shift(0, -.05);

  /// تدرّج الأزرار والبطاقات الحمراء الكبيرة في تصميم ز: من الأفتح إلى الأغمق
  LinearGradient get hero => LinearGradient(
    begin: AlignmentDirectional.topStart,
    end: AlignmentDirectional.bottomEnd,
    colors: [_shift(0, .07), main, _shift(-2, -.08)],
  );

  /// ظلّ ما هو بلون الشركة: متوهّجٌ بلونه لا رماديّ
  List<BoxShadow> glow([double alpha = .45]) => [
    BoxShadow(
      color: main.withValues(alpha: alpha),
      blurRadius: 22,
      spreadRadius: -8,
      offset: const Offset(0, 12),
    ),
  ];
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

/// العناوين والأرقام الكبيرة: «القاهرة» نفسه بوزنٍ أثقل درجةً (docs/plan/70)
TextStyle display(double size, FontWeight weight, Color color, {double height = 1.25}) => font(
  size,
  switch (weight) {
    FontWeight.w400 => w5,
    w5 => w6,
    w6 => w7,
    _ => w8,
  },
  color,
  height: height,
);

/// ظلّ البطاقات البيضاء: خطٌّ رفيعٌ قريب وظلٌّ بعيدٌ ناعمٌ بلونٍ دافئ
const cardShadow = [
  BoxShadow(color: Color(0x0A26181A), blurRadius: 2, offset: Offset(0, 1)),
  BoxShadow(color: Palette.shadow, blurRadius: 24, spreadRadius: -10, offset: Offset(0, 12)),
];

/// «#ZA-20260124» وسط نصٍّ عربي: معزولٌ من اليسار لليمين، وإلّا قفزت «#» إلى آخر الرقم
String tag(String number) => '\u2066#$number\u2069';

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

/// موعدٌ بساعته، لا يومٌ وحده: منتصف الليل يعني «اليوم كلّه» (docs/plan/83)
bool hasHour(DateTime at) => at.hour != 0 || at.minute != 0;

const _weekdays = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

/// موعد التأجيل بكلام الناس (docs/plan/83): «اليوم»، «غداً الساعة 9:00 م»، «الخميس 30/10» — و«فات موعده»
/// ليومٍ مضى
String postponedText(DateTime at) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final days = DateTime(at.year, at.month, at.day).difference(today).inDays;
  final day = switch (days) {
    < 0 => 'فات موعده ${at.day}/${at.month}',
    0 => 'اليوم',
    1 => 'غداً',
    _ => '${_weekdays[at.weekday - 1]} ${at.day}/${at.month}',
  };
  if (!hasHour(at)) return day;
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
  return '$day الساعة $h:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'ص' : 'م'}';
}

/// المعدود بعد العدد كما يُكتب: «طرد واحد»، «طردان»، «3 طرود»، «11 طرداً»، «100 طرد»
String parcels(int n) => switch (n % 100) {
  _ when n == 1 => 'طرد واحد',
  _ when n == 2 => 'طردان',
  >= 3 && <= 10 => '$n طرود',
  >= 11 && <= 99 => '$n طرداً',
  _ => '$n طرد',
};

/// لون كلّ طابورٍ في التطبيق (docs/plan/73): يُعرف الطلب من لونه قبل نصّه
class Queue {
  /// المعالجة: ما لم يُسلَّم — كهرمانيّ
  static const processing = Color(0xFFC77700);
  static const processingSoft = Color(0xFFFFF3DD);

  /// محادثات التجّار — أزرق
  static const merchants = Color(0xFF2F6DB5);
  static const merchantsSoft = Color(0xFFE6EFFA);

  /// رسائل المناديب — أخضر
  static const couriers = Color(0xFF1F8A4C);
  static const couriersSoft = Color(0xFFE3F4EA);

  /// حجز زميلةٍ: قفلٌ كهرمانيّ هادئ
  static const lock = Color(0xFF9A5B00);
  static const lockSoft = Color(0xFFFFF4E0);

  /// المؤجّل أصفر، ونصّه على الأبيض ذهبيٌّ داكن (docs/plan/80)
  static const postponed = Color(0xFFC99A06);
  static const postponedInk = Color(0xFF7A5A00);
  static const postponedSoft = Color(0xFFFFF4CC);

  /// ملاحظة للموظّفين: بنفسجيّ هادئ — لا يُخلط بالحجز ولا بالمؤجّل (docs/plan/83)
  static const note = Color(0xFF6A4BA8);
  static const noteSoft = Color(0xFFF1ECFA);
}

/// «الآن»، «منذ 22 د»، «منذ 5 س»، «منذ يومين» — كم ينتظر الطلب
String ago(int minutes) {
  if (minutes < 1) return 'الآن';
  if (minutes < 60) return 'منذ $minutes د';
  final hours = minutes ~/ 60;
  if (hours < 24) return 'منذ $hours س';
  final days = hours ~/ 24;
  return switch (days) {
    1 => 'منذ يوم',
    2 => 'منذ يومين',
    <= 10 => 'منذ $days أيام',
    _ => 'منذ $days يوماً',
  };
}
