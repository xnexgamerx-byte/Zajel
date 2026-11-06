import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../core/palette.dart';
import 'art_data.dart';

/// رسمٌ من رسوم «وصل» (docs/plan/123) بلون الشركة: الحالات (طردٌ ومنصّةٌ وسيارةٌ وطابعة)، والإعلانات، والرؤوس،
/// وخيارات الإدخال. مرسومةٌ هندسياً في ملف التصميم ومنقولةٌ كما هي (art_data.dart).
class Art extends StatelessWidget {
  const Art(this.name, {super.key, required this.brand, this.width, this.height});

  /// اسم الرسم: created · pickup · warehouse · courier · redelivery · postponed · processing · delivered ·
  /// return_courier · return_hub · returns · returnsAll · printer، أو ad_van · ad_stack · ad_printer ·
  /// scene_hub · cash · scan · ai · voice
  final String name;
  final Brand brand;
  final double? width;
  final double? height;

  /// الرسم بلون الشركة مرّةً واحدة لكلّ لون
  static final _painted = <String, String>{};

  static String? source(String name, String hex) {
    final raw = stateArt[name] ?? sceneArt[name];
    if (raw == null) return null;
    return _painted.putIfAbsent('$name$hex', () => raw.replaceAll('{{accent}}', hex));
  }

  @override
  Widget build(BuildContext context) {
    final svg = source(name, brand.hex);
    if (svg == null) return SizedBox(width: width, height: height);
    return SvgPicture.string(svg, width: width, height: height, fit: BoxFit.contain, excludeFromSemantics: true);
  }
}
