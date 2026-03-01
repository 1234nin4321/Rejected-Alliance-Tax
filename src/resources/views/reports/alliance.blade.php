@extends('web::layouts.grids.12')

@section('title', 'Alliance Tax Reports')
@section('page_header', 'Alliance Tax Reports')

@section('full')

<!-- Period Selection -->
<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Select Period</h3>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ route('alliancetax.reports.alliance') }}" class="form-inline">
                    <div class="form-group">
                        <label>Period Start:</label>
                        <input type="date" name="period_start" class="form-control" value="{{ $periodStart->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label>Period End:</label>
                        <input type="date" name="period_end" class="form-control" value="{{ $periodEnd->format('Y-m-d') }}">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-search"></i> Generate Report
                    </button>
                    <a href="{{ route('alliancetax.reports.export', ['period_start' => $periodStart->format('Y-m-d'), 'period_end' => $periodEnd->format('Y-m-d')]) }}" class="btn btn-success">
                        <i class="fa fa-download"></i> Export CSV
                    </a>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Summary Statistics -->
<div class="row">
    <div class="col-md-3">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3>{{ number_format($totalMined / 1000000000, 2) }}B</h3>
                <p>Total Mined Value</p>
            </div>
            <div class="icon">
                <i class="fa fa-cube"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-purple">
            <div class="inner">
                <h3>{{ number_format($totalTaxGross / 1000000, 0) }}M</h3>
                <p>Gross Tax Owed</p>
            </div>
            <div class="icon">
                <i class="fa fa-money"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>{{ number_format($totalTaxPending / 1000000, 0) }}M</h3>
                <p>Net Tax Pending</p>
            </div>
            <div class="icon">
                <i class="fa fa-clock-o"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-green">
            <div class="inner">
                <h3>{{ number_format($totalTaxPaid / 1000000, 0) }}M</h3>
                <p>Tax Collected</p>
            </div>
            <div class="icon">
                <i class="fa fa-check"></i>
            </div>
        </div>
    </div>
</div>

<!-- Mining Breakdown by Type -->
<div class="row">
    <div class="col-md-6">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Mining Breakdown by Type</h3>
            </div>
            <div class="box-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th class="text-right">Value (ISK)</th>
                            <th class="text-right">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $total = array_sum($miningByCategory); @endphp
                        @foreach($miningByCategory as $category => $value)
                            @if($value > 0)
                            <tr>
                                <td>
                                    @if($category === 'moon_r64')
                                        <span class="label label-danger">Moon Ore (R64)</span>
                                    @elseif($category === 'moon_r32')
                                        <span class="label label-warning" style="background-color: #d35400;">Moon Ore (R32)</span>
                                    @elseif($category === 'moon_r16')
                                        <span class="label label-warning">Moon Ore (R16)</span>
                                    @elseif($category === 'moon_r8')
                                        <span class="label label-success">Moon Ore (R8)</span>
                                    @elseif($category === 'moon_r4')
                                        <span class="label label-info">Moon Ore (R4)</span>
                                    @elseif($category === 'ice')
                                        <span class="label label-primary">Ice</span>
                                    @elseif($category === 'gas')
                                        <span class="label label-warning" style="background-color: #6347ff;">Gas</span>
                                    @else
                                        <span class="label label-default">Standard Ore</span>
                                    @endif


                                </td>
                                <td class="text-right">{{ number_format($value, 0) }}</td>
                                <td class="text-right">{{ $total > 0 ? number_format(($value / $total) * 100, 1) : 0 }}%</td>
                            </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="text-right">{{ number_format($total, 0) }}</th>
                            <th class="text-right">100%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Top Miners -->
    <div class="col-md-6">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Top 10 Miners</h3>
            </div>
            <div class="box-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Character</th>
                            <th class="text-right">Total Mined</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topMiners as $index => $miner)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                <a href="{{ route('alliancetax.character.show', $miner->character_id) }}">
                                    {!! img('characters', 'portrait', $miner->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                    {{ $miner->character->name ?? 'Unknown Character' }}
                                </a>
                            </td>
                            <td class="text-right">{{ number_format($miner->total_value, 0) }} ISK</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No mining activity in this period</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Corporation Breakdown -->
<div class="row">
    <div class="col-md-12">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Corporation Breakdown</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover" id="corp-table">
                    <thead>
                        <tr>
                            <th>Corporation</th>
                            <th class="text-right">Total Mined</th>
                            <th class="text-right">Gross Tax</th>
                            <th class="text-right">Net Owed</th>
                            <th class="text-right">Effective Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($corpBreakdown as $corp)
                        <tr>
                            <td>
                                {!! img('corporations', 'logo', $corp->corporation_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $corp->corporation->name ?? 'Unknown Corporation' }}
                            </td>
                            <td class="text-right">{{ number_format($corp->total_mined, 0) }} ISK</td>
                            <td class="text-right">{{ number_format($corp->total_tax_gross, 0) }} ISK</td>
                            <td class="text-right">{{ number_format($corp->net_tax_owed, 0) }} ISK</td>
                            <td class="text-right">
                                {{ $corp->total_mined > 0 ? number_format(($corp->total_tax_gross / $corp->total_mined) * 100, 2) : 0 }}%
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">No tax data for this period</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection

