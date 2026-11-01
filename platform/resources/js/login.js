/*
 * صفحات الدخول (docs/plan/105). الحركة كلّها CSS في login.css (التيار في مسارات الرسم)، وهنا وحده زرّ
 * إظهار كلمة المرور: data-password-toggle="id الحقل". سكربتٌ في ملفّه لا في الصفحة، كما تشترط سياسة المحتوى.
 */
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-password-toggle]') : null;
    if (!button) return;

    const field = document.getElementById(button.dataset.passwordToggle);
    if (!field) return;

    const show = field.type === 'password';
    field.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', show ? 'true' : 'false');
    button.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    field.focus();
});
