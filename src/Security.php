<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

final class Security
{
    /**
     * @const string
     */
    public const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * Encrypt
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function encrypt(string $data, string $key): string
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(static::CIPHER_ALGO));
        $encrypted = openssl_encrypt($data, static::CIPHER_ALGO, $key, 0, $iv);

        return base64_encode($encrypted . '::' . $iv);
    }

    /**
     * Decrypt
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function decrypt(string $data, string $key): string
    {
        list($encryptedData, $iv) = explode('::', base64_decode($data), 2);

        return openssl_decrypt($encryptedData, static::CIPHER_ALGO, $key, 0, $iv);
    }
}
