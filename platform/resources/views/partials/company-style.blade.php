{{-- لون شعار الشركة، ومظهر نظامها كما اختارته المنصّة: درجات لون التمييز (App\Support\Theme) --}}
<style>:root { --company: {{ $company->primary_color }}; {{ $company->theme()->css() }}}</style>
