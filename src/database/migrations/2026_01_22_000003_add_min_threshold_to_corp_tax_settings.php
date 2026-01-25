<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMinThresholdToCorpTaxSettings extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('alliance_corp_ratting_tax_settings', function (Blueprint $table) {
            $table->decimal('min_threshold', 20, 2)->default(0.00)->after('tax_rate');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alliance_corp_ratting_tax_settings', function (Blueprint $table) {
            $table->dropColumn('min_threshold');
        });
    }
}
