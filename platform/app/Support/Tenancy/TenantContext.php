<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;

/**
 * سياق الشركة الحالي — كائن مفرد (singleton) يُحدَّد مرّة واحدة في الطلب
 * (من النطاق الفرعي أو من المستخدم المسجَّل) ثم يقرأه كل استعلام.
 *
 * ثلاث حالات فقط:
 *   1. شركة محدّدة   -> كل استعلام مفلتر تلقائياً بـ company_id
 *   2. وضع النواة     -> لا فلترة، ويُدخَل إليه بشكل صريح فقط
 *   3. بلا سياق       -> أي استعلام على جدول مقيّد يرمي استثناءً
 */
class TenantContext
{
    protected ?Company $company = null;

    protected bool $platform = false;

    public function set(?Company $company): static
    {
        $this->company = $company;
        $this->platform = false;

        return $this;
    }

    /** يُدخِل الطلب كلّه في وضع النواة (لوحة التحكّم المركزية، الأوامر الإدارية). */
    public function usePlatform(): static
    {
        $this->company = null;
        $this->platform = true;

        return $this;
    }

    public function forget(): static
    {
        $this->company = null;
        $this->platform = false;

        return $this;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    /** ينفّذ الإغلاق داخل سياق شركة ثم يعيد السياق السابق كما كان. */
    public function runFor(Company $company, Closure $callback): mixed
    {
        [$prevCompany, $prevPlatform] = [$this->company, $this->platform];

        $this->company = $company;
        $this->platform = false;

        try {
            return $callback($company);
        } finally {
            $this->company = $prevCompany;
            $this->platform = $prevPlatform;
        }
    }

    /**
     * ينفّذ الإغلاق بلا فلترة — للنواة فقط (تقارير عبر الشركات، الفوترة،
     * مهام الصيانة). صريح دائماً، ولا يُستخدم داخل مسارات الشركات.
     */
    public function runAsPlatform(Closure $callback): mixed
    {
        [$prevCompany, $prevPlatform] = [$this->company, $this->platform];

        $this->company = null;
        $this->platform = true;

        try {
            return $callback();
        } finally {
            $this->company = $prevCompany;
            $this->platform = $prevPlatform;
        }
    }
}
