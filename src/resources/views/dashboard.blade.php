@extends('web::layouts.grids.12')

@section('title', trans('alliancetax::dashboard.title'))
@section('page_header', trans('alliancetax::dashboard.title'))

@section('full')
<div class="row">
    <!-- Current Period Stats -->
    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box">
            <span class="info-box-icon bg-aqua"><i class="fa fa-money"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Mined Value</span>
                <span class="info-box-number">{{ number_format($currentPeriodStats['total_mined_value'], 2) }} ISK</span>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box">
            <span class="info-box-icon bg-red"><i class="fa fa-exclamation-triangle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tax Owed</span>
                <span class="info-box-number">{{ number_format($currentPeriodStats['total_tax_owed'], 2) }} ISK</span>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box">
            <span class="info-box-icon bg-green"><i class="fa fa-check"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tax Collected</span>
                <span class="info-box-number">{{ number_format($currentPeriodStats['total_tax_paid'], 2) }} ISK</span>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box">
            <span class="info-box-icon bg-yellow"><i class="fa fa-users"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Active Miners</span>
                <span class="info-box-number">{{ $currentPeriodStats['active_miners'] }}</span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Top Miners -->
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Top Miners This Period</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Character</th>
                            <th class="text-right">Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topMiners as $index => $miner)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                {!! img('characters', 'portrait', $miner->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $miner->character->name ?? 'Unknown' }}
                            </td>
                            <td class="text-right">{{ number_format($miner->total_value, 2) }} ISK</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No mining activity this period</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tax Summary -->
    <div class="col-md-6">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Tax Collection Summary</h3>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>Total Outstanding:</dt>
                    <dd class="text-danger">{{ number_format($taxSummary['total_outstanding'], 2) }} ISK</dd>
                    
                    <dt>Total Collected:</dt>
                    <dd class="text-success">{{ number_format($taxSummary['total_collected'], 2) }} ISK</dd>
                    
                    <dt>Pending Payments:</dt>
                    <dd>{{ $taxSummary['pending_count'] }} character(s)</dd>
                </dl>

                @can('alliancetax.admin')
                <div class="margin-top">
                    <a href="{{ route('alliancetax.admin.rates.index') }}" class="btn btn-sm btn-primary">
                        <i class="fa fa-cog"></i> Manage Tax Rates
                    </a>
                    <a href="{{ route('alliancetax.reports.alliance') }}" class="btn btn-sm btn-info">
                        <i class="fa fa-file-text"></i> View Reports
                    </a>
                </div>
                @endcan
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row">
    <div class="col-xs-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Recent Mining Activity</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed table-hover" id="recent-activity-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Character</th>
                            <th>Corporation</th>
                            <th>Ore Type</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Estimated Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentActivity as $activity)
                        <tr>
                            <td data-order="{{ $activity->mining_date->timestamp }}">
                                {{ $activity->mining_date->format('Y-m-d H:i') }}
                            </td>
                            <td>
                                {!! img('characters', 'portrait', $activity->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                <a href="{{ route('alliancetax.character.show', $activity->character_id) }}">
                                    {{ $activity->character->name ?? 'Unknown' }}
                                </a>
                            </td>
                            <td>
                                {!! img('corporations', 'logo', $activity->corporation_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $activity->corporation->name ?? 'Unknown' }}
                            </td>
                            <td>
                                {!! img('types', 'icon', $activity->type_id, 32, ['class' => 'eve-icon small-icon']) !!}
                                {{ \Rejected\SeatAllianceTax\Services\OreNameTranslationService::translate($activity->type_name) }}
                            </td>
                            <td class="text-right">{{ number_format($activity->quantity) }}</td>
                            <td class="text-right">{{ number_format($activity->estimated_value, 2) }} ISK</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">No recent mining activity</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
$(function() {
    // Only initialize DataTable if there's actual data
    var $table = $('#recent-activity-table');
    if ($table.find('tbody tr').not(':has(td[colspan])').length > 0) {
        $table.DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            columns: [
                null, // Date
                null, // Character
                null, // Corporation
                null, // Ore Type
                null, // Quantity
                null  // Estimated Value
            ]
        });
    }
});
</script>
@endpush
