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
        // Add missing columns to alliance_tax_invoices
        if (Schema::hasTable('alliance_tax_invoices')) {
            Schema::table('alliance_tax_invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('alliance_tax_invoices', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('status');
                }
            });
        }

        // Add missing columns to alliance_tax_calculations
        if (Schema::hasTable('alliance_tax_calculations')) {
            Schema::table('alliance_tax_calculations', function (Blueprint $table) {
                if (!Schema::hasColumn('alliance_tax_calculations', 'tax_amount_gross')) {
                    $table->decimal('tax_amount_gross', 20, 2)->nullable()->after('tax_amount');
                }
                
                if (!Schema::hasColumn('alliance_tax_calculations', 'credit_applied')) {
                    $table->decimal('credit_applied', 20, 2)->default(0)->after('tax_amount_gross');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('alliance_tax_invoices')) {
            Schema::table('alliance_tax_invoices', function (Blueprint $table) {
                $table->dropColumn('paid_at');
            });
        }

        if (Schema::hasTable('alliance_tax_calculations')) {
            Schema::table('alliance_tax_calculations', function (Blueprint $table) {
                $table->dropColumn(['tax_amount_gross', 'credit_applied']);
            });
        }
    }
};
