@extends('web::layouts.grids.12')

@section('title', 'Tax Details')
@section('page_header', 'Tax Breakdown Details')

@section('full')

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-info-circle"></i> Tax Calculation Details
                </h3>
                <div class="box-tools">
                    <a href="{{ route('alliancetax.mytax.index') }}" class="btn btn-sm btn-default">
                        <i class="fa fa-arrow-left"></i> Back to My Taxes
                    </a>
                </div>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <th>Character:</th>
                                <td>
                                    {!! img('characters', 'portrait', $tax->character_id, 64, ['class' => 'img-circle']) !!}
                                    {{ $tax->character->name ?? 'Unknown' }}
                                </td>
                            </tr>
                            <tr>
                                <th>Tax Period:</th>
                                <td>{{ \Carbon\Carbon::parse($tax->tax_period)->format('Y-m-d') }}</td>
                            </tr>
                            <tr>
                                <th>Period Range:</th>
                                <td>
                                    {{ \Carbon\Carbon::parse($tax->period_start)->format('Y-m-d') }} 
                                    to 
                                    {{ \Carbon\Carbon::parse($tax->period_end)->format('Y-m-d') }}
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table">
                            <tr>
                                <th>Total Mined Value:</th>
                                <td class="text-right">{{ number_format($tax->total_mined_value, 0) }} ISK</td>
                            </tr>
                            <tr class="info">
                                <th>Tax Amount Owed:</th>
                                <td class="text-right">
                                    <strong style="font-size: 18px;" class="text-danger">
                                        {{ number_format($tax->tax_amount, 0) }} ISK
                                    </strong>
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    @if($tax->status === 'pending')
                                        <span class="label label-warning">Pending Payment</span>
                                    @elseif($tax->status === 'paid')
                                        <span class="label label-success">Paid</span>
                                    @else
                                        <span class="label label-default">{{ ucfirst($tax->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($miningActivity->isNotEmpty())
<div class="row">
    <div class="col-md-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Mining Activity Breakdown</h3>
                <span class="label label-info pull-right">{{ $miningActivity->count() }} sessions</span>
            </div>
            <div class="box-body">
                <table class="table table-hover table-condensed">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>System</th>
                            <th>Character</th>
                            <th>Ore Type</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Estimated Value</th>
                            <th class="text-right">Tax Rate</th>
                            <th class="text-right">Tax Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($miningActivity as $activity)
                        @php
                            $taxRate = $activity->tax_rate ?? $tax->applicable_tax_rate;
                            $taxAmount = ($activity->estimated_value * $taxRate) / 100;
                            
                            // Get system name from mapDenormalize
                            $systemName = 'Unknown';
                            if ($activity->solar_system_id) {
                                $system = DB::table('mapDenormalize')
                                    ->where('itemID', $activity->solar_system_id)
                                    ->where('groupID', 5)
                                    ->first();
                                $systemName = $system->itemName ?? $activity->solar_system_id;
                            }
                        @endphp
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($activity->mining_date)->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($activity->solar_system_id)
                                    <span class="text-muted">{{ $systemName }}</span>
                                @else
                                    <span class="text-danger">Unknown</span>
                                @endif
                            </td>
                            <td>
                                {!! img('characters', 'portrait', $activity->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $activity->character->name ?? 'Unknown (' . $activity->character_id . ')' }}
                            </td>
                            <td>
                                {!! img('types', 'icon', $activity->type_id, 32, ['class' => 'eve-icon small-icon']) !!}
                                {{ \Rejected\SeatAllianceTax\Services\OreNameTranslationService::translate($activity->type_name) }}
                                @if($activity->ore_category)
                                    <span class="label label-default">{{ $activity->ore_category }}</span>
                                @endif
                            </td>
                            <td class="text-right">{{ number_format($activity->quantity) }}</td>
                            <td class="text-right">{{ number_format($activity->estimated_value, 0) }} ISK</td>
                            <td class="text-right">{{ number_format($taxRate, 2) }}%</td>
                            <td class="text-right">{{ number_format($taxAmount, 0) }} ISK</td>
                        </tr>
                        @endforeach
                        <tr class="info">
                            <td colspan="5" class="text-right"><strong>Total:</strong></td>
                            <td class="text-right">
                                <strong>{{ number_format($miningActivity->sum('estimated_value'), 0) }} ISK</strong>
                            </td>
                            <td class="text-right">
                                <strong>Avg: {{ number_format($tax->applicable_tax_rate, 2) }}%</strong>
                            </td>
                            <td class="text-right">
                                <strong class="text-danger">{{ number_format($tax->tax_amount, 0) }} ISK</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

