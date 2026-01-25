<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;

class AllianceTaxSystem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alliance_tax_systems';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'solar_system_id',
        'solar_system_name',
    ];
}
