<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;

class AllianceTaxRate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alliance_tax_rates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'alliance_id',
        'corporation_id',
        'tax_rate',
        'item_category',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    /**
     * Scope a query to only include active rates.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', now());
            });
    }

    /**
     * Get the applicable tax rate for a given entity.
     *
     * @param int|null $allianceId
     * @param int|null $corporationId
     * @return float
     */
    public static function getApplicableRate($allianceId = null, $corporationId = null)
    {
        // Corporation-specific rate takes precedence
        if ($corporationId) {
            $rate = self::active()
                ->where('corporation_id', $corporationId)
                ->first();
            
            if ($rate) {
                return $rate->tax_rate;
            }
        }

        // Then alliance rate
        if ($allianceId) {
            $rate = self::active()
                ->where('alliance_id', $allianceId)
                ->first();
            
            if ($rate) {
                return $rate->tax_rate;
            }
        }

        // Default rate from config
        return config('alliancetax.default_tax_rate', 10.0);
    }
}
