<?php
/**
 * Minimal Razorpay REST client (Payment Links + Payments) using cURL.
 * Docs: https://razorpay.com/docs/api/payments/payment-links/
 */
declare(strict_types=1);

class RazorpayClient
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function __construct(private string $keyId, private string $keySecret)
    {
    }

    public static function fromSettings(): self
    {
        $k = (string)setting('rzp_key_id', '');
        $s = (string)setting('rzp_key_secret', '');
        if ($k === '' || $s === '') {
            throw new RuntimeException('Razorpay API keys are not configured. Open Settings > Razorpay.');
        }
        return new self($k, $s);
    }

    public function request(string $method, string $path, ?array $data = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($res === false) {
            throw new RuntimeException('Could not reach Razorpay: ' . $err);
        }
        $json = json_decode((string)$res, true);
        if ($code >= 400 || !is_array($json)) {
            $msg = is_array($json) ? ($json['error']['description'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
            throw new RuntimeException('Razorpay error: ' . $msg);
        }
        return $json;
    }

    public function createPaymentLink(array $payload): array
    {
        return $this->request('POST', '/payment_links', $payload);
    }

    public function fetchPaymentLink(string $id): array
    {
        return $this->request('GET', '/payment_links/' . rawurlencode($id));
    }

    public function cancelPaymentLink(string $id): array
    {
        return $this->request('POST', '/payment_links/' . rawurlencode($id) . '/cancel', []);
    }

    public function fetchPayment(string $id): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($id));
    }

    /** Cheap call used by the "Test keys" button in Settings. */
    public function ping(): bool
    {
        $this->request('GET', '/payment_links?count=1');
        return true;
    }

    /** Verify the X-Razorpay-Signature header of a webhook call. */
    public static function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool
    {
        if ($signature === '' || $webhookSecret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $signature);
    }

    /**
     * Verify the signature Razorpay appends to the callback_url after a payment-link payment.
     * payload = payment_link_id | payment_link_reference_id | payment_link_status | razorpay_payment_id
     */
    public static function verifyCallbackSignature(array $q, string $keySecret): bool
    {
        $sig = (string)($q['razorpay_signature'] ?? '');
        if ($sig === '' || $keySecret === '') {
            return false;
        }
        $payload = implode('|', [
            (string)($q['razorpay_payment_link_id'] ?? ''),
            (string)($q['razorpay_payment_link_reference_id'] ?? ''),
            (string)($q['razorpay_payment_link_status'] ?? ''),
            (string)($q['razorpay_payment_id'] ?? ''),
        ]);
        return hash_equals(hash_hmac('sha256', $payload, $keySecret), $sig);
    }
}
