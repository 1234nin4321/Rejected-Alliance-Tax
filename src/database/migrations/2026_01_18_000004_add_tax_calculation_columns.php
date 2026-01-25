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
        // Add columns to alliance_tax_calculations table
        Schema::table('alliance_tax_calculations', function (Blueprint $table) {
            if (!Schema::hasColumn('alliance_tax_calculations', 'tax_period')) {
                $table->date('tax_period')->after('character_id')->index();
            }
            
            if (!Schema::hasColumn('alliance_tax_calculations', 'applicable_tax_rate')) {
                $table->decimal('applicable_tax_rate', 5, 2)->after('total_mined_value')->nullable();
            }
            
            if (!Schema::hasColumn('alliance_tax_calculations', 'status')) {
                $table->string('status')->after('tax_amount')->default('pending');
                $table->index('status', 'idx_atc_status');
            }
            
            if (!Schema::hasColumn('alliance_tax_calculations', 'calculated_at')) {
                $table->timestamp('calculated_at')->nullable()->after('status');
            }
        });
        
        // Add solar_system_id to alliance_mining_activity table
        Schema::table('alliance_mining_activity', function (Blueprint $table) {
            if (!Schema::hasColumn('alliance_mining_activity', 'solar_system_id')) {
                $table->unsignedInteger('solar_system_id')->nullable()->after('mining_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alliance_tax_calculations', function (Blueprint $table) {
            $table->dropColumn(['tax_period', 'applicable_tax_rate', 'status', 'calculated_at']);
        });
        
        Schema::table('alliance_mining_activity', function (Blueprint $table) {
            $table->dropColumn('solar_system_id');
        });
    }
};
