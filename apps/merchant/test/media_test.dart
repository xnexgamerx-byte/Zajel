import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/config.dart';

void main() {
  test('روابط الصور من النظام تُقرأ على عنوان النظام في إعداد التطبيق', () {
    const base = 'https://zajel.wahajiq.net';
    // خلف Cloudflare قد يبني النظام الرابط بـhttp أو باسمٍ داخليّ
    expect(
      rebase('http://zajel.wahajiq.net/api/v1/merchant/logo?v=ab12', base),
      'https://zajel.wahajiq.net/api/v1/merchant/logo?v=ab12',
    );
    expect(rebase('http://app:8080/api/v1/app-ads/3/image', base), 'https://zajel.wahajiq.net/api/v1/app-ads/3/image');
    expect(
      rebase('/api/v1/merchant/support/4/files/9', base),
      'https://zajel.wahajiq.net/api/v1/merchant/support/4/files/9',
    );
    // نسخة العرض بلا عنوان: كما هي
    expect(rebase('assets/x.jpg', ''), 'assets/x.jpg');
  });
}
