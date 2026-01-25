@extends('web::layouts.grids.12')

@section('title', 'Corporate Ratting Tax')
@section('page_header', 'Corporate Tax Hub')

@push('css')
<style>
    :root {
        --corp-primary: #3c8dbc;
        --corp-success: #00a65a;
        --corp-warning: #f39c12;
        --corp-danger: #dd4b39;
        --corp-dark: #222d32;
        --glass-bg: rgba(255, 255, 255, 0.9);
        --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }

    .premium-header {
        background: linear-gradient(135deg, var(--corp-dark) 0%, #1a2226 100%);
        color: white;
        padding: 30px;
        border-radius: 12px;
        margin-bottom: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        position: relative;
        overflow: hidden;
    }

    .premium-header::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 400px;
        height: 400px;
        background: rgba(255, 255, 255, 0.03);
        border-radius: 50%;
    }

    .metric-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: 1px solid rgba(0,0,0,0.05);
        height: 100%;
        display: flex;
        align-items: center;
    }

    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    }

    .metric-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-right: 15px;
    }

    .metric-value {
        font-size: 22px;
        font-weight: 700;
        display: block;
        color: #333;
    }

    .metric-label {
        font-size: 13px;
        color: #777;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .modern-card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        border: none;
        margin-bottom: 25px;
    }

    .modern-card .box-header {
        padding: 20px;
        border-bottom: 1px solid #f4f4f4;
    }

    .modern-card .box-title {
        font-weight: 600;
        font-size: 18px;
    }

    .modern-card .box-body {
        padding: 20px;
    }

    .custom-tabs .nav-tabs {
        border-bottom: none;
        background: #f8f9fa;
        padding: 10px 10px 0;
        border-radius: 12px 12px 0 0;
    }

    .custom-tabs .nav-tabs > li > a {
        border: none;
        border-radius: 8px 8px 0 0;
        padding: 12px 25px;
        font-weight: 600;
        color: #666;
        transition: all 0.2s;
        margin-right: 5px;
    }

    .custom-tabs .nav-tabs > li.active > a {
        background: white;
        color: var(--corp-primary);
        box-shadow: 0 -4px 10px rgba(0,0,0,0.03);
    }

    .table-premium thead th {
        background: #f8f9fa;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 1px;
        color: #888;
        padding: 15px 10px;
        border-top: none;
    }

    .table-premium tbody td {
        padding: 15px 10px;
        vertical-align: middle;
        border-bottom: 1px solid #f4f4f4;
    }

    .btn-action {
        border-radius: 6px;
        padding: 6px 15px;
        font-weight: 600;
        font-size: 12px;
        transition: all 0.2s;
    }

    .btn-action:hover {
        transform: scale(1.05);
    }

    .corp-logo-circle {
        border: 2px solid #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .form-section-title {
        font-size: 14px;
        font-weight: 700;
        color: #444;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--corp-primary);
        display: inline-block;
    }
</style>
@endpush

@section('full')

@if(session('success'))
<div class="alert alert-success alert-dismissible" style="border-radius: 8px; border: none; box-shadow: 0 4px 10px rgba(0,166,90,0.2);">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="icon fa fa-check-circle"></i> {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible" style="border-radius: 8px; border: none; box-shadow: 0 4px 10px rgba(221,75,57,0.2);">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="icon fa fa-warning"></i> {{ session('error') }}
</div>
@endif

<!-- Premium Header & Generation -->
<div class="premium-header">
    <div class="row">
        <div class="col-md-6">
            <h2 style="margin-top: 0; font-weight: 700;">Corporate Ratting Tax</h2>
            <p style="opacity: 0.8; font-size: 16px;">Central command for managing alliance-wide corporate tax yields from bounty income.</p>
        </div>
        <div class="col-md-6 text-right">
            <div style="background: rgba(255,255,255,0.1); padding: 20px; border-radius: 12px; display: inline-block; backdrop-filter: blur(5px);">
                <form method="POST" action="{{ route('alliancetax.corptax.generate') }}" class="form-inline">
                    @csrf
                    <div class="form-group margin-r-10 text-left">
                        <label style="display: block; font-size: 11px; text-transform: uppercase;">Period Start</label>
                        <input type="date" name="period_start" class="form-control input-sm" style="background: rgba(255,255,255,0.9); border: none;" required value="{{ \Carbon\Carbon::now()->startOfMonth()->format('Y-m-d') }}">
                    </div>
                    <div class="form-group margin-r-10 text-left">
                        <label style="display: block; font-size: 11px; text-transform: uppercase;">Period End</label>
                        <input type="date" name="period_end" class="form-control input-sm" style="background: rgba(255,255,255,0.9); border: none;" required value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                    </div>
                    <button type="submit" class="btn btn-sm btn-success" style="margin-top: 20px; padding: 5px 20px; font-weight: 700;">
                        <i class="fa fa-magic"></i> Generate Invoices
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics Row -->
<div class="row" style="margin-bottom: 25px;">
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon" style="background: rgba(60, 141, 188, 0.1); color: var(--corp-primary);">
                <i class="fa fa-university"></i>
            </div>
            <div>
                <span class="metric-value">{{ $settings->count() }}</span>
                <span class="metric-label">Taxed Corporations</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon" style="background: rgba(243, 156, 18, 0.1); color: var(--corp-warning);">
                <i class="fa fa-hourglass-half"></i>
            </div>
            <div>
                <span class="metric-value">{{ $calculations->where('status', 'pending')->count() }}</span>
                <span class="metric-label">Pending Invoices</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon" style="background: rgba(0, 166, 90, 0.1); color: var(--corp-success);">
                <i class="fa fa-check-circle"></i>
            </div>
            <div>
                <span class="metric-value">{{ number_format($calculations->where('status', 'paid')->sum('tax_amount') / 1000000, 1) }}M</span>
                <span class="metric-label">Total ISK Collected</span>
            </div>
        </div>
    </div>
</div>

<!-- Configuration Section -->
<div class="modern-card box">
    <div class="box-header">
        <h3 class="box-title"><i class="fa fa-cogs text-primary"></i> Tax Structure Configuration</h3>
        <div class="box-tools">
            <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
        </div>
    </div>
    <div class="box-body">
        <div class="row">
            <div class="col-md-5" style="border-right: 1px solid #f4f4f4;">
                <div class="form-section-title">Setup New Corporation</div>
                <form method="POST" action="{{ route('alliancetax.corptax.settings.update') }}" class="well" style="background: #fafafa; border: 1px dashed #ddd;">
                    @csrf
                    <div class="form-group">
                        <label>Select Corporation</label>
                        <select name="corporation_id" id="corp_search" class="form-control" style="width: 100%;" required>
                            <option value="">Search for corporation...</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Alliance Cut (%)</label>
                                <div class="input-group">
                                    <input type="number" name="tax_rate" step="0.5" min="0" max="100" class="form-control" placeholder="15.0" required>
                                    <span class="input-group-addon">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="form-group">
                                <label>Min. Threshold (ISK)</label>
                                <input type="number" name="min_threshold" min="0" class="form-control" placeholder="100,000,000" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="switch">
                            <input type="checkbox" name="is_active" checked>
                            <span class="margin-l-5">Active & Tracking</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" style="padding: 10px; font-weight: 700; margin-top: 10px; box-shadow: 0 4px 10px rgba(60, 141, 188, 0.3);">
                        <i class="fa fa-plus"></i> Save Tax Definition
                    </button>
                </form>
            </div>
            <div class="col-md-7">
                <div class="form-section-title">Active Tax Definitions</div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-premium">
                        <thead>
                            <tr>
                                <th>Corporation</th>
                                <th class="text-center">Rate</th>
                                <th class="text-center">Min Threshold</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($settings as $setting)
                            <tr>
                                <td>
                                    {!! img('corporations', 'logo', $setting->corporation_id, 32, ['class' => 'img-circle corp-logo-circle']) !!}
                                    <span style="font-weight: 600; margin-left: 10px;">{{ $setting->corporation->name ?? 'Unknown' }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-blue" style="font-size: 13px; padding: 5px 10px;">{{ number_format($setting->tax_rate, 1) }}%</span>
                                </td>
                                <td class="text-center">
                                    <span class="text-muted" style="font-size: 12px;">{{ number_format($setting->min_threshold, 0) }} ISK</span>
                                </td>
                                <td class="text-center">
                                    @if($setting->is_active) 
                                        <span class="text-success"><i class="fa fa-check-circle"></i> Tracking</span>
                                    @else 
                                        <span class="text-muted"><i class="fa fa-times-circle"></i> Paused</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <button class="btn btn-xs btn-default btn-action" onclick="editCorp({{ $setting->corporation_id }}, {{ $setting->tax_rate }}, {{ $setting->min_threshold }}, {{ $setting->is_active ? 'true' : 'false' }})">
                                        <i class="fa fa-pencil text-warning"></i> Modify
                                    </button>
                                    <form method="POST" action="{{ route('alliancetax.corptax.settings.destroy', $setting->corporation_id) }}" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-link text-red" onclick="return confirm('Stop tracking this corporation?')">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted" style="padding: 40px;">No corporations defined. Start by adding one to the left.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Management Tabs -->
<div class="modern-card box custom-tabs">
    <div class="nav-tabs-custom">
        <ul class="nav nav-tabs">
            <li class="active"><a href="#tab_invoices" data-toggle="tab"><i class="fa fa-file-text-o"></i> Unpaid Invoices</a></li>
            <li><a href="#tab_history" data-toggle="tab"><i class="fa fa-history"></i> Settlement History</a></li>
        </ul>
        <div class="tab-content" style="padding: 0;">
            <!-- UNPAID TAB -->
            <div class="tab-pane active" id="tab_invoices">
                <table class="table table-premium mb-0">
                    <thead>
                        <tr>
                            <th style="padding-left: 20px;">Corporation</th>
                            <th>Billing Period</th>
                            <th class="text-right">Corp Wallet Yield</th>
                            <th class="text-right">Alliance Tax Owed</th>
                            <th class="text-right" style="padding-right: 20px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($calculations->where('status', 'pending') as $calc)
                        <tr>
                            <td style="padding-left: 20px;">
                                {!! img('corporations', 'logo', $calc->corporation_id, 32, ['class' => 'img-circle corp-logo-circle']) !!}
                                <span style="font-weight: 600; margin-left: 10px;">{{ $calc->corporation->name ?? 'Unknown' }}</span>
                            </td>
                            <td>
                                <span class="text-muted"><i class="fa fa-calendar-o"></i> {{ $calc->period_start->format('M d') }} - {{ $calc->period_end->format('M d') }}</span>
                            </td>
                            <td class="text-right" style="font-family: monospace;">{{ number_format($calc->total_bounty_value, 0) }} ISK</td>
                            <td class="text-right"><strong class="text-red" style="font-size: 15px;">{{ number_format($calc->tax_amount, 0) }} ISK</strong></td>
                            <td class="text-right" style="padding-right: 20px;">
                                <form method="POST" action="{{ route('alliancetax.corptax.mark-paid', $calc->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success btn-action"><i class="fa fa-check"></i> Settle</button>
                                </form>
                                <form method="POST" action="{{ route('alliancetax.corptax.destroy', $calc->id) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-link text-red" onclick="return confirm('Delete record?')"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted" style="padding: 50px;">The queue is clear! No outstanding corporate invoices.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- HISTORY TAB -->
            <div class="tab-pane" id="tab_history">
                <table class="table table-premium mb-0">
                    <thead>
                        <tr>
                            <th style="padding-left: 20px;">Corporation</th>
                            <th class="text-right">Amount Settled</th>
                            <th>Settlement Date</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($calculations->where('status', 'paid') as $calc)
                        <tr>
                            <td style="padding-left: 20px;">
                                {!! img('corporations', 'logo', $calc->corporation_id, 32, ['class' => 'img-circle']) !!}
                                <span style="margin-left: 10px;">{{ $calc->corporation->name ?? 'Unknown' }}</span>
                            </td>
                            <td class="text-right"><strong class="text-success">{{ number_format($calc->tax_amount, 0) }} ISK</strong></td>
                            <td><span class="text-muted">{{ $calc->paid_at ? $calc->paid_at->format('Y-m-d H:i') : 'Unknown' }}</span></td>
                            <td class="text-center"><span class="label label-success" style="padding: 5px 12px; border-radius: 20px;">PAID</span></td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted" style="padding: 50px;">History vault is empty.</td></tr>
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
$(document).ready(function() {
    // Initialize Corporation Search with premium styling
    $('#corp_search').select2({
        ajax: {
            url: "{{ route('alliancetax.api.corporations.search') }}",
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term };
            },
            processResults: function (data) {
                return { results: data };
            },
            cache: true
        },
        placeholder: 'Search for a corporation...',
        minimumInputLength: 3
    });
});

function editCorp(id, rate, threshold, active) {
    var newOption = new Option("Selected Corp (" + id + ")", id, true, true);
    $('#corp_search').append(newOption).trigger('change');
    
    $('input[name="tax_rate"]').val(rate);
    $('input[name="min_threshold"]').val(threshold);
    $('input[name="is_active"]').prop('checked', active);
    
    // Smooth scroll to form
    $('html, body').animate({
        scrollTop: $(".form-section-title").offset().top - 100
    }, 500);
}
</script>
@endpush
