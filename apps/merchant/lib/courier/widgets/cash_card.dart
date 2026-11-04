import 'package:flutter/material.dart';

import '../core/models.dart';
import '../core/palette.dart';
import 'bits.dart';

/// «النقد بيدك» بشريط السقف — في الرئيسية وفي «حسابي» بالأرقام نفسها (docs/plan/66)
class CashCard extends StatelessWidget {
  const CashCard({super.key, required this.brand, required this.cash, this.large = false, this.onTap});

  final Brand brand;
  final CashSummary cash;

  /// في «حسابي»: الرقم أكبر، والمتبقّي حتى السقف، ونصيحة التسليم قرب السقف
  final bool large;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final tone = cash.limitReached ? Palette.bad : (cash.near ? Palette.warn : Palette.ok);
    final card = WhiteCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('النقد بيدك', style: font(12.5, w6, Palette.slate)),
                    const SizedBox(height: 2),
                    Text.rich(
                      TextSpan(
                        children: [
                          TextSpan(text: money(cash.inHand), style: font(large ? 28 : 24, w8, Palette.ink)),
                          TextSpan(text: ' د.ع', style: font(14, w7, Palette.ink)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              if (cash.percent != null) Pill(large ? '${cash.percent}٪' : '${cash.percent}٪ من السقف', color: tone),
            ],
          ),
          if (cash.limit > 0) ...[
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: (cash.percent ?? 0) / 100,
                minHeight: 8,
                color: tone,
                backgroundColor: brand.track,
              ),
            ),
          ],
          const SizedBox(height: 8),
          Row(
            children: [
              if (cash.limit > 0) Text('السقف ${money(cash.limit)}', style: font(12, w6, Palette.slate)),
              const Spacer(),
              if (large && cash.limit > 0)
                Text(
                  'متبقٍّ ${money((cash.limit - cash.inHand).clamp(0, cash.limit))}',
                  style: font(12, w6, Palette.slate),
                )
              else
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(text: 'الواجب تسليمه ', style: font(12, w6, Palette.slate)),
                      TextSpan(text: money(cash.netDue), style: font(12, w8, Palette.ink)),
                    ],
                  ),
                ),
            ],
          ),
          if (large && cash.near) ...[
            const SizedBox(height: 10),
            Text(
              cash.limitReached
                  ? 'وصلت السقف. سلّم النقد عند موظف الفرع قبل أن تستلم شحناتٍ جديدة.'
                  : 'وصلت قرب السقف. سلّم جزءاً من النقد عند موظف الفرع قبل أن تكمل التحصيل.',
              style: font(12.5, w6, tone, height: 1.5),
            ),
          ],
        ],
      ),
    );
    return onTap == null ? card : Tap(onTap: onTap, child: card);
  }
}
