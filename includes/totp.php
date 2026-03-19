<?php
/**
 * TOTP (Time-based One-Time Password) — RFC 6238
 *
 * This implements the same algorithm used by Google Authenticator,
 * Microsoft Authenticator, Authy, etc. Here's how it works:
 *
 * 1. A shared secret is stored on both the server and the authenticator app
 * 2. Both sides divide the current Unix time by 30 (a "time step")
 * 3. They compute HMAC-SHA1 of the time step using the secret
 * 4. A 6-digit code is extracted from the result
 * 5. Since both sides use the same time and secret, they get the same code
 *
 * The code changes every 30 seconds. We allow a ±1 window (90 seconds)
 * to account for clock drift between server and phone.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Generate a random TOTP secret (base32-encoded).
 * This is the string that gets embedded in the QR code and stored in the DB.
 * 20 bytes = 32 base32 characters = 160 bits of entropy.
 */
function generateTotpSecret(): string {
    $bytes = random_bytes(20);
    return base32Encode($bytes);
}

/**
 * Verify a TOTP code against a secret.
 * Checks the current time step and ±1 window to handle clock drift.
 *
 * @param string $secret Base32-encoded secret
 * @param string $code   6-digit code from the authenticator app
 * @return bool True if the code is valid
 */
function verifyTotpCode(string $secret, string $code): bool {
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        return false;
    }

    $secretBytes = base32Decode($secret);
    $timeStep = floor(time() / 30);

    // Check current time step and ±1 window (allows for 30s clock drift)
    for ($i = -1; $i <= 1; $i++) {
        $computedCode = computeTotpCode($secretBytes, $timeStep + $i);
        if (hash_equals($computedCode, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Compute a 6-digit TOTP code for a given time step.
 *
 * This is the core algorithm (RFC 4226 HOTP applied to time):
 * 1. Pack the time step as an 8-byte big-endian integer
 * 2. HMAC-SHA1 it with the secret
 * 3. Use "dynamic truncation" to extract a 4-byte chunk
 * 4. Modulo 10^6 to get a 6-digit number
 */
function computeTotpCode(string $secretBytes, int $timeStep): string {
    // Pack time step as 8-byte big-endian
    $timeBytes = pack('N*', 0, $timeStep);

    // HMAC-SHA1
    $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);

    // Dynamic truncation: use the last nibble as an offset
    $offset = ord($hash[19]) & 0x0f;

    // Extract 4 bytes starting at the offset
    $binary = (ord($hash[$offset]) & 0x7f) << 24
            | ord($hash[$offset + 1]) << 16
            | ord($hash[$offset + 2]) << 8
            | ord($hash[$offset + 3]);

    // Modulo to get 6 digits, zero-padded
    return str_pad($binary % 1000000, 6, '0', STR_PAD_LEFT);
}

/**
 * Build the otpauth:// URI for QR code generation.
 * This is the standard format that authenticator apps expect when
 * scanning a QR code. It encodes the secret, issuer, and account name.
 *
 * @param string $secret  Base32-encoded secret
 * @param string $account Account label (e.g. "admin")
 * @param string $issuer  App name shown in the authenticator
 */
function buildTotpUri(string $secret, string $account, string $issuer = 'FileDump'): string {
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

// =========================================================================
// Base32 Encoding/Decoding
// =========================================================================
// Authenticator apps require base32-encoded secrets (RFC 4648).
// Base32 uses the characters A-Z and 2-7, which are easy to type manually
// if a QR code scan fails.

function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $result = '';
    foreach (str_split($binary, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $result .= $alphabet[bindec($chunk)];
    }

    return $result;
}

function base32Decode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper(rtrim($data, '='));

    $binary = '';
    foreach (str_split($data) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) continue;
        $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $result = '';
    foreach (str_split($binary, 8) as $byte) {
        if (strlen($byte) < 8) break;
        $result .= chr(bindec($byte));
    }

    return $result;
}
