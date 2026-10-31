<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الربط مع نظام الشركة الوسيطة (docs/plan/104): ما تُنشئه عندهم شحناتنا، وما يصلنا منهم من حالات.
 *
 * - aggregator_links: ربط الشركة (واحدٌ لكل شركة) — حسابها عندهم، وتاجرها، والبادئة، ومفتاح الـ Webhook،
 *   وربط حالاتهم بأفعالنا. الأسرار مشفّرةٌ بمفتاح التطبيق، ومفتاح الـ Webhook ببصمته وحدها.
 * - aggregator_places: محافظاتنا ومناطقنا بمدنهم ومناطقهم.
 * - aggregator_jobs: ما يُرسَل لهم بالمجدوِل: إنشاء الطلب، واستلام الراجع. يُعاد ما فشل.
 * - aggregator_events: كل تحديثٍ وصلنا منهم كما وصل، وما فُعل به.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregator_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('base_url', 200);
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();
            $table->text('aggre_token')->nullable();
            $table->timestamp('aggre_token_at')->nullable();
            $table->string('merchant_id', 40)->nullable();
            $table->string('merchant_name', 150)->nullable();
            $table->text('merchant_token')->nullable();
            $table->string('order_prefix', 30)->nullable();
            $table->string('package_size_id', 20)->nullable();
            $table->foreignId('failure_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('postpone_reason_id')->nullable()->constrained('failure_reasons')->nullOnDelete();
            $table->string('webhook_header', 60)->default('X-Webhook-Key');
            $table->string('webhook_token_hash', 64)->nullable();
            $table->string('webhook_token_last4', 4)->nullable();
            $table->json('catalog')->nullable();
            $table->json('status_map')->nullable();
            $table->boolean('sends_orders')->default(false);
            $table->timestamps();
        });

        Schema::create('aggregator_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // governorate ← مدينتهم، city ← منطقتهم
            $table->unsignedBigInteger('local_id');
            $table->string('remote_id', 20);
            $table->string('remote_name', 150)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'local_id']);
        });

        Schema::create('aggregator_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // create | receive_return
            $table->string('status', 12)->default('pending'); // pending | done | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'next_attempt_at']);
            $table->index(['shipment_id', 'action']);
        });

        Schema::create('aggregator_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('remote_order_id', 40)->nullable();
            $table->string('company_order_id', 60)->nullable();
            $table->string('status_id', 10)->nullable();
            $table->string('status_text', 150)->nullable();
            $table->boolean('deliver_done')->default(false);
            $table->bigInteger('price')->nullable();
            $table->bigInteger('old_price')->nullable();
            $table->string('fingerprint', 64);
            $table->string('result', 12); // applied | recorded | duplicate | unmatched | error
            $table->string('message', 500)->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('aggregator_order_id', 40)->nullable()->after('courier_return_receipt_id');
            $table->index(['company_id', 'aggregator_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'aggregator_order_id']);
            $table->dropColumn('aggregator_order_id');
        });
        Schema::dropIfExists('aggregator_events');
        Schema::dropIfExists('aggregator_jobs');
        Schema::dropIfExists('aggregator_places');
        Schema::dropIfExists('aggregator_links');
    }
};
