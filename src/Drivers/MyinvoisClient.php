<?php

namespace EInvoiceSdk\Drivers;

use EInvoiceSdk\Exceptions\EInvoiceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Myinvois;

/**
 * The SDK client with our own token cache. The SDK caches a Carbon object with the token, which Laravel 13 apps
 * (cache.serializable_classes = false) cannot read back, so every call after the first crashed. We cache the plain string.
 */
class MyinvoisClient extends Myinvois
{
    public function getToken(): string
    {
        $clientId = $this->getSettings('client_id');
        $clientSecret = $this->getSettings('client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new EInvoiceException('Missing MyInvois client ID / client secret.');
        }

        $key = 'einvoice.token.'.sha1($clientId.'|'.($this->getSettings('preprod') ? 'preprod' : 'prod'));

        if (is_string($token = Cache::get($key))) {
            return $token;
        }

        $response = Http::asForm()->post($this->getEndpoint('/connect/token'), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => 'InvoicingAPI',
        ]);

        if ($response->clientError()) {
            throw new EInvoiceException($this->getTokenErrorMessage($response));
        }

        $response->throw();
        $token = (string) $response->json('access_token');

        // LHDN tokens last an hour; refresh five minutes early.
        Cache::put($key, $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 300)));

        return $token;
    }
}
