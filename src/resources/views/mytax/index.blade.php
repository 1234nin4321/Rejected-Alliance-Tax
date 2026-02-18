@extends('web::layouts.grids.12')

@section('title', 'My Mining Taxes')
@section('page_header', 'My Mining Taxes')

@section('full')

<div class="row">
    <!-- Tax Summary -->
    <div class="col-md-3">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-exclamation-circle"></i> Taxes Pending</h3>
            </div>
            <div class="box-body">
                <h3 class="text-danger">
                    <strong>{{ number_format($totalPending, 0) }} ISK</strong>
                </h3>
                @if($pendingTaxes->isNotEmpty())
                    <p class="text-muted">
                        {{ $pendingTaxes->count() }} tax period(s) unpaid
                    </p>
                @else
                    <p class="text-success">
                        <i class="fa fa-check"></i> All caught up!
                    </p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="box" style="border-top: 3px solid #f39c12;">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-calculator"></i> Estimated Tax</h3>
            </div>
            <div class="box-body">
                @if($taxEstimate['has_estimate'])
                    <h3 style="color: #f39c12;">
                        <strong>~{{ number_format($taxEstimate['net_estimated_tax'], 0) }} ISK</strong>
                    </h3>
                    <p class="text-muted">
                        <small>{{ $taxEstimate['period_label'] }} (not yet invoiced)</small>
                    </p>
                @else
                    <h3 class="text-muted">
                        <strong>0 ISK</strong>
                    </h3>
                    <p class="text-muted">
                        @if(($taxEstimate['reason'] ?? '') === 'already_invoiced')
                            <small>Current period already invoiced</small>
                        @else
                            <small>No mining activity this period</small>
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-money"></i> Overpaid / Tax Credit</h3>
            </div>
            <div class="box-body">
                <h3 class="text-info">
                    <strong>{{ number_format($totalBalance, 0) }} ISK</strong>
                </h3>
                <p class="text-muted">
                    Available to deduct from future taxes
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-check"></i> Taxes Paid (3 Months)</h3>
            </div>
            <div class="box-body">
                <h3 class="text-success">
                    <strong>{{ number_format($totalPaid, 0) }} ISK</strong>
                </h3>
                <p class="text-muted">
                    {{ $paidTaxes->count() }} payment(s)
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="callout callout-warning" style="background-color: #2c3e50 !important; color: white !important; border-left: 5px solid #f39c12 !important;">
            <h4><i class="icon fa fa-info-circle"></i> How to Pay Your Taxes</h4>
            <p>
                To pay your outstanding taxes, please send the total ISK amount to the following corporation: 
                <strong>{{ $taxCorp->name ?? 'Configured Tax Corporation' }}</strong>
            </p>
            <ul>
                <li><strong>Automated Tracking:</strong> Payments are tracked automatically. Please send the ISK from the <strong>character</strong> that owes the tax.</li>
                <li><strong>Overpayments:</strong> Any amount sent above the total owed will be automatically saved as a <strong>Tax Credit</strong> for that character and applied to your next tax invoice.</li>
                <li><strong>Verification:</strong> The system logs every payment. Once processed, your status will update to <span class="label label-success">Paid</span>.</li>
            </ul>
        </div>
    </div>
</div>

<!-- Tax Estimate Breakdown -->
@if($taxEstimate['has_estimate'])
<div class="row">
    <div class="col-xs-12">
        <div class="box" style="border-top: 3px solid #f39c12;">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-calculator"></i>
                    Estimated Tax — {{ $taxEstimate['period_label'] }}
                </h3>
                <span class="label pull-right" style="background-color: #f39c12; font-size: 12px;">NOT YET INVOICED</span>
            </div>
            <div class="box-body">
                <div class="callout" style="background-color: #fcf8e3 !important; border-left: 5px solid #f39c12 !important; color: #8a6d3b !important;">
                    <i class="fa fa-info-circle"></i>
                    This is an <strong>estimate</strong> based on your mining activity so far this {{ $taxEstimate['period_type'] === 'monthly' ? 'month' : 'week' }}.
                    The final tax may differ once the period ends and the invoice is generated.
                </div>

                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Character</th>
                            <th class="text-right">Mining Sessions</th>
                            <th class="text-right">Mined Value</th>
                            <th class="text-right">Estimated Tax</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($taxEstimate['character_breakdown'] as $charEstimate)
                        <tr>
                            <td>
                                {!! img('characters', 'portrait', $charEstimate['character_id'], 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $charEstimate['character_name'] }}
                            </td>
                            <td class="text-right">{{ number_format($charEstimate['sessions']) }}</td>
                            <td class="text-right">{{ number_format($charEstimate['mined_value'], 0) }} ISK</td>
                            <td class="text-right" style="color: #f39c12;">
                                <strong>~{{ number_format($charEstimate['estimated_tax'], 0) }} ISK</strong>
                            </td>
                        </tr>
                        @endforeach

                        <tr style="border-top: 2px solid #f39c12;">
                            <td colspan="2" class="text-right"><strong>Totals:</strong></td>
                            <td class="text-right">
                                <strong>{{ number_format($taxEstimate['total_mined_value'], 0) }} ISK</strong>
                            </td>
                            <td class="text-right" style="color: #f39c12;">
                                <strong>~{{ number_format($taxEstimate['total_estimated_tax'], 0) }} ISK</strong>
                            </td>
                        </tr>

                        @if($taxEstimate['credit_applicable'] > 0)
                        <tr>
                            <td colspan="3" class="text-right text-info"><strong>Tax Credit to Apply:</strong></td>
                            <td class="text-right text-info">
                                <strong>-{{ number_format($taxEstimate['credit_applicable'], 0) }} ISK</strong>
                            </td>
                        </tr>
                        @endif

                        <tr class="info">
                            <td colspan="3" class="text-right"><strong>Estimated Net Tax Owed:</strong></td>
                            <td class="text-right">
                                <strong style="font-size: 16px; color: #f39c12;">
                                    ~{{ number_format($taxEstimate['net_estimated_tax'], 0) }} ISK
                                </strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Pending Taxes by Character -->
@if($pendingTaxes->isNotEmpty())
<div class="row">
    <div class="col-xs-12">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Outstanding Taxes</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Character</th>
                            <th>Tax Period</th>
                            <th class="text-right">Tax Gross</th>
                            <th class="text-right">Credit Applied</th>
                            <th class="text-right">Net Owed</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingTaxes as $tax)
                        <tr>
                            <td>
                                {!! img('characters', 'portrait', $tax->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                <a href="{{ route('alliancetax.mytax.character', $tax->character_id) }}">
                                    {{ optional($tax->character)->name ?? 'Unknown' }}
                                </a>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($tax->tax_period)->format('Y-m-d') }}</td>
                            <td class="text-right text-muted">{{ number_format($tax->tax_amount_gross ?? $tax->tax_amount, 0) }} ISK</td>
                            <td class="text-right text-info">{{ number_format($tax->credit_applied ?? 0, 0) }} ISK</td>
                            <td class="text-right">
                                <strong class="text-danger">{{ number_format($tax->tax_amount, 0) }} ISK</strong>
                            </td>
                            <td>
                                <span class="label label-warning">Pending</span>
                            </td>
                            <td>
                                <a href="{{ route('alliancetax.mytax.details', $tax->id) }}" class="btn btn-xs btn-info">
                                    <i class="fa fa-list"></i> View Breakdown
                                </a>
                            </td>
                        </tr>
                        @endforeach
                        <tr class="info">
                            <td colspan="4" class="text-right"><strong>TOTAL OUTSTANDING:</strong></td>
                            <td class="text-right">
                                <strong class="text-danger" style="font-size: 16px;">
                                    {{ number_format($totalPending, 0) }} ISK
                                </strong>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Mining Summary by Character -->
@if($miningSummary->isNotEmpty())
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Mining Summary (Last 3 Months)</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>Character</th>
                            <th class="text-right">Mining Sessions</th>
                            <th class="text-right">Total Quantity</th>
                            <th class="text-right">Estimated Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($miningSummary as $summary)
                        <tr>
                            <td>
                                {!! img('characters', 'portrait', $summary->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $summary->character->name ?? 'Character ' . $summary->character_id }}
                            </td>
                            <td class="text-right">{{ number_format($summary->mining_sessions) }}</td>
                            <td class="text-right">{{ number_format($summary->total_quantity) }}</td>
                            <td class="text-right">{{ number_format($summary->total_value, 0) }} ISK</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Recent Mining Activity -->
@if($recentActivity->isNotEmpty())
<div class="row">
    <div class="col-xs-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Recent Mining Activity (Last 30 Days)</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Character</th>
                            <th>Ore Type</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentActivity->take(20) as $activity)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($activity->mining_date)->format('Y-m-d H:i') }}</td>
                            <td>
                                {!! img('characters', 'portrait', $activity->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $activity->character->name ?? 'Character ' . $activity->character_id }}
                            </td>
                            <td>
                                {!! img('types', 'icon', $activity->type_id, 32, ['class' => 'eve-icon small-icon']) !!}
                                {{ \Rejected\SeatAllianceTax\Services\OreNameTranslationService::translate($activity->type_name) }}
                            </td>
                            <td class="text-right">{{ number_format($activity->quantity) }}</td>
                            <td class="text-right">{{ number_format($activity->estimated_value, 0) }} ISK</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                
                @if($recentActivity->count() > 20)
                <p class="text-center text-muted">
                    Showing 20 of {{ $recentActivity->count() }} recent records
                </p>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

@if($pendingTaxes->isEmpty() && $paidTaxes->isEmpty() && $recentActivity->isEmpty() && !$taxEstimate['has_estimate'])
<div class="row">
    <div class="col-xs-12">
        <div class="box box-default">
            <div class="box-body">
                <div class="callout callout-info">
                    <h4><i class="icon fa fa-info-circle"></i> No Tax Information</h4>
                    <p>You don't have any tax records yet. This could mean:</p>
                    <ul>
                        <li>You haven't mined recently</li>
                        <li>Taxes haven't been calculated yet</li>
                        <li>Your characters aren't in the alliance</li>
                    </ul>
                    <p>
                        <strong>Contact your alliance administrator if you think this is incorrect.</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

