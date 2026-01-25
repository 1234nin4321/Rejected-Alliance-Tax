<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllianceCorpRattingTaxTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Settings for corporate ratting tax per corporation
        Schema::create('alliance_corp_ratting_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('corporation_id')->unique();
            $table->decimal('tax_rate', 5, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Calculations/Invoices for corporate ratting tax
        Schema::create('alliance_corp_ratting_tax_calculations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('corporation_id');
            $table->decimal('total_bounty_value', 20, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('tax_amount', 20, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('pending'); // pending, paid, cancelled
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['corporation_id', 'status'], 'corp_ratting_tax_status');
            $table->index(['period_start', 'period_end'], 'corp_ratting_tax_period');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alliance_corp_ratting_tax_calculations');
        Schema::dropIfExists('alliance_corp_ratting_tax_settings');
    }
}
