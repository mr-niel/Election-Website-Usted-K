<?php
/**
 * SMS Helper — Africa's Talking integration for OTP delivery.
 *
 * Uses sandbox mode by default (free, no real SMS sent).
 * To go live: set AT_LIVE=true and use your real username + API key.
 *
 * Africa's Talking sandbox: messages are delivered to the sandbox
 * dashboard (https://account.africastalking.com/sandbox) rather than
 * real phone numbers. This lets you test the full flow without cost.
 */

define('AT_USERNAME', 'sandbox');
define('AT_API_KEY', 'atsk_dbee8c96e0927371921b0927eecc610accf3593b120a2a23aab9aae8f638e83271c96b5f');
define('AT_SENDER_ID', 'USTED');     // appears as the sender on the phone
define('AT_SANDBOX', true);          // true = sandbox (free), false = live

/**
 * Send an SMS to a single recipient.
 *
 * @param string $to       Phone number in international format, e.g. +233241234567
 * @param string $message  The message body (max 160 chars for a single SMS)
 * @return array           ['success' => bool, 'message' => string, 'status' => string]
 */
function send_sms(string $to, string $message): array
{
    $url = AT_SANDBOX
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $payload = [
        'username' => AT_USERNAME,
        'to'       => $to,
        'message'  => $message,
    ];

    // The sandbox does NOT support custom sender IDs — it rejects any
    // "from" value with "Invalid Sender ID". Only send it in live mode,
    // and only if the sender ID has been registered/approved on your
    // Africa's Talking account.
    if (!AT_SANDBOX) {
        $payload['from'] = AT_SENDER_ID;
    }

    $params = http_build_query($payload);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . AT_API_KEY,
        ],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => 'SMS gateway error: ' . $err, 'status' => 'network_error'];
    }

    $data = json_decode($raw, true);

    if ($code >= 200 && $code < 300 && isset($data['SMSMessageData']['Recipients'])) {
        $recipients = $data['SMSMessageData']['Recipients'];
        if (count($recipients) > 0) {
            $status = $recipients[0]['status'] ?? 'Unknown';
            $cost   = $recipients[0]['cost'] ?? 'N/A';
            return [
                'success' => true,
                'message' => 'SMS queued successfully.',
                'status'  => $status,
                'cost'    => $cost,
            ];
        }
    }

    $errMsg = $data['SMSMessageData']['Message'] ?? ($data['errorMessage'] ?? 'Unknown SMS error');
    return ['success' => false, 'message' => 'SMS failed: ' . $errMsg, 'status' => 'api_error'];
}

/**
 * Generate a random 6-digit OTP code.
 */
function generate_otp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Send an OTP code to a phone number.
 *
 * @param string $to    Phone number in international format
 * @param string $code  The 6-digit OTP code
 * @return array        Result from send_sms()
 */
function send_otp(string $to, string $code): array
{
    $message = 'Your USTED Election verification code is: ' . $code .
               '. Do not share this code with anyone. It expires in 5 minutes.';
    return send_sms($to, $message);
}

/**
 * Normalize a Ghana phone number to international format.
 * Accepts: 0241234567, +233241234567, 233241234567
 * Returns: +233241234567  (or null if invalid)
 */
function normalize_ghana_phone(string $input): ?string
{
    $input = preg_replace('/\s+/', '', $input);
    $input = preg_replace('/[^0-9+]/', '', $input);

    if (preg_match('/^\+233\d{9}$/', $input)) {
        return $input;
    }
    if (preg_match('/^233\d{9}$/', $input)) {
        return '+' . $input;
    }
    if (preg_match('/^0\d{9}$/', $input)) {
        return '+233' . substr($input, 1);
    }
    return null;
}
