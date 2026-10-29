<?php
/**
 * WhatsApp Cloud API (Meta) client.
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api/
 *
 * Business-initiated messages (like a fee request) MUST use an approved template.
 * Plain text messages only work inside the 24-hour customer service window.
 *
 * To use a different WhatsApp provider (Interakt, Gupshup, WATI, Twilio ...)
 * replace the two public methods below; the rest of the app only calls
 * sendTemplate() and sendText() and expects the same return array.
 */
declare(strict_types=1);

class WhatsAppClient
{
    public function __construct(
        private string $token,
        private string $phoneNumberId,
        private string $version = 'v22.0'
    ) {
    }

    public static function fromSettings(): self
    {
        $token = (string)setting('wa_token', '');
        $pnid  = (string)setting('wa_phone_number_id', '');
        if ($token === '' || $pnid === '') {
            throw new RuntimeException('WhatsApp API is not configured. Open Settings > WhatsApp.');
        }
        return new self($token, $pnid, (string)setting('wa_api_version', 'v22.0'));
    }

    /**
     * Send an approved template.
     * $bodyParams  - list of strings for {{1}}, {{2}} ... in the template body
     * $buttons     - list of ['index' => '0', 'text' => 'dynamic-url-suffix'] for URL buttons
     */
    public function sendTemplate(string $to, string $template, string $lang, array $bodyParams = [], array $buttons = []): array
    {
        $components = [];
        if ($bodyParams) {
            $params = [];
            foreach ($bodyParams as $p) {
                $params[] = ['type' => 'text', 'text' => self::cleanParam((string)$p)];
            }
            $components[] = ['type' => 'body', 'parameters' => $params];
        }
        foreach ($buttons as $b) {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => (string)($b['index'] ?? '0'),
                'parameters' => [['type' => 'text', 'text' => (string)($b['text'] ?? '')]],
            ];
        }
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $lang ?: 'en'],
            ],
        ];
        if ($components) {
            $payload['template']['components'] = $components;
        }
        return $this->send($payload);
    }

    public function sendText(string $to, string $text, bool $previewUrl = true): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => $previewUrl, 'body' => $text],
        ]);
    }

    /** Template parameters may not contain newlines, tabs or 4+ consecutive spaces. */
    public static function cleanParam(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $s = preg_replace('/ {4,}/', '   ', $s) ?? $s;
        $s = trim($s);
        return $s === '' ? '-' : $s;
    }

    private function send(array $payload): array
    {
        $url = 'https://graph.facebook.com/' . $this->version . '/' . $this->phoneNumberId . '/messages';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not reach WhatsApp API: ' . $err, 'http' => 0, 'raw' => null];
        }
        $json = json_decode((string)$res, true);
        if ($code >= 200 && $code < 300 && isset($json['messages'][0]['id'])) {
            return ['ok' => true, 'id' => $json['messages'][0]['id'], 'error' => null, 'http' => $code, 'raw' => $json];
        }
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        if (!empty($json['error']['error_data']['details'])) {
            $msg .= ' - ' . $json['error']['error_data']['details'];
        }
        return ['ok' => false, 'id' => null, 'error' => $msg, 'http' => $code, 'raw' => $json];
    }
}
