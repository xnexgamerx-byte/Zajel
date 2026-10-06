<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Scopes\CurrentCompanyScope;
use App\Support\Theme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    /** @var array<string, CompanyFeature>|null قرارات المنصّة السارية في ميزاتها، تُقرأ مرّةً لكل كائن */
    protected ?array $featureMemo = null;

    protected function casts(): array
    {
        return [
            'settings'      => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new CurrentCompanyScope);

        static::creating(function (self $company) {
            $company->uuid ??= (string) Str::uuid();
        });
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasMany
    {
        return $this->subscriptions()->whereIn('status', ['trialing', 'active']);
    }

    /** قرارات المنصّة في ميزاتها، الساري منها وما مضى (docs/plan/35) */
    public function featureDecisions(): HasMany
    {
        return $this->hasMany(CompanyFeature::class);
    }

    /**
     * القرار الساري في كل ميزة: [مفتاحها => صفّه]. يُقرأ مرّةً لكل كائن شركة، والشركة
     * الحالية كائنٌ واحدٌ في الطلب: استعلامٌ واحد مهما كثرت الروابط التي تسأل.
     *
     * @return array<string, CompanyFeature>
     */
    public function currentFeatures(): array
    {
        return $this->featureMemo ??= CompanyFeature::acrossCompanies()
            ->where('company_id', $this->id)
            ->current()
            ->get()
            ->keyBy('feature')
            ->all();
    }

    /** الميزة مفتوحةٌ في هذه الشركة: بقرار المنصّة إن قرّرت، وإلّا بحالها في القائمة */
    public function hasFeature(Feature|string $feature): bool
    {
        $feature = $feature instanceof Feature ? $feature : Feature::tryFrom($feature);

        if ($feature === null) {
            return false;
        }

        $decision = $this->currentFeatures()[$feature->value] ?? null;

        return $decision ? $decision->enabled : $feature->included();
    }

    /**
     * كل ميزةٍ وحالها في الشركة: مفتوحة؟ برسمٍ كم؟ منذ متى؟ وهل قرّرت المنصّة فيها
     * أم هي على حالها في القائمة.
     *
     * @return list<array{feature: Feature, enabled: bool, price: int, since: ?\Illuminate\Support\Carbon, decided: bool}>
     */
    public function featureStates(): array
    {
        $current = $this->currentFeatures();

        return array_map(fn (Feature $feature) => [
            'feature' => $feature,
            'enabled' => $this->hasFeature($feature),
            'price'   => (int) ($current[$feature->value]->monthly_price ?? 0),
            'since'   => $current[$feature->value]->starts_at ?? null,
            'decided' => isset($current[$feature->value]),
        ], Feature::cases());
    }

    /** بعد قرارٍ جديد في كائنٍ قُرئت ميزاته */
    public function forgetFeatures(): void
    {
        $this->featureMemo = null;
    }

    /** مظهر نظامها كما اختارته المنصّة: درجات لونه */
    public function theme(): Theme
    {
        return Theme::for($this);
    }

    /** حرف الشعار: أوّل حرفٍ من الاسم بعد «ال» — «ز» للزاجل، و«ب» للبرق. */
    public function initial(): string
    {
        return mb_substr((string) preg_replace('/^ال(?=\S)/u', '', trim((string) $this->name)), 0, 1);
    }

    public function isOperational(): bool
    {
        return in_array($this->status, ['trial', 'active'], true);
    }

    /** قراءة مفتاح من settings بمسار منقوط. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * واتساب الدعم لوجهةٍ بعينها: رقم محافظتها إن كان لها رقم في «إعدادات
     * المحافظات»، وإلّا رقم الشركة العامّ — زبون البصرة يكلّم فرع البصرة.
     */
    public function supportWhatsappFor(?int $governorateId = null): ?string
    {
        $own = $governorateId === null ? null : DB::table('governorate_settings')
            ->where('company_id', $this->id)->where('governorate_id', $governorateId)->value('whatsapp');

        return $own ?: $this->setting('support.whatsapp');
    }
}
