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
        Schema::create('alliance_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, decimal
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('key');
        });

        // Insert default settings
        DB::table('alliance_tax_settings')->insert([
            [
                'key' => 'alliance_id',
                'value' => null,
                'type' => 'integer',
                'description' => 'Your alliance ID for tax tracking',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_tax_rate',
                'value' => '10.0',
                'type' => 'decimal',
                'description' => 'Default tax rate percentage (if no specific rate is set)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'tax_period',
                'value' => 'weekly',
                'type' => 'string',
                'description' => 'Tax calculation period (weekly or monthly)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'minimum_taxable_amount',
                'value' => '1000000',
                'type' => 'integer',
                'description' => 'Minimum mining value to be taxed (in ISK)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'auto_calculate',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Enable automatic tax calculations',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alliance_tax_settings');
    }
};
