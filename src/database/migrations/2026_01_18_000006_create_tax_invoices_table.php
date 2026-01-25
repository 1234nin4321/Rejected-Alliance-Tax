<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('alliance_tax_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_calculation_id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('corporation_id');
            $table->decimal('amount', 20, 2);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status')->default('sent'); // sent, paid, overdue
            $table->timestamp('notified_at')->nullable();
            $table->text('invoice_note')->nullable();
            $table->text('metadata')->nullable(); // JSON metadata for consolidated invoices
            $table->timestamps();

            $table->index('character_id');
            $table->index('status');
            $table->index('due_date');
            $table->foreign('tax_calculation_id')->references('id')->on('alliance_tax_calculations')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('alliance_tax_invoices');
    }
};
