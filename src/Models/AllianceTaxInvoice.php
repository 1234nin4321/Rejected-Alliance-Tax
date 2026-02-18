<?php

namespace Rejected\SeatAllianceTax\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class AllianceTaxInvoice extends Model
{
    protected $table = 'alliance_tax_invoices';

    protected $fillable = [
        'tax_calculation_id',
        'character_id',
        'corporation_id',
        'amount',
        'invoice_date',
        'due_date',
        'status',
        'paid_at',
        'payment_ref_id',
        'notified_at',
        'invoice_note',
        'metadata',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount' => 'integer',
        'notified_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function taxCalculation()
    {
        return $this->belongsTo(AllianceTaxCalculation::class);
    }

    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['sent', 'partial']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
                     ->orWhere(function($q) {
                         $q->whereIn('status', ['sent', 'partial'])
                           ->where('due_date', '<', now());
                     });
    }

    public function markAsPaid()
    {
        $this->status = 'paid';
        return $this->save();
    }
}
