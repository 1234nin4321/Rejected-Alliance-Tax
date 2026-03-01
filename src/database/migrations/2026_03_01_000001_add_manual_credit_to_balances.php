<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('alliance_tax_balances', function (Blueprint $table) {
            // Add manual adjustment column
            $table->decimal('manual_credit', 20, 2)->default(0)->after('balance');
            $table->string('manual_credit_reason')->nullable()->after('manual_credit');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alliance_tax_balances', function (Blueprint $table) {
            $table->dropColumn(['manual_credit', 'manual_credit_reason']);
        });
    }
};
