<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A single payment transaction can legitimately cover multiple invoices
     * (e.g., one large donation split across two invoices). The unique
     * constraint on payment_ref_id must be dropped to support this.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('alliance_tax_invoices')) {
            Schema::table('alliance_tax_invoices', function (Blueprint $table) {
                // Drop the unique index — the column name may vary by convention
                // Laravel creates unique indexes as {table}_{column}_unique
                $table->dropUnique('alliance_tax_invoices_payment_ref_id_unique');

                // Re-add as a normal index for query performance
                $table->index('payment_ref_id');
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
                $table->dropIndex(['payment_ref_id']);
                $table->unique('payment_ref_id');
            });
        }
    }
};
