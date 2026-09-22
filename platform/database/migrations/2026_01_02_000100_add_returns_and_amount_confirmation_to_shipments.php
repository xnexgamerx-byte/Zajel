<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            /*
            | الراجع خطوتان لا واحدة: أن يُعيده المندوب إلى المخزن، ثم أن
            | يُسلَّم إلى التاجر. دمجهما يعني طرداً "راجعاً" في الورق وهو
            | في حقيبة مندوب منذ أسبوعين — وهذا أكثر ما يُختلف عليه.
            | التسليم للتاجر هو حالة returned نفسها بتاريخها returned_at.
            */
            $table->timestamp('return_received_at')->nullable()->after('returned_at');
            $table->foreignId('return_received_by_user_id')->nullable()->after('return_received_at');

            /*
            | تأكيد مبلغ الوصل: بعده لا يُعدَّل المبلغ المحصَّل إطلاقاً.
            | نصف الخلافات المالية تبدأ بمبلغ عُدّل بعد أن بُنيت عليه تسوية.
            */
            $table->boolean('amount_confirmed')->default(false)->after('collected_amount');
            $table->timestamp('amount_confirmed_at')->nullable()->after('amount_confirmed');
            $table->foreignId('amount_confirmed_by_user_id')->nullable()->after('amount_confirmed_at');

            // طابور الراجع: الشحنات في طريق العودة غير المستلمة بعد
            $table->index(['company_id', 'status', 'return_received_at'], 'sh_return_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_return_idx');
            $table->dropColumn([
                'return_received_at', 'return_received_by_user_id',
                'amount_confirmed', 'amount_confirmed_at', 'amount_confirmed_by_user_id',
            ]);
        });
    }
};
