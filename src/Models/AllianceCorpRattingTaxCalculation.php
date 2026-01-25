<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceCorpRattingTaxCalculation extends Model
{
    protected $table = 'alliance_corp_ratting_tax_calculations';

    protected $fillable = [
        'corporation_id',
        'total_bounty_value',
        'tax_rate',
        'tax_amount',
        'period_start',
        'period_end',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'total_bounty_value' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
