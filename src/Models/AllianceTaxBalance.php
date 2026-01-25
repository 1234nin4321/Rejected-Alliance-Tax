<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;

class AllianceTaxBalance extends Model
{
    protected $table = 'alliance_tax_balances';

    protected $fillable = [
        'character_id',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];
}
