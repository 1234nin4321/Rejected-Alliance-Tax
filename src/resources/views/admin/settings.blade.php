@extends('web::layouts.grids.12')

@section('title', 'Alliance Tax Settings')
@section('page_header', 'Alliance Tax Settings')

@section('full')
<div class="row" style="margin-bottom: 20px;">
    <div class="col-md-12">
        <div class="pull-right">
            <a href="{{ route('alliancetax.admin.rates.index') }}" class="btn btn-primary">
                <i class="fa fa-percent"></i> Tax Rates
            </a>
            <a href="{{ route('alliancetax.admin.credits.index') }}" class="btn btn-info">
                <i class="fa fa-users"></i> Member Credits
            </a>
            <a href="{{ route('alliancetax.admin.exemptions.index') }}" class="btn btn-warning">
                <i class="fa fa-user-times"></i> Exemptions
            </a>
</div>
</div>
</div>

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
    <!-- General Settings -->
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">General Settings</h3>
            </div>
            <form method="POST" action="{{ route('alliancetax.admin.settings.update') }}">
                @csrf
                <div class="box-body">
                    <!-- Alliance ID -->
                    <div class="form-group">
                        <label for="alliance_id">Alliance ID</label>
                        <input type="number" 
                               class="form-control" 
                               id="alliance_id" 
                               name="alliance_id" 
                               value="{{ $settings['alliance_id'] ?? '' }}"
                               placeholder="Enter your alliance ID">
                        <p class="help-block">The alliance ID to track mining taxes for.</p>
                    </div>

                    <!-- Default Tax Rate -->
                    <div class="form-group">
                        <label for="default_tax_rate">Default Tax Rate (%)</label>
                        <input type="number" 
                               class="form-control" 
                               id="default_tax_rate" 
                               name="default_tax_rate" 
                               value="{{ $settings['default_tax_rate'] ?? 10.0 }}"
                               step="0.01"
                               min="0"
                               max="100"
                               required>
                        <p class="help-block">Default tax rate percentage when no specific rate is configured.</p>
                    </div>

                    <!-- Tax Period -->
                    <div class="form-group">
                        <label for="tax_period">Tax Calculation Period</label>
                        <select class="form-control" id="tax_period" name="tax_period" required>
                            <option value="weekly" {{ ($settings['tax_period'] ?? 'weekly') == 'weekly' ? 'selected' : '' }}>
                                Weekly (Monday to Sunday)
                            </option>
                            <option value="monthly" {{ ($settings['tax_period'] ?? 'weekly') == 'monthly' ? 'selected' : '' }}>
                                Monthly (1st to last day)
                            </option>
                        </select>
                        <p class="help-block">How often taxes should be calculated.</p>
                    </div>

                    <!-- Minimum Taxable Amount -->
                    <div class="form-group">
                        <label for="minimum_taxable_amount">Minimum Taxable Amount (ISK)</label>
                        <input type="number" 
                               class="form-control" 
                               id="minimum_taxable_amount" 
                               name="minimum_taxable_amount" 
                               value="{{ $settings['minimum_taxable_amount'] ?? 1000000 }}"
                               step="1000"
                               min="0"
                               required>
                        <p class="help-block">Minimum mining value before taxes are applied (1,000,000 = 1M ISK).</p>
                    </div>

                    <!-- Auto Calculate -->
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="hidden" name="auto_calculate" value="0">
                                <input type="checkbox" 
                                       name="auto_calculate" 
                                       value="1"
                                       {{ ($settings['auto_calculate'] ?? true) ? 'checked' : '' }}>
                                Enable Automatic Tax Calculations
                            </label>
                        </div>
                        <p class="help-block">Automatically calculate taxes at the end of each period.</p>
                    </div>

                    <!-- Tax Collection Corporation -->
                    <div class="form-group">
                        <label for="tax_collection_corporation_id">Tax Collection Corporation</label>
                        <select class="form-control" 
                                id="tax_collection_corporation_id" 
                                name="tax_collection_corporation_id">
                            @if(isset($settings['tax_collection_corporation_id']) && $settings['tax_collection_corporation_id'])
                                <option value="{{ $settings['tax_collection_corporation_id'] }}" selected>
                                    {{ $settings['tax_collection_corporation_name'] ?? 'Corporation ID: ' . $settings['tax_collection_corporation_id'] }}
                                </option>
                            @else
                                <option value="">Select a corporation...</option>
                            @endif
                        </select>
                        <input type="hidden" id="tax_collection_corporation_name_hidden" name="tax_collection_corporation_name" value="">
                        <p class="help-block">The corporation that receives tax payments. Used for automated payment reconciliation from wallet journal.</p>
                    </div>

                    <!-- Discord Webhook URL -->
                    <div class="form-group">
                        <label for="discord_webhook_url">Discord Webhook URL (Optional)</label>
                        <input type="url" 
                               class="form-control" 
                               id="discord_webhook_url" 
                               name="discord_webhook_url" 
                               value="{{ $settings['discord_webhook_url'] ?? '' }}"
                               placeholder="https://discord.com/api/webhooks/...">
                        <p class="help-block">Discord webhook for tax notifications. Sends a general announcement (no personal data). <a href="https://support.discord.com/hc/en-us/articles/228383668" target="_blank">How to create a webhook</a></p>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn btn-primary" id="save-settings-btn">
                        <i class="fa fa-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Stats & Manual Recalculation -->
    <div class="col-md-6">
        <!-- Taxed Systems -->
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Taxed Solar Systems</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">If any systems are listed here, tax will <strong>ONLY</strong> be charged for mining performed within these systems. If the list is empty, all systems are taxed.</p>
                
                <form method="POST" action="{{ route('alliancetax.admin.systems.store') }}" class="form-inline mb-3">
                    @csrf
                    <div class="form-group mr-2">
                        <select class="form-control" id="solar_system_id" name="solar_system_id" style="min-width: 300px;" required>
                            <option value="">Type to search for a system...</option>
                        </select>
                        <input type="hidden" id="solar_system_name_hidden" name="solar_system_name" value="">
                    </div>
                    <button type="submit" class="btn btn-success" id="add-system-btn">
                        <i class="fa fa-plus"></i> Add System
                    </button>
                </form>

                <hr>

                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>
                            <th>System Name</th>
                            <th>System ID</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($taxedSystems as $system)
                        <tr>
                            <td>{{ $system->solar_system_name }}</td>
                            <td>{{ $system->solar_system_id }}</td>
                            <td>
                                <form method="POST" action="{{ route('alliancetax.admin.systems.destroy', $system->id) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Remove this system from taxed systems?')">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center">No restricted systems. All mining activity is currently taxed.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Current Configuration</h3>
            </div>
            <div class="box-body">
                <dl class="dl-horizontal">
                    <dt>Alliance ID:</dt>
                    <dd>{{ $settings['alliance_id'] ?? 'Not Set' }}</dd>

                    <dt>Default Tax Rate:</dt>
                    <dd>{{ number_format($settings['default_tax_rate'] ?? 10, 2) }}%</dd>

                    <dt>Tax Period:</dt>
                    <dd>{{ ucfirst($settings['tax_period'] ?? 'weekly') }}</dd>

                    <dt>Minimum Amount:</dt>
                    <dd>{{ number_format($settings['minimum_taxable_amount'] ?? 1000000) }} ISK</dd>

                    <dt>Auto Calculate:</dt>
                    <dd>
                        @if($settings['auto_calculate'] ?? true)
                            <span class="label label-success">Enabled</span>
                        @else
                            <span class="label label-danger">Disabled</span>
                        @endif
                    </dd>

                    <dt>Custom Rates:</dt>
                    <dd>{{ $customRatesCount }} configured</dd>

                    <dt>Active Exemptions:</dt>
                    <dd>{{ $activeExemptionsCount }}</dd>
                </dl>
            </div>
        </div>

        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Manual Tax Calculation</h3>
            </div>
            <form method="POST" action="{{ route('alliancetax.admin.recalculate') }}">
                @csrf
                <div class="box-body">
                    <p>Manually trigger tax calculations for a specific period:</p>
                    
                    <div class="form-group">
                        <label for="period_start">Period Start</label>
                        <input type="date" class="form-control" id="period_start" name="period_start" required>
                    </div>

                    <div class="form-group">
                        <label for="period_end">Period End</label>
                        <input type="date" class="form-control" id="period_end" name="period_end" required>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn btn-warning">
                        <i class="fa fa-calculator"></i> Recalculate Taxes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('javascript')
<script>
$(function() {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Initialize Select2 for character search
    var $characterSelect = $('#mail_sender_character_id');
    var $corporationSelect = $('#tax_collection_corporation_id');
    var $systemSelect = $('#solar_system_id');
    
    // Check if there's an existing value
    var existingValue = $characterSelect.find('option:selected').val();
    var existingText = $characterSelect.find('option:selected').text();
    
    // Character Select2
    if ($characterSelect.length > 0) {
        $characterSelect.select2({
            ajax: {
                url: '{{ route('alliancetax.api.characters.search') }}',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term
                    };
                },
                processResults: function (data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 3,
            placeholder: 'Type at least 3 characters to search...',
            allowClear: true
        });

        // If there's an existing selection, trigger change to display it
        if (existingValue && existingValue !== '') {
            $characterSelect.val(existingValue).trigger('change');
        }
    }
    
    // Corporation Select2
    if ($corporationSelect.length > 0) {
        $corporationSelect.select2({
            ajax: {
                url: '{{ route('alliancetax.api.corporations.search') }}',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term
                    };
                },
                processResults: function (data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 3,
            placeholder: 'Type at least 3 characters to search...',
            allowClear: true
        });
    }

    // Solar System Select2
    if ($systemSelect.length > 0) {
        $systemSelect.select2({
            ajax: {
                url: '{{ route('alliancetax.api.systems.search') }}',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term
                    };
                },
                processResults: function (data) {
                    console.log('System search results:', data);
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: 'Type to search for a system...',
            allowClear: true
        });
    }
    
    // Capture character and corporation names on form submit
    $('#save-settings-btn').closest('form').on('submit', function(e) {
        // Character name
        if ($characterSelect.length > 0) {
            var selectedCharacter = $characterSelect.select2('data');
            if (selectedCharacter && selectedCharacter.length > 0 && selectedCharacter[0].text) {
                var characterText = selectedCharacter[0].text;
                $('#mail_sender_character_name_hidden').val(characterText);
            }
        }
        
        // Corporation name
        if ($corporationSelect.length > 0) {
            var selectedCorporation = $corporationSelect.select2('data');
            if (selectedCorporation && selectedCorporation.length > 0 && selectedCorporation[0].text) {
                var corporationText = selectedCorporation[0].text;
                $('#tax_collection_corporation_name_hidden').val(corporationText);
            }
        }
    });

    // Capture system name on add system form submit
    $('#add-system-btn').closest('form').on('submit', function(e) {
        if ($systemSelect.length > 0) {
            var selectedSystem = $systemSelect.select2('data');
            if (selectedSystem && selectedSystem.length > 0 && selectedSystem[0].text) {
                var systemText = selectedSystem[0].text;
                // Remove the (ID) part if it exists
                systemText = systemText.replace(/\s\(\d+\)$/, '');
                $('#solar_system_name_hidden').val(systemText);
            }
        }
    });
});
</script>
@endpush
