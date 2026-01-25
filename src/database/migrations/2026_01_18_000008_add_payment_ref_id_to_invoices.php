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
        if (Schema::hasTable('alliance_tax_invoices')) {
            Schema::table('alliance_tax_invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('alliance_tax_invoices', 'payment_ref_id')) {
                    $table->bigInteger('payment_ref_id')->nullable()->unique()->after('amount');
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
                $table->dropColumn('payment_ref_id');
            });
        }
    }
};
