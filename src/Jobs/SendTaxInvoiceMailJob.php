<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\RefreshToken;

class SendTaxInvoiceMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoice;

    public function __construct(AllianceTaxInvoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function handle()
    {
        Log::info("[AllianceTax] Starting to send invoice mail for invoice ID: {$this->invoice->id}");
        
        try {
            // Get a refresh token with mail sending capability
            $token = $this->getMailToken();
            
            if (!$token) {
                Log::warning("[AllianceTax] No character token available for sending mail. Please ensure a character with 'esi-mail.send_mail.v1' scope is added to SeAT.");
                return;
            }

            Log::info("[AllianceTax] Using character {$token->character_id} to send mail");
            
            $accessToken = $token->token;
            
            // Prepare mail content
            $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');
            $corpName = 'Alliance Tax Corporation';
            
            if ($taxCorpId) {
                $corp = CorporationInfo::find($taxCorpId);
                $corpName = $corp ? $corp->name : $corpName;
            }
            
            $subject = "Mining Tax Invoice - " . number_format($this->invoice->amount, 0) . " ISK Due";
            
            $body = "<font size=\"12\" color=\"#bfffffff\">Dear Pilot,</font><br><br>";
            $body .= "This is your mining tax invoice for the period ending <b>" . $this->invoice->invoice_date->format('Y-m-d') . "</b>.<br><br>";
            $body .= "<font size=\"13\" color=\"#ff7fffff\"><b>=== INVOICE DETAILS ===</b></font><br>";
            $body .= "<font size=\"12\" color=\"#ffffa600\">Amount Due: <b>" . number_format($this->invoice->amount, 2) . " ISK</b></font><br>";
            $body .= "Due Date: <b>" . $this->invoice->due_date->format('Y-m-d') . "</b><br>";
            $body .= "Invoice Note: " . ($this->invoice->invoice_note ?? 'Mining tax payment') . "<br><br>";
            
            if ($taxCorpId) {
                $body .= "<font size=\"12\" color=\"#ff00ff00\"><b>Payment Instructions:</b></font><br>";
                $body .= "Please send <b>" . number_format($this->invoice->amount, 0) . " ISK</b> to:<br>";
                $body .= "Corporation: <b>{$corpName}</b><br>";
                $body .= "Corp ID: {$taxCorpId}<br><br>";
                $body .= "<font size=\"11\" color=\"#bfffffff\">Your payment will be automatically tracked and reconciled.</font><br><br>";
            }
            
            $body .= "You can view your detailed tax breakdown in the Alliance Tax section of AUTH.<br><br>";
            $body .= "Thank you for your contribution to the alliance!<br><br>";
            $body .= "<font size=\"14\" color=\"#ffffa600\">o7</font><br>";
            $body .= "<font size=\"10\" color=\"#bfffffff\">Alliance Tax Management</font>";

            Log::info("[AllianceTax] Sending mail to character {$this->invoice->character_id} via ESI");
            
            // Send via ESI
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://esi.evetech.net/latest/characters/{$token->character_id}/mail/", [
                'approved_cost' => 0,
                'body' => $body,
                'recipients' => [
                    [
                        'recipient_id' => $this->invoice->character_id,
                        'recipient_type' => 'character'
                    ]
                ],
                'subject' => $subject,
            ]);

            if ($response->successful()) {
                Log::info("[AllianceTax] Successfully sent tax invoice mail to character {$this->invoice->character_id}");
                
                // Mark as notified
                $this->invoice->notified_at = now();
                $this->invoice->save();
            } else {
                Log::error("[AllianceTax] Failed to send mail. HTTP Status: " . $response->status());
                Log::error("[AllianceTax] Response: " . $response->body());
                throw new \Exception("ESI mail send failed: " . $response->body());
            }
            
        } catch (\Exception $e) {
            Log::error("[AllianceTax] Error sending tax invoice mail: " . $e->getMessage());
            Log::error("[AllianceTax] Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Get a refresh token that can send mail
     */
    protected function getMailToken()
    {
        // First, try to use the configured mail sender character
        $mailSenderCharId = AllianceTaxSetting::get('mail_sender_character_id');
        
        if ($mailSenderCharId) {
            $token = RefreshToken::where('character_id', $mailSenderCharId)
                ->whereJsonContains('scopes', 'esi-mail.send_mail.v1')
                ->first();
                
            if ($token) {
                Log::info("[AllianceTax] Using configured mail sender character: {$mailSenderCharId}");
                return $token;
            } else {
                Log::warning("[AllianceTax] Configured mail sender character {$mailSenderCharId} has no valid token with mail scope");
            }
        }
        
        // Fallback: try to get a token from the tax collection corp
        $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');
        
        if ($taxCorpId) {
            $token = RefreshToken::whereHas('character.affiliation', function($query) use ($taxCorpId) {
                    $query->where('corporation_id', $taxCorpId);
                })
                ->whereJsonContains('scopes', 'esi-mail.send_mail.v1')
                ->first();
                
            if ($token) {
                Log::info("[AllianceTax] Using tax corp character for mail");
                return $token;
            }
        }
        
        // Last resort: any token with mail scope
        $token = RefreshToken::whereJsonContains('scopes', 'esi-mail.send_mail.v1')->first();
        
        if ($token) {
            Log::info("[AllianceTax] Using fallback character for mail: {$token->character_id}");
        }
        
        return $token;
    }
}
