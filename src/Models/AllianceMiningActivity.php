<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceMiningActivity extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alliance_mining_activity';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'corporation_id',
        'alliance_id',
        'type_id',
        'type_name',
        'quantity',
        'estimated_value',
        'mining_date',
        'solar_system_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'mining_date' => 'datetime',
        'quantity' => 'integer',
        'estimated_value' => 'decimal:2',
    ];

    /**
     * Get the character that performed this mining activity.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the corporation this mining activity belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Scope a query to only include activity from a specific period.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $start
     * @param string $end
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePeriod($query, $start, $end)
    {
        return $query->whereBetween('mining_date', [$start, $end]);
    }

    /**
     * Scope a query to only include activity for a specific character.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $characterId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    /**
     * Scope a query to only include activity for a specific corporation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $corporationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCorporation($query, $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Scope a query to only include activity for a specific alliance.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $allianceId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAlliance($query, $allianceId)
    {
        return $query->where('alliance_id', $allianceId);
    }
}
