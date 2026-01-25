<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceTaxCalculation extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alliance_tax_calculations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'corporation_id',
        'alliance_id',
        'tax_period',
        'period_type',
        'period_start',
        'period_end',
        'total_mined_value',
        'tax_rate',
        'applicable_tax_rate',
        'tax_amount',
        'tax_amount_gross',
        'credit_applied',
        'is_paid',
        'status',
        'paid_at',
        'calculated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_period' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'total_mined_value' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'applicable_tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_amount_gross' => 'decimal:2',
        'credit_applied' => 'decimal:2',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
        'calculated_at' => 'datetime',
    ];

    /**
     * Get the character this tax calculation is for.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the corporation this tax calculation belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Get the invoice for this tax calculation.
     */
    public function invoice()
    {
        return $this->hasOne(\Rejected\SeatAllianceTax\Models\AllianceTaxInvoice::class);
    }

    /**
     * Scope a query to only include unpaid taxes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnpaid($query)
    {
        return $query->where('is_paid', false);
    }

    /**
     * Scope a query to only include paid taxes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePaid($query)
    {
        return $query->where('is_paid', true);
    }

    /**
     * Scope a query to only include calculations for a specific character.
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
     * Mark this tax calculation as paid.
     *
     * @return bool
     */
    public function markAsPaid()
    {
        $this->is_paid = true;
        $this->paid_at = now();
        return $this->save();
    }

    /**
     * Calculate tax amount based on rate and mined value.
     *
     * @return float
     */
    public function calculateTaxAmount()
    {
        return ($this->total_mined_value * $this->tax_rate) / 100;
    }
}
