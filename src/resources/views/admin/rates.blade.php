@extends('web::layouts.grids.12')

@section('title', 'Tax Rates Management')
@section('page_header', 'Tax Rates Management')

@section('full')

@if(session('success'))
<div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="icon fa fa-check"></i> {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <h4><i class="icon fa fa-ban"></i> Error!</h4>
    <ul>
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="row">
    <!-- Add New Rate -->
    <div class="col-md-4">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Add New Tax Rate</h3>
            </div>
            <form method="POST" action="{{ route('alliancetax.admin.rates.store') }}">
                @csrf
                <div class="box-body">
                    <div class="form-group">
                        <label>Apply To</label>
                        <select class="form-control" id="rate_type" name="rate_type" required>
                            <option value="">Select...</option>
                            <option value="alliance">Entire Alliance</option>
                            <option value="corporation">Specific Corporation</option>
                        </select>
                    </div>

                    <div class="form-group" id="alliance_id_group" style="display: none;">
                        <label for="alliance_id">Alliance ID</label>
                        <input type="number" class="form-control" id="alliance_id" name="alliance_id" placeholder="Enter alliance ID">
                        <p class="help-block">Leave empty to use default alliance from settings.</p>
                    </div>

                    <div class="form-group" id="corporation_id_group" style="display: none;">
                        <label for="corporation_id">Corporation ID</label>
                        <input type="number" class="form-control" id="corporation_id" name="corporation_id" placeholder="Enter corporation ID" required>
                    </div>

                    <div class="form-group">
                        <label for="tax_rate">Tax Rate (%)</label>
                        <input type="number" 
                               class="form-control" 
                               id="tax_rate" 
                               name="tax_rate" 
                               step="0.01" 
                               min="0" 
                               max="100" 
                               required
                               placeholder="e.g., 10.00">
                    </div>

                    <div class="form-group">
                        <label for="item_category">Mining Category</label>
                        <select class="form-control" id="item_category" name="item_category" required>
                            <option value="all">All Items (Default)</option>
                            <option value="ore">Standard Ore</option>
                            <option value="ice">Ice</option>
                            <option value="moon_r4">Moon Ore (R4)</option>
                            <option value="moon_r8">Moon Ore (R8)</option>
                            <option value="moon_r16">Moon Ore (R16)</option>
                            <option value="moon_r32">Moon Ore (R32)</option>
                            <option value="moon_r64">Moon Ore (R64)</option>
                            <option value="gas">Gas</option>
                        </select>
                        <p class="help-block">Specific categories override 'All Items' rates.</p>
                    </div>

                    <div class="form-group">
                        <label for="effective_from">Effective From</label>
                        <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ date('Y-m-d') }}" required>
                    </div>

                    <div class="form-group">
                        <label for="effective_until">Effective Until (Optional)</label>
                        <input type="date" class="form-control" id="effective_until" name="effective_until">
                        <p class="help-block">Leave empty for no end date.</p>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn btn-success btn-block">
                        <i class="fa fa-plus"></i> Add Tax Rate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Rates -->
    <div class="col-md-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Active Tax Rates</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover" id="rates-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>ID</th>
                            <th>Category</th>
                            <th>Rate</th>
                            <th>Effective From</th>
                            <th>Effective Until</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rates as $rate)
                        <tr>
                            <td>
                                @if($rate->corporation_id)
                                    <span class="label label-info">Corporation</span>
                                @else
                                    <span class="label label-primary">Alliance</span>
                                @endif
                            </td>
                            <td>
                                @if($rate->corporation_id)
                                    {!! img('corporations', 'logo', $rate->corporation_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                    {{ $rate->corporation_id }}
                                @elseif($rate->alliance_id)
                                    {!! img('alliances', 'logo', $rate->alliance_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                    {{ $rate->alliance_id }}
                                @else
                                    <em>Default</em>
                                @endif
                            </td>
                            <td>
                                @if($rate->item_category === 'moon_r64')
                                    <span class="label label-danger">Moon Ore (R64)</span>
                                @elseif($rate->item_category === 'moon_r32')
                                    <span class="label label-warning" style="background-color: #d35400;">Moon Ore (R32)</span>
                                @elseif($rate->item_category === 'moon_r16')
                                    <span class="label label-warning">Moon Ore (R16)</span>
                                @elseif($rate->item_category === 'moon_r8')
                                    <span class="label label-success">Moon Ore (R8)</span>
                                @elseif($rate->item_category === 'moon_r4')
                                    <span class="label label-info">Moon Ore (R4)</span>
                                @elseif($rate->item_category === 'ice')
                                    <span class="label label-info">Ice</span>
                                @elseif($rate->item_category === 'ore')
                                    <span class="label label-default">Standard Ore</span>
                                @elseif($rate->item_category === 'gas')
                                    <span class="label label-warning" style="background-color: #6347ff;">Gas</span>
                                @else
                                    <span class="label label-primary">All Items</span>
                                @endif
                            </td>
                            <td>
                                <strong>{{ number_format($rate->tax_rate, 2) }}%</strong>
                            </td>
                            <td>{{ $rate->effective_from->format('Y-m-d') }}</td>
                            <td>{{ $rate->effective_until ? $rate->effective_until->format('Y-m-d') : 'No end date' }}</td>
                            <td>
                                @if($rate->is_active)
                                    <span class="label label-success">Active</span>
                                @else
                                    <span class="label label-default">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-xs btn-warning" data-toggle="modal" data-target="#editModal{{ $rate->id }}">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <form method="POST" action="{{ route('alliancetax.admin.rates.destroy', $rate->id) }}" style="display: inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Are you sure you want to delete this tax rate?')">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal{{ $rate->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="{{ route('alliancetax.admin.rates.update', $rate->id) }}">
                                        @csrf
                                        @method('PUT')
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            <h4 class="modal-title">Edit Tax Rate</h4>
                                        </div>
                                        <div class="modal-body">
                                            <div class="form-group">
                                                <label for="edit_tax_rate{{ $rate->id }}">Tax Rate (%)</label>
                                                <input type="number" 
                                                       class="form-control" 
                                                       id="edit_tax_rate{{ $rate->id }}" 
                                                       name="tax_rate" 
                                                       value="{{ $rate->tax_rate }}"
                                                       step="0.01" 
                                                       min="0" 
                                                       max="100" 
                                                       required>
                                            </div>
                                            <div class="form-group">
                                                <div class="checkbox">
                                                    <label>
                                                        <input type="checkbox" 
                                                               name="is_active" 
                                                               value="1"
                                                               {{ $rate->is_active ? 'checked' : '' }}>
                                                        Active
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No custom tax rates configured. The default rate will be used.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Info Box -->
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">How Tax Rates Work</h3>
            </div>
            <div class="box-body">
                <p><strong>Tax rates are applied in this order of priority:</strong></p>
                <ol>
                    <li><strong>Corporation-specific rate</strong> - Highest priority</li>
                    <li><strong>Alliance-wide rate</strong> - Medium priority</li>
                    <li><strong>Default rate</strong> (from settings) - Lowest priority</li>
                </ol>
                <p class="text-muted">
                    <i class="fa fa-info-circle"></i>
                    If a character belongs to a corporation with a custom rate, that rate will be used. 
                    Otherwise, the alliance rate (if set) or default rate will apply.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection

@push('javascript')
<script>
$(function() {
    // Show/hide fields based on rate type
    $('#rate_type').on('change', function() {
        var type = $(this).val();
        
        $('#alliance_id_group').hide();
        $('#corporation_id_group').hide();
        $('#corporation_id').prop('required', false);
        
        if (type === 'alliance') {
            $('#alliance_id_group').show();
        } else if (type === 'corporation') {
            $('#corporation_id_group').show();
            $('#corporation_id').prop('required', true);
        }
    });

    // DataTables - only initialize if there's data
    var $ratesTable = $('#rates-table');
    if ($ratesTable.find('tbody tr').not(':has(td[colspan])').length > 0) {
        $ratesTable.DataTable({
            order: [[3, 'desc']],
            pageLength: 25,
            columns: [
                null, // Type
                null, // ID
                null, // Category
                null, // Rate
                null, // Effective From
                null, // Effective Until
                null, // Status
                null  // Actions
            ]
        });
    }

    // Auto-dismiss alerts
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
});
</script>
@endpush
