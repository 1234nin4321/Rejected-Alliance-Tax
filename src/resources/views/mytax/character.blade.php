@extends('web::layouts.grids.12')

@section('title', 'Character Tax Details')
@section('page_header', 'Character Taxes: ' . $characterId)

@section('full')
<div class="row">
    <div class="col-md-12">
        <a href="{{ route('alliancetax.mytax.index') }}" class="btn btn-default margin-bottom">
            <i class="fa fa-arrow-left"></i> Back to My Taxes
        </a>
    </div>
</div>

<div class="row">
    <!-- Tax History -->
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Tax Payment History</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="text-right">Mined Value</th>
                            <th class="text-right">Tax Rate</th>
                            <th class="text-right">Tax Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($taxes as $tax)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($tax->tax_period)->format('Y-m-d') }}</td>
                            <td class="text-right">{{ number_format($tax->total_mined_value, 0) }} ISK</td>
                            <td class="text-right">{{ number_format($tax->applicable_tax_rate, 2) }}%</td>
                            <td class="text-right">{{ number_format($tax->tax_amount, 2) }} ISK</td>
                            <td>
                                @if($tax->status === 'paid')
                                    <span class="label label-success">Paid</span>
                                @elseif($tax->status === 'pending')
                                    <span class="label label-warning">Pending</span>
                                @else
                                    <span class="label label-danger">{{ ucfirst($tax->status) }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No tax records found for this character.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                {{ $taxes->links() }}
            </div>
        </div>
    </div>

    <!-- Recent Mining -->
    <div class="col-md-6">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Recent Mining Ledger</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($miningActivity as $activity)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($activity->mining_date)->format('Y-m-d H:i') }}</td>
                            <td>
                                {!! img('types', 'icon', $activity->type_id, 32, ['class' => 'eve-icon small-icon']) !!}
                                {{ \Rejected\SeatAllianceTax\Services\OreNameTranslationService::translate($activity->type_name) }}
                            </td>
                            <td class="text-right">{{ number_format($activity->quantity) }}</td>
                            <td class="text-right">{{ number_format($activity->estimated_value, 2) }} ISK</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">No mining activity recorded.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                {{ $miningActivity->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
