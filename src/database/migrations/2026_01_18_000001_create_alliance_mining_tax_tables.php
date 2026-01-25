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
        // Mining activity tracking table
        Schema::create('alliance_mining_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->unsignedInteger('type_id'); // Ore/Ice type ID
            $table->string('type_name');
            $table->bigInteger('quantity');
            $table->decimal('estimated_value', 20, 2); // ISK value
            $table->timestamp('mining_date');
            $table->timestamps();

            // Custom index names to avoid MySQL 64-character limit
            $table->index(['character_id', 'mining_date'], 'idx_ama_char_date');
            $table->index(['corporation_id', 'mining_date'], 'idx_ama_corp_date');
            $table->index(['alliance_id', 'mining_date'], 'idx_ama_ally_date');
        });

        // Tax calculations table
        Schema::create('alliance_tax_calculations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('period_type'); // weekly, monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_mined_value', 20, 2);
            $table->decimal('tax_rate', 5, 2); // Percentage
            $table->decimal('tax_amount', 20, 2);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Custom index names to avoid MySQL 64-character limit
            $table->index(['character_id', 'period_start', 'period_end'], 'idx_atc_char_period');
            $table->index(['corporation_id', 'period_start', 'period_end'], 'idx_atc_corp_period');
            $table->index(['alliance_id', 'period_start', 'period_end'], 'idx_atc_ally_period');
            $table->index('is_paid', 'idx_atc_is_paid');
        });

        // Tax rates configuration table
        Schema::create('alliance_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->decimal('tax_rate', 5, 2); // Percentage
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            // Custom index names to avoid MySQL 64-character limit
            $table->index(['alliance_id', 'is_active'], 'idx_atr_ally_active');
            $table->index(['corporation_id', 'is_active'], 'idx_atr_corp_active');
        });

        // Exemptions table
        Schema::create('alliance_tax_exemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->string('reason');
            $table->date('exempt_from');
            $table->date('exempt_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by'); // User ID who created exemption
            $table->timestamps();

            // Custom index names to avoid MySQL 64-character limit
            $table->index(['character_id', 'is_active'], 'idx_ate_char_active');
            $table->index(['corporation_id', 'is_active'], 'idx_ate_corp_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alliance_tax_exemptions');
        Schema::dropIfExists('alliance_tax_rates');
        Schema::dropIfExists('alliance_tax_calculations');
        Schema::dropIfExists('alliance_mining_activity');
    }
};
