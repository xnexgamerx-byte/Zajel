<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «مندوب التوصيل الأب» و«المندوبون الفرعيّون»، و«أرباح مندوب الاستلام»
 * بشراكته، كما في المعتاد:
 *
 * المندوب الفرعيّ يعمل تحت آخر: الشحنات باسمه وفي تطبيقه، والأب يرى
 * فريقه ويُسوّى معه كشوفهم دفعةً واحدة. مستوىً واحد لا شجرة.
 *
 * ومندوب الاستلام الشريك: يُدفع له ما استحقّه بعد حصّة المركز — نسبةً أو
 * مبلغاً — وكل دفعةٍ برقمها وما ذهب للشريك وما بقي للمركز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('branch_id')->constrained('couriers')->nullOnDelete();
            $table->string('partner_centre_type', 10)->default('none')->after('commission_per_return'); // none | percent | amount
            $table->unsignedInteger('partner_centre_value')->default(0)->after('partner_centre_type');
        });

        Schema::create('pickup_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->unsignedBigInteger('earned');
            $table->string('centre_type', 10)->default('none');
            $table->unsignedInteger('centre_value')->default(0);
            $table->unsignedBigInteger('centre_amount')->default(0);
            $table->unsignedBigInteger('paid_amount');
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'courier_id', 'created_at'], 'pp_courier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_payouts');

        Schema::table('couriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['partner_centre_type', 'partner_centre_value']);
        });
    }
};
