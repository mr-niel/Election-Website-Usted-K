<?php
/**
 * SMS Helper — mNotify (BMS API v2.0) integration for OTP delivery.
 *
 * Sends real SMS to Ghana phone numbers using the mNotify bulk SMS API.
 * API key is passed as a GET parameter on every request.
 *
 * Docs: https://api.mnotify.com/api/sms/quick
 */

define('MNOTIFY_API_KEY', 'WeREzYyaCp23dcASc9Iskokq3');
define('MNOTIFY_SENDER_ID', 'USTED');   // must be registered/approved on your mNotify account

/**
 * Send an SMS to a single recipient via mNotify.
 *
 * @param string $to       Phone number in international format, e.g. +233241234567
 * @param string $message  The message body
 * @return array           ['success' => bool, 'message' => string, 'status' => string]
 */
function send_sms(string $to, string $message): array
{
    $local = to_mnotify_local($to);
    if ($local === null) {
        return ['success' => false, 'message' => 'Invalid phone number for SMS.', 'status' => 'invalid_number'];
    }

    $url = 'https://api.mnotify.com/api/sms/quick?key=' . urlencode(MNOTIFY_API_KEY);

    $payload = json_encode([
        'recipient'    => [$local],
        'sender'       => MNOTIFY_SENDER_ID,
        'message'      => $message,
        'is_schedule'  => false,
        'schedule_date' => '',
        'sms_type'     => 'otp',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
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

    if ($code >= 200 && $code < 300 && ($data['status'] ?? '') === 'success') {
        return [
            'success' => true,
            'message' => 'SMS sent successfully.',
            'status'  => 'success',
        ];
    }

    $errMsg = $data['message'] ?? ($data['errorMessage'] ?? 'Unknown SMS error');
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

/**
 * Convert international format (+233241234567) to mNotify local format (0241234567).
 * Returns null if the number is not a valid Ghana number.
 */
function to_mnotify_local(string $input): ?string
{
    $input = preg_replace('/\s+/', '', $input);
    $input = preg_replace('/[^0-9+]/', '', $input);

    if (preg_match('/^\+233(\d{9})$/', $input, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^233(\d{9})$/', $input, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^0\d{9}$/', $input)) {
        return $input;
    }
    return null;
}
