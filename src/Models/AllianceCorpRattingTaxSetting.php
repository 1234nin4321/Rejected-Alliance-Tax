<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceCorpRattingTaxSetting extends Model
{
    protected $table = 'alliance_corp_ratting_tax_settings';

    protected $fillable = [
        'corporation_id',
        'tax_rate',
        'min_threshold',
        'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'min_threshold' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }
}
