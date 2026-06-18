<?php
namespace App\Infrastructure;

class GoogleAuthenticator
{
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random base32 secret key
     */
    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Generate standard otpauth QR code URL
     */
    public static function getQrCodeUrl(string $issuer, string $accountName, string $secret): string
    {
        $label = urlencode($issuer . ':' . $accountName);
        $issuerEncoded = urlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerEncoded}";
    }

    /**
     * Calculate code for a given secret and time slice
     */
    public static function getCode(string $secret, int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / 30);
        }

        $secretKey = self::base32Decode($secret);

        // Pack time slice into big-endian 64-bit binary
        $timeBinary = pack('N*', 0) . pack('N*', $timeSlice);

        // HMAC-SHA1 hash
        $hash = hash_hmac('sha1', $timeBinary, $secretKey, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0xf;
        $otp = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a time-based OTP code against the secret key
     */
    public static function verifyCode(string $secret, string $code, int $discrepancy = 1): bool
    {
        $code = str_replace(' ', '', $code);
        if (strlen($code) !== 6 || !is_numeric($code)) {
            return false;
        }

        $currentTimeSlice = (int)floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode a base32 string
     */
    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(str_replace('=', '', $base32));
        if (!preg_match('/^[A-Z2-7]+$/', $base32)) {
            throw new \Exception('Invalid base32 characters in secret key');
        }

        $binary = '';
        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::$base32Chars, $base32[$i]);
            $binary .= sprintf('%05b', $val);
        }

        $bytes = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int)bindec($chunk));
            }
        }

        return $bytes;
    }
}
