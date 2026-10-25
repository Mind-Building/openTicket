<?php
/**
 * PayPal Service
 * Handles PayPal payment processing
 */

namespace App\Services;

use App\Core\Config;

class PayPalService
{
    private string $clientId;
    private string $clientSecret;
    private string $mode; // 'sandbox' or 'live'
    private string $baseUrl;

    public function __construct()
    {
        $this->mode = Config::get('paypal_mode', 'sandbox');
        
        if ($this->mode === 'live') {
            $this->clientId = $_ENV['PAYPAL_LIVE_CLIENT_ID'] ?? Config::get('paypal_live_client_id', '');
            $this->clientSecret = $_ENV['PAYPAL_LIVE_CLIENT_SECRET'] ?? Config::get('paypal_live_client_secret', '');
            $this->baseUrl = 'https://api-m.paypal.com';
        } else {
            $this->clientId = $_ENV['PAYPAL_SANDBOX_CLIENT_ID'] ?? Config::get('paypal_sandbox_client_id', '');
            $this->clientSecret = $_ENV['PAYPAL_SANDBOX_CLIENT_SECRET'] ?? Config::get('paypal_sandbox_client_secret', '');
            $this->baseUrl = 'https://api-m.sandbox.paypal.com';
        }
    }

    /**
     * Create PayPal order
     * @param array $items Reservation items
     * @param float $total Total amount (after voucher discount)
     * @param string $currency Currency code
     * @param string $returnUrl Return URL after payment
     * @param string $cancelUrl Cancel URL
     * @param array $vouchers Optional: Array of voucher codes used
     */
    public function createOrder(array $items, float $total, string $returnUrl, string $cancelUrl, string $currency = 'EUR', array $vouchers = []): ?array
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException('PayPal credentials not configured');
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            throw new \RuntimeException('Failed to get PayPal access token');
        }

        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($total, 2, '.', '')
                    ],
                    'description' => 'Ticket Bestellung' . (!empty($vouchers) ? ' (Gutscheine: ' . implode(', ', $vouchers) . ')' : '')
                ]
            ],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'brand_name' => Config::get('company_name', 'Ticket System'),
                'locale' => 'de-DE',
                'user_action' => 'PAY_NOW'
            ]
        ];

        $ch = curl_init($this->baseUrl . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
                'PayPal-Request-Id: ' . uniqid()
            ],
            CURLOPT_POSTFIELDS => json_encode($orderData)
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("PayPal create order cURL error (mode={$this->mode}): $curlError");
            throw new \RuntimeException('PayPal connection error: ' . $curlError);
        }

        if ($httpCode === 201) {
            $order = json_decode($response, true);

            if (function_exists('logPaymentStarted')) {
                $sessionId = $_SESSION['session_id'] ?? session_id() ?? 'unknown';
                logPaymentStarted($sessionId, $order['id'] ?? 'unknown', $total, $currency);
            }

            return $order;
        }

        // Surface PayPal's actual rejection reason instead of swallowing it
        $decoded = json_decode($response, true);
        $detail  = $decoded['message'] ?? $decoded['error_description'] ?? $response;
        // Include the first issue detail if present, including WHICH field is wrong
        if (!empty($decoded['details'][0])) {
            $issue = $decoded['details'][0];
            $detail .= ' [' . ($issue['issue'] ?? '')
                . (isset($issue['field']) ? ' @ ' . $issue['field'] : '')
                . (isset($issue['value']) ? ' = "' . $issue['value'] . '"' : '')
                . ': ' . ($issue['description'] ?? '') . ']';
        }
        error_log("PayPal create order error HTTP $httpCode (mode={$this->mode}): request=" . json_encode($orderData) . " response=$response");
        throw new \RuntimeException("PayPal order rejected (HTTP $httpCode): $detail");
    }

    /**
     * Capture PayPal order
     */
    public function captureOrder(string $orderId): ?array
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            throw new \RuntimeException('Failed to get PayPal access token');
        }

        $ch = curl_init($this->baseUrl . '/v2/checkout/orders/' . $orderId . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201) {
            $result = json_decode($response, true);
            
            $amount = 0;
            $currency = 'EUR';
            if (isset($result['purchase_units'][0]['payments']['captures'][0]['amount'])) {
                $amount = (float)($result['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);
                $currency = $result['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? 'EUR';
            }
            
            if (function_exists('logPaymentCompleted')) {
                $sessionId = $_SESSION['session_id'] ?? session_id() ?? 'unknown';
                logPaymentCompleted($sessionId, $orderId, $amount, $currency, null, [
                    'status' => $result['status'] ?? 'unknown',
                    'payer_email' => $result['payer']['email_address'] ?? 'unknown'
                ]);
            }
            
            return $result;
        }

        error_log("PayPal capture order error: $response");
        return null;
    }

    /**
     * Get access token from PayPal
     */
    private function getAccessToken(): ?string
    {
        $ch = curl_init($this->baseUrl . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST          => true,
            CURLOPT_USERPWD       => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_POSTFIELDS    => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER    => [
                'Accept: application/json',
                'Accept-Language: en_US'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            // e.g. SSL certificate problem, connection refused, timeout
            error_log("PayPal access token cURL error (mode={$this->mode}): $curlError");
            throw new \RuntimeException('PayPal connection error: ' . $curlError);
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['access_token'] ?? null;
        }

        // Log the full PayPal error response for debugging
        $decoded = json_decode($response, true);
        $detail  = $decoded['error_description'] ?? $decoded['message'] ?? $response;
        error_log("PayPal access token HTTP $httpCode (mode={$this->mode}): $detail");
        throw new \RuntimeException("PayPal auth failed (HTTP $httpCode): $detail");
    }

    /**
     * Get order details
     */
    public function getOrder(string $orderId): ?array
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return null;
        }

        $ch = curl_init($this->baseUrl . '/v2/checkout/orders/' . $orderId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }
}

