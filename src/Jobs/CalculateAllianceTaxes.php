<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxRate;
use Rejected\SeatAllianceTax\Models\AllianceTaxExemption;

class CalculateAllianceTaxes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The period start date.
     *
     * @var string
     */
    protected $periodStart;

    /**
     * The period end date.
     *
     * @var string
     */
    protected $periodEnd;

    /**
     * The period type.
     *
     * @var string
     */
    protected $periodType;

    /**
     * Create a new job instance.
     *
     * @param string $periodStart
     * @param string $periodEnd
     * @param string $periodType
     * @return void
     */
    public function __construct($periodStart, $periodEnd, $periodType = 'weekly')
    {
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
        $this->periodType = $periodType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $allianceId = \Rejected\SeatAllianceTax\Models\AllianceTaxSetting::get('alliance_id');
        
        if (!$allianceId) {
            return;
        }

        $service = new \Rejected\SeatAllianceTax\Services\TaxCalculationService();
        $service->processPeriod(
            $allianceId,
            \Carbon\Carbon::parse($this->periodStart),
            \Carbon\Carbon::parse($this->periodEnd)
        );

        // Check if automated processing is enabled
        $autoCalc = \Rejected\SeatAllianceTax\Models\AllianceTaxSetting::get('auto_calculate');
        if ($autoCalc === 'true' || $autoCalc === true) {
            // Automatically generate invoices for the calculations we just made
            $result = $service->generateInvoices(
                \Carbon\Carbon::parse($this->periodStart),
                \Carbon\Carbon::parse($this->periodEnd),
                7 // Default 7 days due
            );

            if (isset($result['count']) && $result['count'] > 0) {
                // Automatically send Discord notification
                $service->notifyDiscord($result['count']);
            }
        }
    }

    /**
     * Check if a character or corporation is exempt from taxes.
     *
     * @param int $characterId
     * @param int $corporationId
     * @return bool
     */
    protected function isExempt($characterId, $corporationId)
    {
        return AllianceTaxExemption::isCharacterExempt($characterId) ||
               AllianceTaxExemption::isCorporationExempt($corporationId);
    }
}
