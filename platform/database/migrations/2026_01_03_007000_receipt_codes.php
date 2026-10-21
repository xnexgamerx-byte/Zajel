<?php

use App\Support\ReceiptCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رمز الاستلام على وصل حساب التاجر وإيصال الراجع (docs/plan/69): التاجر يؤكّد ما وصله
 * بمسح الورقة التي جاءت معه. ولكلّ ورقةٍ قديمة رمزها، فتُطبع ثانيةً وتُمسح.
 */
return new class extends Migration
{
    private const TABLES = ['merchant_settlements', 'return_batches'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->char('receipt_code', 10)->nullable()->unique();
            });

            DB::table($name)->whereNull('receipt_code')->orderBy('id')->select('id')
                ->chunkById(500, function ($rows) use ($name) {
                    foreach ($rows as $row) {
                        DB::table($name)->where('id', $row->id)->update(['receipt_code' => ReceiptCode::generate()]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['receipt_code']);
                $table->dropColumn('receipt_code');
            });
        }
    }
};
