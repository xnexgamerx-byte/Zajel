<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\NotifyTexts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «نصوص الإشعارات» (docs/plan/91): ما يصل هواتف التجّار والمناديب والكول سنتر، بكلام الشركة. لمن يملك
 * notify.texts — مدير الشركة افتراضاً، ومن يمنحه.
 */
class NotifyTextController extends Controller
{
    public function edit(TenantContext $tenant): View
    {
        $company = $tenant->company();

        return view('tenant.notify-texts', [
            'groups' => collect(NotifyTexts::catalog())->groupBy('audience', preserveKeys: true),
            'own' => collect(NotifyTexts::catalog())->mapWithKeys(fn ($text, $key) => [$key => NotifyTexts::stored($company, $key)]),
        ]);
    }

    public function update(Request $request, TenantContext $tenant): RedirectResponse
    {
        $company = $tenant->company();
        $catalog = NotifyTexts::catalog();

        $request->validate([
            'texts' => ['required', 'array'],
            'texts.*.title' => ['nullable', 'string', 'max:'.NotifyTexts::MAX_TITLE],
            'texts.*.body' => ['nullable', 'string', 'max:'.NotifyTexts::MAX_BODY],
            'reset' => ['nullable', 'array'],
        ], [
            'texts.*.title.max' => 'العنوان '.NotifyTexts::MAX_TITLE.' حرفاً على الأكثر.',
            'texts.*.body.max' => 'النصّ '.NotifyTexts::MAX_BODY.' حرفاً على الأكثر.',
        ]);

        $before = collect($catalog)->mapWithKeys(fn ($text, $key) => [$key => NotifyTexts::stored($company, $key)])->filter()->all();
        $after = [];
        $errors = [];

        foreach ($catalog as $key => $text) {
            if ($request->boolean("reset.{$key}")) {
                continue;
            }

            foreach (['title', 'body'] as $part) {
                $value = NotifyTexts::normalise($key, $request->input("texts.{$key}.{$part}"), $part);

                if ($value === null) {
                    continue;
                }

                if ($unknown = NotifyTexts::unknownFields($key, $value)) {
                    $errors["texts.{$key}.{$part}"] = 'خانةٌ لا تُعرف في هذا الإشعار: '.implode('، ', $unknown).'. الخانات المعروفة تحته.';
                }

                $after[$key][$part] = $value;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $settings = $company->settings ?? [];
        $settings['notify_texts'] = $after;
        if ($after === []) {
            unset($settings['notify_texts']);
        }
        $company->forceFill(['settings' => $settings])->save();

        // ما تغيّر وحده، وبمَن غيّره: نصٌّ وصل التجّار يُسأل عنه
        $changed = collect(array_keys($catalog))->filter(fn ($key) => ($before[$key] ?? []) != ($after[$key] ?? []))->values()->all();

        if ($changed) {
            AuditLog::create([
                'company_id' => $company->id,
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'action' => 'notify_texts_updated',
                'old_values' => array_intersect_key($before, array_flip($changed)),
                'new_values' => array_intersect_key($after, array_flip($changed)),
                'ip' => $request->ip(),
            ]);
        }

        return back()->with('success', $changed ? 'حُفظت نصوص الإشعارات: تصل بها الهواتف من الآن.' : 'لم يتغيّر شيء.');
    }
}
