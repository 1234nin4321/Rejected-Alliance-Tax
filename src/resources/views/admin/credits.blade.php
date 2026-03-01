@extends('web::layouts.grids.12')

@section('title', 'Member Tax Credits')
@section('page_header', 'Member Tax Credits Management')

@section('full')

@if(session('success'))
<div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="icon fa fa-check"></i> {{ session('success') }}
</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Manual Tax Credit Adjustments</h3>
                <div class="box-tools">
                    <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#addCreditModal">
                        <i class="fa fa-plus"></i> Add Manual Credit
                    </button>
                    <form action="{{ route('alliancetax.admin.credits.recalculate') }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Recalculate all automated credits? Manual adjustments will be preserved.')">
                            <i class="fa fa-refresh"></i> Sync Automated Credits
                        </button>
                    </form>
                </div>
            </div>
            <div class="box-body">
                <p class="text-muted">
                    Balances are split into <strong>Automated Credits</strong> (recalculated from wallet logs) and <strong>Manual Adjustments</strong>. 
                    The total credit shown to the user is the sum of both.
                </p>
                <table class="table table-hover" id="credits-table">
                    <thead>
                        <tr>
                            <th>Character</th>
                            <th class="text-right">Automated Credit</th>
                            <th class="text-right">Manual Adjustment</th>
                            <th class="text-right">Total Balance</th>
                            <th>Note</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($balances as $balance)
                        <tr>
                            <td>
                                {!! img('characters', 'portrait', $balance->character_id, 32, ['class' => 'img-circle eve-icon small-icon']) !!}
                                {{ $balance->character_name ?? 'ID: ' . $balance->character_id }}
                            </td>
                            <td class="text-right text-muted">
                                {{ number_format($balance->balance, 0) }} ISK
                            </td>
                            <td class="text-right">
                                <strong class="{{ $balance->manual_credit > 0 ? 'text-success' : ($balance->manual_credit < 0 ? 'text-danger' : '') }}">
                                    {{ number_format($balance->manual_credit, 0) }} ISK
                                </strong>
                            </td>
                            <td class="text-right">
                                <strong style="font-size: 1.1em;">{{ number_format($balance->balance + $balance->manual_credit, 0) }} ISK</strong>
                            </td>
                            <td>
                                <small class="text-muted">{{ $balance->manual_credit_reason }}</small>
                            </td>
                            <td>
                                <button type="button" class="btn btn-xs btn-warning" 
                                        data-toggle="modal" 
                                        data-target="#editCreditModal" 
                                        data-id="{{ $balance->id }}"
                                        data-char="{{ $balance->character_name }}"
                                        data-amount="{{ $balance->manual_credit }}"
                                        data-reason="{{ $balance->manual_credit_reason }}">
                                    <i class="fa fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">No tax credits found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Credit Modal -->
<div class="modal fade" id="addCreditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('alliancetax.admin.credits.store') }}">
                @csrf
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Add Manual Credit Adjustment</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Target Character</label>
                        <select class="form-control" id="character_id" name="character_id" style="width: 100%" required>
                            <option value="">Search for a character...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Adjustment Amount (ISK)</label>
                        <input type="number" class="form-control" name="amount" placeholder="e.g. 500000000" required>
                        <p class="help-block">Use positive numbers for credits, negative for debits.</p>
                    </div>
                    <div class="form-group">
                        <label>Reason / Note</label>
                        <input type="text" class="form-control" name="reason" placeholder="e.g. Compensation for loss">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Credit Modal -->
<div class="modal fade" id="editCreditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="edit-form" action="">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Edit Manual Adjustment: <span id="edit-char-name"></span></h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Adjustment Amount (ISK)</label>
                        <input type="number" class="form-control" id="edit-amount" name="amount" required>
                    </div>
                    <div class="form-group">
                        <label>Reason / Note</label>
                        <input type="text" class="form-control" id="edit-reason" name="reason">
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

@endsection

@push('javascript')
<script>
$(function() {
    // Select2 for character search
    $('#character_id').select2({
        ajax: {
            url: '{{ route('alliancetax.api.characters.search') }}',
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
        minimumInputLength: 3,
        dropdownParent: $('#addCreditModal')
    });

    // Populate Edit Modal
    $('#editCreditModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var id = button.data('id');
        var char = button.data('char');
        var amount = button.data('amount');
        var reason = button.data('reason');

        var modal = $(this);
        modal.find('#edit-char-name').text(char);
        modal.find('#edit-amount').val(amount);
        modal.find('#edit-reason').val(reason);
        modal.find('#edit-form').attr('action', '{{ route('alliancetax.admin.credits.update', '') }}/' + id);
    });

    $('#credits-table').DataTable({
        order: [[3, 'desc']],
        pageLength: 50
    });
});
</script>
@endpush
