<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class SendDiscordTaxAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoiceCount;
    protected $seatUrl;

    public function __construct($invoiceCount, $seatUrl)
    {
        $this->invoiceCount = $invoiceCount;
        $this->seatUrl = $seatUrl;
    }

    public function handle()
    {
        $webhookUrl = AllianceTaxSetting::get('discord_webhook_url');
        
        if (!$webhookUrl) {
            Log::warning("[AllianceTax] Discord webhook not configured");
            return;
        }

        $taxCorpName = AllianceTaxSetting::get('tax_collection_corporation_name') ?? 'Alliance Tax Corporation';
        $myTaxesUrl = rtrim($this->seatUrl, '/') . '/alliance-tax/my-taxes';

        $embed = [
            "title" => "⚠️ Mining Tax Invoices Available",
            "description" => "**{$this->invoiceCount}** new mining tax invoice(s) have been generated and are now available.",
            "color" => 16753920, // Orange
            "fields" => [
                [
                    "name" => "📋 What to do",
                    "value" => "1. Log into SeAT\n2. Go to **Alliance Tax > My Taxes**\n3. View your invoice details\n4. Send payment to **{$taxCorpName}**",
                    "inline" => false
                ],
                [
                    "name" => "🔗 Quick Link",
                    "value" => "[View My Taxes]({$myTaxesUrl})",
                    "inline" => false
                ]
            ],
            "footer" => [
                "text" => "Payments are automatically tracked. Questions? Contact your tax administrator."
            ],
            "timestamp" => now()->toIso8601String()
        ];

        try {
            $response = Http::post($webhookUrl, [
                'content' => "@everyone Mining tax invoices are now available!",
                'embeds' => [$embed]
            ]);

            if ($response->successful()) {
                Log::info("[AllianceTax] Discord announcement sent for {$this->invoiceCount} invoices");
            } else {
                Log::error("[AllianceTax] Discord webhook failed: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("[AllianceTax] Discord announcement error: " . $e->getMessage());
        }
    }
}
