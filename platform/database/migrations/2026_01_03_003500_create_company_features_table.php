<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ميزات كل شركة كما قرّرها صاحب المنصّة (App\Enums\Feature، docs/plan/35).
 *
 * كل صفٍّ قرارٌ بمدّته: مفتوحةٌ أو مغلقة، برسمها الشهريّ، من starts_at حتى ends_at،
 * والقرار الساري ends_at فيه فارغ. فالتغيير يُغلق الساري ويبدأ غيره، ومن المُدد تُحسب
 * فاتورة الشهر يوماً بيوم: ميزةٌ فُتحت في منتصفه تُحسب بأيّامها.
 * وميزةٌ لا صفّ لها في شركةٍ على حالها في القائمة: ما كان في النظام مفتوح، والجديدة مطفأة.
 *
 * ورسم الميزة في الفاتورة سطرٌ من نوع addon، ومجموعه في features_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->boolean('enabled');
            $table->unsignedInteger('monthly_price')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // مَن قرّر من المنصّة — والتفصيل في سجلّ التدقيق
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'feature', 'ends_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('features_amount')->default(0)->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('features_amount'));
        Schema::dropIfExists('company_features');
    }
};
