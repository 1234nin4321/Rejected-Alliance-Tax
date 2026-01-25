@extends('web::layouts.grids.12')

@section('title', 'Tax Exemptions Management')
@section('page_header', 'Tax Exemptions Management')

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
    <!-- Add New Exemption -->
    <div class="col-md-4">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Add Tax Exemption</h3>
            </div>
            <form method="POST" action="{{ route('alliancetax.admin.exemptions.store') }}">
                @csrf
                <div class="box-body">
                    <div class="form-group">
                        <label>Exempt</label>
                        <select class="form-control" id="exempt_type" name="exempt_type" required>
                            <option value="">Select...</option>
                            <option value="character">Individual Character</option>
                            <option value="corporation">Entire Corporation</option>
                        </select>
                    </div>

                    <div class="form-group" id="character_id_group" style="display: none;">
                        <label for="character_id">Character ID</label>
                        <input type="number" class="form-control" id="character_id" name="character_id" placeholder="Enter character ID">
                    </div>

                    <div class="form-group" id="corporation_id_group" style="display: none;">
                        <label for="corporation_id">Corporation ID</label>
                        <input type="number" class="form-control" id="corporation_id" name="corporation_id" placeholder="Enter corporation ID">
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason</label>
                        <textarea class="form-control" 
                                  id="reason" 
                                  name="reason" 
                                  rows="3" 
                                  required
                                  placeholder="e.g., New member grace period, Special role, etc."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="exempt_from">Exempt From</label>
                        <input type="date" class="form-control" id="exempt_from" name="exempt_from" value="{{ date('Y-m-d') }}" required>
                    </div>

                    <div class="form-group">
                        <label for="exempt_until">Exempt Until (Optional)</label>
                        <input type="date" class="form-control" id="exempt_until" name="exempt_until">
                        <p class="help-block">Leave empty for permanent exemption.</p>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn btn-warning btn-block">
                        <i class="fa fa-shield"></i> Add Exemption
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Exemptions -->
    <div class="col-md-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Active Exemptions</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover" id="exemptions-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Entity</th>
                            <th>Reason</th>
                            <th>Exempt From</th>
                            <th>Exempt Until</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($exemptions as $exemption)
                        <tr class="{{ !$exemption->is_active ? 'text-muted' : '' }}">
                            <td>
                                @if($exemption->character_id)
                                    <span class="label label-info">Character</span>
                                @else
                                    <span class="label label-primary">Corporation</span>
                                @endif
                            </td>
                            <td>
                                @if($exemption->character_id)
                                    {!! img('characters', 'portrait', $exemption->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                    {{ $exemption->character->name ?? 'Character ' . $exemption->character_id }}
                                @elseif($exemption->corporation_id)
                                    {!! img('corporations', 'logo', $exemption->corporation_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                    {{ $exemption->corporation->name ?? 'Corporation ' . $exemption->corporation_id }}
                                @endif
                            </td>
                            <td>
                                <small>{{ Str::limit($exemption->reason, 50) }}</small>
                            </td>
                            <td>{{ $exemption->exempt_from->format('Y-m-d') }}</td>
                            <td>{{ $exemption->exempt_until ? $exemption->exempt_until->format('Y-m-d') : 'Permanent' }}</td>
                            <td>
                                @php
                                    $now = now();
                                    $isActive = $exemption->is_active && 
                                                $exemption->exempt_from <= $now && 
                                                (!$exemption->exempt_until || $exemption->exempt_until >= $now);
                                @endphp
                                
                                @if($isActive)
                                    <span class="label label-success">Active</span>
                                @elseif($exemption->exempt_from > $now)
                                    <span class="label label-info">Scheduled</span>
                                @elseif($exemption->exempt_until && $exemption->exempt_until < $now)
                                    <span class="label label-default">Expired</span>
                                @else
                                    <span class="label label-default">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('alliancetax.admin.exemptions.destroy', $exemption->id) }}" style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" 
                                            class="btn btn-xs btn-danger" 
                                            onclick="return confirm('Are you sure you want to remove this exemption?')"
                                            title="Remove Exemption">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No tax exemptions configured.
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
                <h3 class="box-title">About Tax Exemptions</h3>
            </div>
            <div class="box-body">
                <p><strong>Exemptions can be used for:</strong></p>
                <ul>
                    <li><strong>New Members</strong> - Grace period for new alliance/corp members</li>
                    <li><strong>Special Roles</strong> - Exempt leadership, recruiters, or special positions</li>
                    <li><strong>Temporary Relief</strong> - Hardship cases or special circumstances</li>
                    <li><strong>Corp-Wide</strong> - Exempt entire corporations from taxes</li>
                </ul>
                <p class="text-muted">
                    <i class="fa fa-info-circle"></i>
                    Exempted characters/corporations will not have taxes calculated during the exemption period.
                </p>
            </div>
        </div>
    </div>
</div>

@endsection

@push('javascript')
<script>
$(function() {
    // Show/hide fields based on exemption type
    $('#exempt_type').on('change', function() {
        var type = $(this).val();
        
        $('#character_id_group').hide();
        $('#corporation_id_group').hide();
        $('#character_id').prop('required', false);
        $('#corporation_id').prop('required', false);
        
        if (type === 'character') {
            $('#character_id_group').show();
            $('#character_id').prop('required', true);
        } else if (type === 'corporation') {
            $('#corporation_id_group').show();
            $('#corporation_id').prop('required', true);
        }
    });

    // DataTables - only initialize if there's data
    var $exemptionsTable = $('#exemptions-table');
    if ($exemptionsTable.find('tbody tr').not(':has(td[colspan])').length > 0) {
        $exemptionsTable.DataTable({
            order: [[3, 'desc']],
            pageLength: 25,
            columns: [
                null, // Type
                null, // Entity
                null, // Reason
                null, // Exempt From
                null, // Exempt Until
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
