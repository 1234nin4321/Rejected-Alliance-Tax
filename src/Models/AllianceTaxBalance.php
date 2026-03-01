<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;

class AllianceTaxBalance extends Model
{
    protected $table = 'alliance_tax_balances';

    protected $fillable = [
        'character_id',
        'balance',
        'manual_credit',
        'manual_credit_reason',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'manual_credit' => 'decimal:2',
    ];

}
