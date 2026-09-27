<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المراتب والصلاحيات الاستثنائية (docs/plan/17 §٥).
 *
 * المرتبة: اسمٌ تختاره الشركة («موظّف رواجع») وما يفتحه. والموظّف يحملها
 * فتحلّ محلّ افتراضي دوره، وتغييرها يسري على كل من يحملها.
 *
 * والاستثنائية: صلاحيةٌ لموظّفٍ بعينه فوق مرتبته، ومن منحها — تُسحب ولا تُنسى.
 *
 * ومعهما تنقسم صلاحيتان كما تنقسمان في المعتاد: «المستخدمون والتجّار
 * والمندوبون» ثلاثاً، و«التقارير المالية» عن التقارير. ومن خُصِّصت صلاحياته
 * قبل اليوم لا يفقد شيئاً: من كانت له الأولى نال الثلاث، ومن كانت له
 * التقارير نال المالية معها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('abilities');
            // القالب الذي بدأت منه — للعرض وحده
            $table->string('template', 40)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rank_id')->nullable()->after('role')->constrained('ranks')->nullOnDelete();
        });

        Schema::create('user_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ability', 60);
            $table->string('note', 255)->nullable();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // الاسم محفوظاً: من منح يبقى معروفاً ولو حُذف حسابه
            $table->string('granted_by_name', 160)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ability']);
            $table->index(['company_id', 'ability']);
        });

        $this->splitLegacyAbilities();
    }

    /** من خُصِّصت صلاحياته قبل المراتب لا يفقد شيئاً بانقسام الصلاحيتين. */
    public function splitLegacyAbilities(): void
    {
        $split = [
            'settings.people' => ['settings.merchants', 'settings.couriers', 'settings.users'],
            'reports.view'    => ['reports.view', 'reports.financial'],
        ];

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) use ($split) {
            $old = json_decode($user->permissions, true);

            if (! is_array($old)) {
                return;
            }

            $new = [];

            foreach ($old as $ability) {
                array_push($new, ...($split[$ability] ?? [$ability]));
            }

            $new = array_values(array_unique($new));

            if ($new !== $old) {
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($new)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rank_id');
        });

        Schema::dropIfExists('user_grants');
        Schema::dropIfExists('ranks');
    }
};
