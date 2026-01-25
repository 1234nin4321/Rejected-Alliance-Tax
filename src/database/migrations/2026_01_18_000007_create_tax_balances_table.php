<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaxBalancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('alliance_tax_balances', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('character_id')->unsigned()->unique();
            $table->decimal('balance', 20, 2)->default(0);
            $table->timestamps();
            
            $table->index('character_id');
        });
        
        Schema::table('alliance_tax_calculations', function (Blueprint $table) {
            $table->decimal('tax_amount_gross', 20, 2)->after('tax_amount')->nullable();
            $table->decimal('credit_applied', 20, 2)->after('tax_amount_gross')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alliance_tax_balances');
        
        Schema::table('alliance_tax_calculations', function (Blueprint $table) {
            $table->dropColumn(['tax_amount_gross', 'credit_applied']);
        });
    }
}
