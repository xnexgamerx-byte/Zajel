<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TenantContext set(?Company $company)
 * @method static TenantContext usePlatform()
 * @method static TenantContext forget()
 * @method static Company|null company()
 * @method static int|null id()
 * @method static bool has()
 * @method static bool isPlatform()
 * @method static mixed runFor(Company $company, Closure $callback)
 * @method static mixed runAsPlatform(Closure $callback)
 *
 * @see TenantContext
 */
class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
