@extends('web::layouts.grids.12')

@section('title', 'Invoice #' . $invoice->id)
@section('page_header', 'Invoice Details')

@section('full')

<div class="row">
    <div class="col-md-12">
        <a href="{{ route('alliancetax.mytax.index') }}" class="btn btn-default btn-sm" style="margin-bottom: 15px;">
            <i class="fa fa-arrow-left"></i> Back to My Taxes
        </a>
    </div>
</div>

<div class="row">
    <!-- Invoice Summary -->
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-file-text-o"></i> Invoice #{{ $invoice->id }}</h3>
                <div class="box-tools pull-right">
                    @if($invoice->status === 'paid')
                        <span class="label label-success" style="font-size: 14px;"><i class="fa fa-check"></i> Paid</span>
                    @elseif($invoice->status === 'partial')
                        <span class="label" style="background-color: #f39c12; font-size: 14px;"><i class="fa fa-adjust"></i> Partial</span>
                    @elseif($invoice->status === 'overdue' || ($invoice->due_date && \Carbon\Carbon::parse($invoice->due_date)->isPast()))
                        <span class="label label-danger" style="font-size: 14px;"><i class="fa fa-exclamation-triangle"></i> Overdue</span>
                    @else
                        <span class="label label-warning" style="font-size: 14px;"><i class="fa fa-clock-o"></i> Sent</span>
                    @endif
                </div>
            </div>
            <div class="box-body">
                <table class="table table-condensed">
                    <tr>
                        <td style="width: 40%; font-weight: bold;">Character</td>
                        <td>
                            {!! img('characters', 'portrait', $invoice->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                            {{ optional($invoice->character)->name ?? 'Unknown' }}
                        </td>
                    </tr>
                    @if($invoice->corporation)
                    <tr>
                        <td style="font-weight: bold;">Corporation</td>
                        <td>{{ $invoice->corporation->name }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="font-weight: bold;">Tax Period</td>
                        <td>
                            @if(isset($metadata['period_start']))
                                {{ \Carbon\Carbon::parse($metadata['period_start'])->format('M d, Y') }}
                                — {{ \Carbon\Carbon::parse($metadata['period_end'])->format('M d, Y') }}
                            @else
                                {{ \Carbon\Carbon::parse($invoice->created_at)->format('M d, Y') }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Invoice Date</td>
                        <td>{{ \Carbon\Carbon::parse($invoice->created_at)->format('M d, Y H:i') }}</td>
                    </tr>
                    @if($invoice->due_date)
                    <tr>
                        <td style="font-weight: bold;">Due Date</td>
                        <td>
                            {{ \Carbon\Carbon::parse($invoice->due_date)->format('M d, Y') }}
                            @if(!in_array($invoice->status, ['paid']) && \Carbon\Carbon::parse($invoice->due_date)->isPast())
                                <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Overdue</span>
                            @endif
                        </td>
                    </tr>
                    @endif
                    @if($invoice->paid_at)
                    <tr>
                        <td style="font-weight: bold;">Paid Date</td>
                        <td class="text-success">
                            <i class="fa fa-check"></i>
                            {{ \Carbon\Carbon::parse($invoice->paid_at)->format('M d, Y H:i') }}
                        </td>
                    </tr>
                    @endif
                    @if($taxCorp)
                    <tr>
                        <td style="font-weight: bold;">Pay To</td>
                        <td>{{ $taxCorp->name }}</td>
                    </tr>
                    @endif
                    @if($invoice->invoice_note)
                    <tr>
                        <td style="font-weight: bold;">Note</td>
                        <td>{{ $invoice->invoice_note }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <!-- Payment Summary -->
    <div class="col-md-6">
        <div class="box box-{{ $invoice->status === 'paid' ? 'success' : ($invoice->status === 'partial' ? 'warning' : 'danger') }}">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-money"></i> Payment Summary</h3>
            </div>
            <div class="box-body">
                <table class="table table-condensed">
                    <tr>
                        <td style="width: 50%; font-weight: bold;">Original Amount</td>
                        <td class="text-right" style="font-size: 18px;">
                            <strong>{{ number_format($originalAmount, 0) }} ISK</strong>
                        </td>
                    </tr>
                    @if($totalPaidOnInvoice > 0 || $invoice->status === 'paid')
                    <tr>
                        <td style="font-weight: bold;">Total Paid</td>
                        <td class="text-right text-success" style="font-size: 18px;">
                            @if($invoice->status === 'paid')
                                <strong>{{ number_format($originalAmount, 0) }} ISK</strong>
                            @else
                                <strong>{{ number_format($totalPaidOnInvoice, 0) }} ISK</strong>
                            @endif
                        </td>
                    </tr>
                    @endif
                    @if($invoice->status !== 'paid')
                    <tr style="border-top: 2px solid #dd4b39;">
                        <td style="font-weight: bold;">Remaining Balance</td>
                        <td class="text-right text-danger" style="font-size: 20px;">
                            <strong>{{ number_format($invoice->amount, 0) }} ISK</strong>
                        </td>
                    </tr>
                    @endif
                </table>

                @if($invoice->status === 'partial' || ($totalPaidOnInvoice > 0 && $invoice->status !== 'paid'))
                    @php
                        $pctPaid = $originalAmount > 0 ? ($totalPaidOnInvoice / $originalAmount) * 100 : 0;
                    @endphp
                    <div class="progress" style="margin-top: 10px; height: 20px;">
                        <div class="progress-bar progress-bar-success progress-bar-striped"
                             style="width: {{ $pctPaid }}%; line-height: 20px;">
                            {{ number_format($pctPaid, 0) }}% Paid
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Payment History -->
        @if(count($appliedPayments) > 0)
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-history"></i> Payment History</h3>
            </div>
            <div class="box-body no-padding">
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($appliedPayments as $idx => $payment)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>
                                @if(isset($payment['date']))
                                    {{ \Carbon\Carbon::parse($payment['date'])->format('M d, Y H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-right text-success">
                                <strong>{{ number_format($payment['amount'] ?? 0, 0) }} ISK</strong>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>

<!-- Mining Breakdown for this Invoice Period -->
@if($miningActivity->isNotEmpty())
<div class="row">
    <div class="col-xs-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-industry"></i> Mining Activity for this Period</h3>
                <span class="badge pull-right">{{ $miningActivity->count() }} entries</span>
            </div>
            <div class="box-body">
                <table class="table table-condensed table-hover table-striped">
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
                        @foreach($miningActivity as $activity)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($activity->mining_date)->format('Y-m-d') }}</td>
                            <td>
                                {!! img('characters', 'portrait', $activity->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ optional($activity->character)->name ?? 'Character ' . $activity->character_id }}
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
                    <tfoot>
                        <tr class="info">
                            <td colspan="3" class="text-right"><strong>Total:</strong></td>
                            <td class="text-right"><strong>{{ number_format($miningActivity->sum('quantity')) }}</strong></td>
                            <td class="text-right"><strong>{{ number_format($miningActivity->sum('estimated_value'), 0) }} ISK</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@endsection
