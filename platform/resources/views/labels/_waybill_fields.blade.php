{{-- خانات الوصل المطبوع: يكتبها التاجر بيده، واسمه مطبوعٌ إن كان الدفتر له --}}
<div class="wb-legend">معلومات الشحن</div>
<div class="wb-field">
    <span>اسم التاجر</span>
    @if ($merchantName)<b>{{ $merchantName }}</b>@else<i></i>@endif
</div>
<div class="wb-field"><span>اسم الزبون</span><i></i></div>
<div class="wb-field"><span>هاتف الزبون</span><i></i></div>
<div class="wb-pair">
    <div class="wb-field"><span>المحافظة</span><i></i></div>
    <div class="wb-field"><span>المنطقة</span><i></i></div>
</div>
<div class="wb-field"><span>أقرب نقطة دالّة</span><i></i></div>
<div class="wb-field wb-amount"><span>المبلغ</span><i></i><span>د.ع</span></div>
