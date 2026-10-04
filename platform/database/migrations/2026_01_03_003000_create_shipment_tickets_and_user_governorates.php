<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المبلغ بيد الكول سنتر لا المندوب (docs/plan/30).
 *
 * كان المندوب يكتب «المبلغ المستلم» كما يشاء عند الباب، ولا يصل الشركة شيء.
 * الآن يسلّم بالمبلغ الأصلي وحده، وإن قال الزبون غيره فتح «طلب تغيير المبلغ»:
 * تذكرةٌ تصل موظّفة الكول سنتر المختصّة بمحافظة الشحنة، فتعتمده أو ترفضه.
 *
 * و«محافظات الاختصاص» لكل موظّف: معالجة الشحنات وتذاكرها ومحادثاتها لمن
 * اختصّ بمحافظتها وحده. ومن لا محافظات له يرى كلّها، كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            // وجهة الشحنة حين فُتحت: بها تصل الموظّفة المختصّة
            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20); // price | partial
            $table->unsignedBigInteger('current_amount');
            $table->unsignedBigInteger('requested_amount');
            $table->string('reason', 255);
            $table->string('status', 20)->default('open'); // open | approved | rejected | closed
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->string('reply', 255)->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            // الواصل الجزئي المعتمد: متى سلّمه المندوب به
            $table->timestamp('used_at')->nullable();
            // أُغلقت وحدها: خرجت الشحنة من يد المندوب قبل أن تُعالَج أو يُسلَّم بها
            $table->string('closed_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'governorate_id'], 'st_queue_idx');
            $table->index(['shipment_id', 'status'], 'st_shipment_idx');
        });

        Schema::create('user_governorates', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'governorate_id']);
        });

        /*
        | من يغيّر حالة الشحنة في مرتبته كان يعالج ما لم يُسلَّم: له تذاكرها أيضاً.
        | صلاحيةٌ جديدة لا تُنقص مرتبةً شيئاً، وصاحب الشركة ينزعها متى شاء.
        */
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('shipments.status', $abilities, true)
                && ! in_array('tickets.handle', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode([...$abilities, 'tickets.handle'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_governorates');
        Schema::dropIfExists('shipment_tickets');
    }
};
