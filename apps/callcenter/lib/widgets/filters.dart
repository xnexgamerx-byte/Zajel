import 'package:flutter/material.dart';

import '../core/facets.dart';
import '../core/palette.dart';

export '../core/facets.dart';

/// شريط الفرز (docs/plan/93) فوق كلّ قائمة: «الكل» والمحافظات بأعدادها، وتحتها المندوب والتاجر. لا يظهر
/// ما لا يفرز شيئاً: محافظةٌ واحدة لا شرائح لها.
class FilterBar extends StatelessWidget {
  const FilterBar({
    super.key,
    required this.facets,
    required this.pick,
    required this.onChanged,
    required this.color,
    this.total,
  });

  final Facets facets;
  final FilterPick pick;
  final ValueChanged<FilterPick> onChanged;
  final Color color;

  /// كم في القائمة كلّها، على «الكل»
  final int? total;

  static bool worth(Facets f, FilterPick pick) =>
      pick.any || f.governorates.length > 1 || f.couriers.length > 1 || f.merchants.length > 1;

  @override
  Widget build(BuildContext context) {
    if (!worth(facets, pick)) return const SizedBox.shrink();
    final all = total ?? facets.governorates.fold<int>(0, (n, g) => n + g.count);
    final showPlaces = facets.governorates.length > 1 || pick.governorate != null;
    final showPeople =
        facets.couriers.length > 1 || facets.merchants.length > 1 || pick.courier != null || pick.merchant != null;
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (showPlaces)
            SizedBox(
              height: 38,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                children: [
                  _chip(
                    all > 0 ? 'الكل $all' : 'الكل',
                    pick.governorate == null,
                    () => onChanged(pick.withGovernorate(null)),
                  ),
                  for (final g in facets.governorates)
                    _chip(
                      '${g.label} ${g.count}',
                      pick.governorate == g.key,
                      () => onChanged(pick.withGovernorate(pick.governorate == g.key ? null : g.key)),
                    ),
                ],
              ),
            ),
          if (showPeople)
            Padding(
              padding: EdgeInsets.fromLTRB(14, showPlaces ? 6 : 0, 14, 0),
              child: Row(
                children: [
                  if (facets.couriers.length > 1 || pick.courier != null)
                    Expanded(
                      child: _picker(
                        context,
                        'المندوب',
                        facets.couriers,
                        pick.courier,
                        (v) => onChanged(pick.withCourier(v)),
                      ),
                    ),
                  if ((facets.couriers.length > 1 || pick.courier != null) &&
                      (facets.merchants.length > 1 || pick.merchant != null))
                    const SizedBox(width: 8),
                  if (facets.merchants.length > 1 || pick.merchant != null)
                    Expanded(
                      child: _picker(
                        context,
                        'التاجر',
                        facets.merchants,
                        pick.merchant,
                        (v) => onChanged(pick.withMerchant(v)),
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _chip(String label, bool on, VoidCallback onTap) => Padding(
    padding: const EdgeInsetsDirectional.only(end: 6),
    child: ChoiceChip(
      selected: on,
      showCheckmark: false,
      onSelected: (_) => onTap(),
      selectedColor: color,
      backgroundColor: Colors.white,
      side: BorderSide(color: on ? color : Palette.line),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      visualDensity: VisualDensity.compact,
      label: Text(label, style: font(12, w7, on ? Colors.white : Palette.ink)),
    ),
  );

  /// «المندوب: الكل ▾»: قائمةٌ بأسمائهم وأعدادهم
  Widget _picker(BuildContext context, String what, List<Facet> options, String? picked, ValueChanged<String?> onPick) {
    final current = options.where((o) => o.key == picked).firstOrNull;
    final on = picked != null;
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () async {
        final chosen = await showModalBottomSheet<String>(
          context: context,
          showDragHandle: true,
          backgroundColor: Colors.white,
          builder: (sheet) => SafeArea(
            child: ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
              children: [
                ListTile(
                  title: Text('كل ${what == 'المندوب' ? 'المناديب' : 'التجّار'}', style: font(14, w8, Palette.ink)),
                  trailing: picked == null ? Icon(Icons.check_rounded, color: color) : null,
                  onTap: () => Navigator.pop(sheet, ''),
                ),
                for (final o in options)
                  ListTile(
                    title: Text(o.label, style: font(14, w7, Palette.ink)),
                    subtitle: Text('${o.count}', style: font(11.5, w6, Palette.muted)),
                    trailing: picked == o.key ? Icon(Icons.check_rounded, color: color) : null,
                    onTap: () => Navigator.pop(sheet, o.key),
                  ),
              ],
            ),
          ),
        );
        if (chosen != null) onPick(chosen.isEmpty ? null : chosen);
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: on ? color.withValues(alpha: .1) : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: on ? color : Palette.line),
        ),
        child: Row(
          children: [
            Expanded(
              child: Text(
                '$what: ${current?.label ?? 'الكل'}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: font(12, w7, on ? color : Palette.ink),
              ),
            ),
            Icon(Icons.expand_more_rounded, size: 18, color: on ? color : Palette.muted),
          ],
        ),
      ),
    );
  }
}
