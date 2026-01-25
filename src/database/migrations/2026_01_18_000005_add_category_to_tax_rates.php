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
        Schema::table('alliance_tax_rates', function (Blueprint $table) {
            // Add category column to allow different rates for Ore, Ice, and Moon Ore
            $table->string('item_category')->default('all')->after('tax_rate');
            
            // Update indices to include category
            $table->index(['alliance_id', 'item_category', 'is_active'], 'idx_atr_ally_cat_active');
            $table->index(['corporation_id', 'item_category', 'is_active'], 'idx_atr_corp_cat_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alliance_tax_rates', function (Blueprint $table) {
            $table->dropIndex('idx_atr_ally_cat_active');
            $table->dropIndex('idx_atr_corp_cat_active');
            $table->dropColumn('item_category');
        });
    }
};
