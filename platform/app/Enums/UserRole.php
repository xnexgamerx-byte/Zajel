<?php

namespace App\Enums;

enum UserRole: string
{
    // النواة — company_id = null
    case PlatformAdmin   = 'platform_admin';
    case PlatformSupport = 'platform_support';

    // داخل شركة مستأجِرة
    case CompanyOwner    = 'company_owner';
    case CompanyAdmin    = 'company_admin';
    case BranchManager   = 'branch_manager';
    case Operations      = 'operations';
    case CustomerService = 'customer_service';
    case Accountant      = 'accountant';
    case Courier         = 'courier';
    case Merchant        = 'merchant';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin   => 'مدير المنصّة',
            self::PlatformSupport => 'دعم المنصّة',
            self::CompanyOwner    => 'صاحب الشركة',
            self::CompanyAdmin    => 'مدير الشركة',
            self::BranchManager   => 'مدير فرع',
            self::Operations      => 'عمليات',
            self::CustomerService => 'خدمة العملاء',
            self::Accountant      => 'محاسب',
            self::Courier         => 'مندوب',
            self::Merchant        => 'تاجر',
        };
    }

    public function isPlatform(): bool
    {
        return in_array($this, [self::PlatformAdmin, self::PlatformSupport], true);
    }

    public static function platformRoles(): array
    {
        return [self::PlatformAdmin, self::PlatformSupport];
    }
}
