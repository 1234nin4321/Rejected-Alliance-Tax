<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAllianceTaxSystemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('alliance_tax_systems', function (Blueprint $column) {
            $column->bigIncrements('id');
            $column->unsignedInteger('solar_system_id')->index();
            $column->string('solar_system_name');
            $column->timestamps();
            
            $column->unique('solar_system_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alliance_tax_systems');
    }
}
