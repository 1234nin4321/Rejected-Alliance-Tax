<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiConfiguration;

class ScopeAuthController extends Controller
{
    /**
     * Redirect to EVE SSO to add mail scope to character
     */
    public function authorize($characterId)
    {
        $clientId = config('eseye.eseye_client_id') ?? config('services.eveonline.client_id');
        $callbackUrl = route('alliancetax.scope.callback');
        
        $state = base64_encode(json_encode([
            'character_id' => $characterId,
            'redirect' => route('alliancetax.admin.settings'),
        ]));
        
        $scopes = [
            'esi-mail.send_mail.v1',
        ];
        
        $url = sprintf(
            '%s?response_type=code&redirect_uri=%s&client_id=%s&scope=%s&state=%s',
            'https://login.eveonline.com/v2/oauth/authorize',
            urlencode($callbackUrl),
            $clientId,
            urlencode(implode(' ', $scopes)),
            $state
        );
        
        return redirect($url);
    }
    
    /**
     * Handle callback from EVE SSO
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('alliancetax.admin.settings')
                ->with('error', 'Authorization cancelled or failed.');
        }
        
        $code = $request->get('code');
        $state = json_decode(base64_decode($request->get('state')), true);
        
        try {
            $clientId = config('eseye.eseye_client_id') ?? config('services.eveonline.client_id');
            $clientSecret = config('eseye.eseye_client_secret') ?? config('services.eveonline.client_secret');
            
            // Exchange code for token
            $response = \Http::asForm()->post('https://login.eveonline.com/v2/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
            ])->withBasicAuth($clientId, $clientSecret);
            
            if (!$response->successful()) {
                throw new \Exception('Failed to exchange authorization code');
            }
            
            $tokenData = $response->json();
            
            // Update the refresh token scopes
            $token = \Seat\Eveapi\Models\RefreshToken::where('character_id', $state['character_id'])->first();
            
            if ($token) {
                $existingScopes = is_array($token->scopes) ? $token->scopes : json_decode($token->scopes, true) ?? [];
                $newScopes = array_unique(array_merge($existingScopes, ['esi-mail.send_mail.v1']));
                $token->scopes = $newScopes;
                $token->token = $tokenData['refresh_token'];
                $token->save();
                
                return redirect()->route('alliancetax.admin.settings')
                    ->with('success', 'Mail sending scope added successfully!');
            }
            
            return redirect()->route('alliancetax.admin.settings')
                ->with('error', 'Character token not found.');
                
        } catch (\Exception $e) {
            \Log::error('[AllianceTax] Scope authorization failed: ' . $e->getMessage());
            
            return redirect()->route('alliancetax.admin.settings')
                ->with('error', 'Failed to add mail scope. Please try logging in again.');
        }
    }
}
