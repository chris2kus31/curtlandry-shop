<?php

namespace App\Auth;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;

/**
 * Password hasher that transparently verifies legacy WordPress/WooCommerce
 * password hashes so migrated customers can keep their existing passwords.
 *
 * Supported stored formats:
 *   - Native bcrypt ($2y$ / $2a$ / $2b$)          -> Bagisto's own hashes
 *   - WordPress 6.8+ bcrypt ($wp$2y$)             -> SHA-384 pre-hash + bcrypt
 *   - phpass portable ($P$ / $H$)                 -> legacy WordPress/phpBB
 *   - Legacy plain MD5 (32 hex chars)             -> very old WordPress
 *
 * New hashes and rehashes are always produced with native bcrypt, so a
 * migrated customer is silently upgraded to Bagisto's format on first login
 * (see WordPressEloquentUserProvider).
 */
class WordPressHasher implements Hasher
{
    /**
     * Bcrypt hasher used for making/verifying Bagisto-native hashes.
     */
    protected BcryptHasher $bcrypt;

    /**
     * phpass base-64 alphabet (not the standard base64 alphabet).
     */
    protected string $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct()
    {
        $this->bcrypt = new BcryptHasher([
            'rounds' => config('hashing.bcrypt.rounds', 10),
        ]);
    }

    /**
     * Get information about the given hashed value.
     */
    public function info($hashedValue)
    {
        return password_get_info($hashedValue);
    }

    /**
     * Hash the given value. Always produces a native bcrypt hash.
     */
    public function make($value, array $options = [])
    {
        return $this->bcrypt->make($value, $options);
    }

    /**
     * Check the given plain value against a hash of any supported format.
     */
    public function check($value, $hashedValue, array $options = [])
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return false;
        }

        // WordPress 6.8+ bcrypt: '$wp' . password_hash(base64(hmac-sha384(pwd)))
        if (str_starts_with($hashedValue, '$wp$')) {
            $prehash = base64_encode(hash_hmac('sha384', trim((string) $value), 'wp-sha384', true));

            return password_verify($prehash, substr($hashedValue, 3));
        }

        // phpass portable hashes ($P$ = WordPress, $H$ = phpBB)
        if (str_starts_with($hashedValue, '$P$') || str_starts_with($hashedValue, '$H$')) {
            if (hash_equals($hashedValue, $this->phpassCrypt((string) $value, $hashedValue))) {
                return true;
            }

            // Fallback: WordPress hashes the trimmed password when creating hashes.
            return hash_equals($hashedValue, $this->phpassCrypt(trim((string) $value), $hashedValue));
        }

        // Legacy plain MD5 (32 hexadecimal characters, no algorithm prefix).
        if (strlen($hashedValue) === 32 && ctype_xdigit($hashedValue)) {
            return hash_equals(strtolower($hashedValue), md5((string) $value));
        }

        // Native bcrypt (or anything else PHP's password_verify understands).
        return $this->bcrypt->check($value, $hashedValue, $options);
    }

    /**
     * Determine if the hash should be re-hashed to Bagisto's native format.
     *
     * Every legacy WordPress format returns true so it is transparently
     * upgraded to native bcrypt after a successful login.
     */
    public function needsRehash($hashedValue, array $options = [])
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return true;
        }

        if (
            str_starts_with($hashedValue, '$wp$')
            || str_starts_with($hashedValue, '$P$')
            || str_starts_with($hashedValue, '$H$')
            || (strlen($hashedValue) === 32 && ctype_xdigit($hashedValue))
        ) {
            return true;
        }

        return $this->bcrypt->needsRehash($hashedValue, $options);
    }

    /**
     * Verify a plaintext password against a phpass portable hash.
     *
     * Port of Openwall's PasswordHash::crypt_private(). The iteration count is
     * read from the stored hash, so any WordPress-generated cost is honoured.
     */
    protected function phpassCrypt(string $password, string $setting): string
    {
        $output = '*0';

        if (substr($setting, 0, 3) !== '$P$' && substr($setting, 0, 3) !== '$H$') {
            return $output;
        }

        $countLog2 = strpos($this->itoa64, $setting[3]);

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return $output;
        }

        $count = 1 << $countLog2;

        $salt = substr($setting, 4, 8);

        if (strlen($salt) !== 8) {
            return $output;
        }

        $hash = md5($salt.$password, true);

        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).$this->encode64($hash, 16);
    }

    /**
     * phpass base-64 encoder (port of PasswordHash::encode64()).
     */
    protected function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = ord($input[$i++]);
            $output .= $this->itoa64[$value & 0x3f];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= $this->itoa64[($value >> 6) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= $this->itoa64[($value >> 12) & 0x3f];

            if ($i++ >= $count) {
                break;
            }

            $output .= $this->itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}
