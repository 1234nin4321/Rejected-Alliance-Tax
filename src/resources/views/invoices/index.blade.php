@extends('web::layouts.grids.12')

@section('title', 'Tax Invoices')
@section('page_header', 'Tax Invoice Management')

@section('full')

<!-- Invoice Statistics -->
<div class="row">
    <div class="col-md-4">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>{{ number_format($stats['total_sent'] / 1000000, 0) }}M</h3>
                <p>Outstanding Invoices</p>
            </div>
            <div class="icon"><i class="fa fa-file-text"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="small-box bg-red">
            <div class="inner">
                <h3>{{ number_format($stats['total_overdue'] / 1000000, 0) }}M</h3>
                <p>Overdue</p>
            </div>
            <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="small-box bg-green">
            <div class="inner">
                <h3>{{ number_format($stats['total_paid'] / 1000000, 0) }}M</h3>
                <p>Paid</p>
            </div>
            <div class="icon"><i class="fa fa-check"></i></div>
        </div>
    </div>
</div>

<!-- Flash Messages -->
@if(session('success'))
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-check-circle"></i> {!! session('success') !!}
        </div>
    </div>
</div>
@endif
@if(session('error'))
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-exclamation-circle"></i> {!! session('error') !!}
        </div>
    </div>
</div>
@endif
@if(session('info'))
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-info-circle"></i> {!! session('info') !!}
        </div>
    </div>
</div>
@endif
@if(session('warning'))
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-warning alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fa fa-warning"></i> {!! session('warning') !!}
        </div>
    </div>
</div>
@endif

<!-- Generate Invoices -->
<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Generate New Invoices</h3>
                <div class="box-tools">
                    <form method="POST" action="{{ route('alliancetax.invoices.reconcile') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-info" onclick="this.disabled=true; this.innerHTML='<i class=\'fa fa-spinner fa-spin\'></i> Reconciling...'; this.form.submit();">
                            <i class="fa fa-refresh"></i> Reconcile Payments
                        </button>
                    </form>
                </div>
            </div>
            <div class="box-body">
                <form method="POST" action="{{ route('alliancetax.invoices.generate') }}" class="form-inline">
                    @csrf
                    <div class="form-group">
                        <label>Period Start:</label>
                        <input type="date" name="period_start" class="form-control" required value="{{ now()->startOfWeek()->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label>Period End:</label>
                        <input type="date" name="period_end" class="form-control" required value="{{ now()->endOfWeek()->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label>Due in (days):</label>
                        <input type="number" name="due_days" class="form-control" required value="7" min="1" max="30">
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-plus"></i> Generate Invoices
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tax Lifecycle Management -->
<div class="row">
    <div class="col-md-12">
        <div class="nav-tabs-custom" style="border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            
            <!-- COLORED ACTION BUTTONS -->
            <ul class="nav nav-tabs no-border bg-gray-light" style="padding: 15px 15px 0 15px;">
                <li class="active">
                    <a href="#tab_1" data-toggle="tab" class="btn btn-default" style="margin-right: 10px; border: 1px solid #ccc; padding: 10px 25px; font-weight: bold;">
                        <span class="label label-default" style="margin-right: 8px; border: 1px solid #999;">{{ $stats['pending_calc_count'] }}</span> 
                        Pending Calculations
                    </a>
                </li>
                <li>
                    <a href="#tab_2" data-toggle="tab" class="btn btn-warning" style="margin-right: 10px; border: 1px solid #e08e0b; padding: 10px 25px; color: white; font-weight: bold;">
                        <span class="label label-danger" style="margin-right: 8px;">{{ $stats['pending_invoice_count'] }}</span> 
                        Active Invoices
                    </a>
                </li>
                <li>
                    <a href="#tab_3" data-toggle="tab" class="btn btn-success" style="border: 1px solid #008d4c; padding: 10px 25px; color: white; font-weight: bold;">
                        <span class="label label-primary" style="margin-right: 8px; background-color: #333 !important;">{{ $stats['paid_invoice_count'] }}</span> 
                        Paid History
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                
                <!-- STEP 1: PENDING RECORDS -->
                <div class="tab-pane active" id="tab_1">
                    <div class="box-header">
                        <h3 class="box-title text-orange">Outstanding Tax Records</h3>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="padding-left: 20px;">Character</th>
                                    <th>Corporation</th>
                                    <th>Date Period</th>
                                    <th class="text-right">Mined Value</th>
                                    <th class="text-right">Tax Owed</th>
                                    <th class="text-right" style="padding-right: 20px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pendingCalculations as $calc)
                                <tr>
                                    <td style="padding-left: 20px;">
                                        {!! img('characters', 'portrait', $calc->character_id, 32, ['class' => 'img-circle shadow-sm']) !!}
                                        <span class="margin-l-5">{{ $calc->character->name ?? 'Unknown' }}</span>
                                    </td>
                                    <td>{{ $calc->corporation->name ?? 'Unknown' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($calc->tax_period)->format('Y-m-d') }}</td>
                                    <td class="text-right text-muted">{{ number_format($calc->total_mined_value, 0) }} ISK</td>
                                    <td class="text-right"><strong>{{ number_format($calc->tax_amount, 0) }} ISK</strong></td>
                                    <td class="text-right" style="padding-right: 20px;">
                                        <form method="POST" action="{{ route('alliancetax.admin.calculations.destroy', $calc->id) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Delete record?')"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted" style="padding: 50px;">No pending records. Everything is invoiced!</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- STEP 2: ACTIVE INVOICES -->
                <div class="tab-pane" id="tab_2">
                    <form method="POST" id="bulk-form">
                        @csrf
                        <div class="box-header">
                            <h3 class="box-title text-red">Unpaid Invoices</h3>
                            <div class="box-tools">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-success" onclick="submitBulkAction('{{ route('alliancetax.invoices.bulk-paid') }}')"><i class="fa fa-check"></i> Mark Paid</button>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="submitBulkAction('{{ route('alliancetax.invoices.send-notifications') }}')"><i class="fa fa-bell"></i> Send Alerts</button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="if(confirm('Delete selected?')) submitBulkAction('{{ route('alliancetax.invoices.bulk-delete') }}')"><i class="fa fa-trash"></i> Delete</button>
                                </div>
                            </div>
                        </div>
                        <div class="box-body no-padding">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; padding-left: 20px;"><input type="checkbox" id="select-all"></th>
                                        <th>Pilot</th>
                                        <th class="text-right">Amount</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th class="text-right" style="padding-right: 20px;">Quick Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($pendingInvoices as $invoice)
                                    <tr>
                                        <td style="padding-left: 20px;"><input type="checkbox" name="invoice_ids[]" value="{{ $invoice->id }}" class="invoice-checkbox"></td>
                                        <td><strong>{{ $invoice->character->name ?? 'Unknown' }}</strong></td>
                                        <td class="text-right">
                                            <strong>{{ number_format($invoice->amount, 0) }} ISK</strong>
                                            @php
                                                $meta = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
                                                $appliedPayments = $meta['applied_payments'] ?? [];
                                                $totalPaid = 0;
                                                foreach ($appliedPayments as $p) { $totalPaid += ($p['amount'] ?? 0); }
                                            @endphp
                                            @if($totalPaid > 0)
                                                <br><small class="text-green">{{ number_format($totalPaid, 0) }} ISK paid</small>
                                            @endif
                                        </td>
                                        <td>{{ $invoice->due_date->format('Y-m-d') }}</td>
                                        <td>
                                            @if($invoice->status === 'partial')
                                                <span class="label label-info">PARTIAL</span>
                                            @elseif($invoice->due_date < now())
                                                <span class="label label-danger">OVERDUE</span>
                                            @else
                                                <span class="label label-warning">SENT</span>
                                            @endif
                                        </td>
                                        <td class="text-right" style="padding-right: 20px;">
                                            <form method="POST" action="{{ route('alliancetax.invoices.mark-paid', $invoice->id) }}" style="display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-xs btn-success"><i class="fa fa-check"></i> Paid</button>
                                            </form>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr><td colspan="6" class="text-center text-muted" style="padding: 50px;">Queue is clear. No active unpaid invoices!</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>

                <!-- STEP 3: PAID HISTORY -->
                <div class="tab-pane" id="tab_3">
                    <div class="box-header">
                        <h3 class="box-title text-green">History Archive</h3>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="padding-left: 20px;">Pilot</th>
                                    <th class="text-right">Amount Settled</th>
                                    <th>Paid At</th>
                                    <th>Status</th>
                                    <th class="text-right" style="padding-right: 20px;">Admin</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($paidInvoices as $invoice)
                                <tr>
                                    <td style="padding-left: 20px;">{{ $invoice->character->name ?? 'Unknown' }}</td>
                                    <td class="text-right text-green"><strong>{{ number_format($invoice->amount, 0) }} ISK</strong></td>
                                    <td>{{ $invoice->paid_at ? $invoice->paid_at->format('Y-m-d') : 'N/A' }}</td>
                                    <td><span class="label label-success">COMPLETED</span></td>
                                    <td class="text-right" style="padding-right: 20px;">
                                        <form method="POST" action="{{ route('alliancetax.invoices.destroy', $invoice->id) }}" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-xs btn-default text-red" onclick="return confirm('Prune record?')"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted" style="padding: 50px;">History is currently empty.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

@endsection

@push('javascript')
<script>
// Select all checkboxes
$('#select-all').click(function() {
    $('.invoice-checkbox').prop('checked', this.checked);
});

function submitBulkAction(url) {
    const form = $('#bulk-form');
    const checked = $('.invoice-checkbox:checked').length;
    
    if (checked === 0) {
        alert('Please select at least one invoice');
        return;
    }
    
    form.attr('action', url);
    form.submit();
}
</script>
@endpush
