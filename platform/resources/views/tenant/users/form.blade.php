@extends('layouts.app')
@section('title', $staff->exists ? 'تعديل مستخدم' : 'مستخدم جديد')

@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="text-xl font-bold">{{ $staff->exists ? 'تعديل ' . $staff->name : 'مستخدم جديد' }}</h1>
    <a href="{{ route('users.index') }}" class="btn-ghost">رجوع</a>
</div>

<form method="POST" action="{{ $staff->exists ? route('users.update', $staff) : route('users.store') }}"
      class="card max-w-2xl space-y-4 p-5">
    @csrf
    @if ($staff->exists) @method('PUT') @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="field-label" for="name">الاسم <span class="text-red-500">*</span></label>
            <input id="name" name="name" class="field-input" required value="{{ old('name', $staff->name) }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="phone">الهاتف <span class="text-red-500">*</span></label>
            <input id="phone" name="phone" class="field-input text-left" dir="ltr" required
                   placeholder="07xxxxxxxxx" value="{{ old('phone', $staff->phone) }}">
            <p class="mt-1 text-xs text-slate-500">هو اسم الدخول.</p>
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="email">البريد</label>
            <input id="email" name="email" type="email" class="field-input text-left" dir="ltr"
                   value="{{ old('email', $staff->email) }}">
        </div>
        <div>
            <label class="field-label" for="role">الدور <span class="text-red-500">*</span></label>
            <select id="role" name="role" class="field-input" required>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}"
                            @selected(old('role', $staff->role?->value ?? 'operations') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('role') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="field-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id" class="field-input">
                <option value="">كل الفروع</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}"
                            @selected((int) old('branch_id', $staff->branch_id) === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">
                تحديد فرع يقصر رؤيته على شحناته — عدا صاحب الشركة ومديرها.
            </p>
        </div>
        <div>
            <label class="field-label" for="password">
                كلمة المرور @unless ($staff->exists) <span class="text-red-500">*</span> @endunless
            </label>
            <input id="password" name="password" type="text" class="field-input text-left" dir="ltr"
                   placeholder="{{ $staff->exists ? 'اتركها فارغة لعدم التغيير' : '' }}"
                   @required(! $staff->exists)>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1"
               @checked(old('is_active', $staff->exists ? $staff->is_active : true))
               class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        الحساب مفعّل
    </label>

    <button type="submit" class="btn-primary w-full sm:w-auto">
        {{ $staff->exists ? 'حفظ' : 'أضف المستخدم' }}
    </button>
</form>
@endsection
