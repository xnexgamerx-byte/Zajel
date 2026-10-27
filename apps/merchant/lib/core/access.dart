import 'package:flutter/foundation.dart';

/// ما يفتحه الحساب الداخل (docs/plan/87): صاحب المتجر كلّه، وموظّفه ما فتحه له. الخادم يرفض غيره، وهنا لا
/// يظهر: لا تبويبٌ ولا زرٌّ ولا رقمٌ لما لم يُفتح.
class Access {
  Access._();

  static const owner = 'owner';
  static const all = ['shipments', 'create', 'print', 'pickups', 'processing', 'finance', 'support', owner];

  static Set<String> _perms = {...all};

  /// «موظّفو المتجر» مفتوحةٌ لشركته (من الرئيسية)
  static bool staffFeature = false;

  /// تغيّر ما يفتحه الحساب: يُعاد بناء الشريط والقوائم
  static final changed = ValueNotifier<int>(0);

  /// null: نظامٌ لا يعرف الموظّفين — الحساب صاحب المتجر
  static void set(List<String>? perms, {bool? staff}) {
    final next = perms == null ? {...all} : {...perms};
    final staffNext = staff ?? staffFeature;
    if (setEquals(next, _perms) && staffNext == staffFeature) return;
    _perms = next;
    staffFeature = staffNext;
    changed.value++;
  }

  static bool can(String permission) => _perms.contains(permission);

  static bool get isOwner => can(owner);

  /// صلاحية الشاشة باسمها في التطبيق (shipments:delivered، labels، finance…)، وnull لما يفتحه الجميع
  static String? needs(String screen) => switch (screen.split(':').first) {
    'shipments' || 'attention' || 'status' || 'states' || 'calendar' => 'shipments',
    'create' || 'import' || 'waybills' => 'create',
    'finance' || 'returns' || 'receive' || 'requests' => 'finance',
    'labels' => 'print',
    'pickups' => 'pickups',
    'processing' => 'processing',
    'support' => 'support',
    'staff' => owner,
    _ => null,
  };

  /// المسح: وصلٌ مطبوعٌ لشحنةٍ جديدة، أو ورقة حسابٍ أو راجعٍ تُستلم
  static bool opens(String screen) {
    if (screen == 'scan') return can('create') || can('finance');
    final p = needs(screen);
    return p == null || can(p);
  }
}
