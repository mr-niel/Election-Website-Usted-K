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
define('MNOTIFY_SENDER_ID', 'mNotify');

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
        'recipient'     => [$local],
        'sender'        => MNOTIFY_SENDER_ID,
        'message'       => $message,
        'is_schedule'   => false,
        'schedule_date' => '',
    ]);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $raw = false;
    $code = 0;
    $err = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\\s(\\d{3})\\s/', $statusLine, $matches)) {
            $code = (int)$matches[1];
        }
        if ($raw === false) {
            $err = 'Unable to connect to the SMS gateway.';
        }
    }

    if ($err) {
        return ['success' => false, 'message' => 'SMS gateway error: ' . $err, 'status' => 'network_error'];
    }

    $data = json_decode((string)$raw, true);

    if ($code >= 200 && $code < 300 && is_array($data) && ($data['status'] ?? '') === 'success') {
        return [
            'success' => true,
            'message' => 'SMS sent successfully.',
            'status'  => 'success',
        ];
    }

    $errMsg = 'Unknown SMS error';
    if (is_array($data)) {
        $errMsg = $data['message']
            ?? $data['error']
            ?? $data['errorMessage']
            ?? $data['detail']
            ?? $errMsg;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $errMsg = trim($raw);
    }

    if ($code > 0) {
        $errMsg .= ' (HTTP ' . $code . ')';
    }

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
