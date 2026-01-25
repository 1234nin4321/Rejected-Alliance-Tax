<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceTaxExemption extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alliance_tax_exemptions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'corporation_id',
        'reason',
        'exempt_from',
        'exempt_until',
        'is_active',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'exempt_from' => 'date',
        'exempt_until' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the character this exemption is for.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the corporation this exemption is for.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Scope a query to only include active exemptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('exempt_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('exempt_until')
                  ->orWhere('exempt_until', '>=', now());
            });
    }

    /**
     * Check if a character is currently exempt.
     *
     * @param int $characterId
     * @return bool
     */
    public static function isCharacterExempt($characterId)
    {
        return self::active()
            ->where('character_id', $characterId)
            ->exists();
    }

    /**
     * Check if a corporation is currently exempt.
     *
     * @param int $corporationId
     * @return bool
     */
    public static function isCorporationExempt($corporationId)
    {
        return self::active()
            ->where('corporation_id', $corporationId)
            ->exists();
    }
}
