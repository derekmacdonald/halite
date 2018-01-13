<?php
declare(strict_types=1);
namespace ParagonIE\Halite;

use ParagonIE\ConstantTime\{
    Base64UrlSafe,
    Hex
};
use ParagonIE\Halite\Alerts\{
    CannotPerformOperation,
    InvalidDigestLength,
    InvalidMessage,
    InvalidSignature,
    InvalidType
};
use ParagonIE\Halite\Symmetric\{
    Config as SymmetricConfig,
    Crypto,
    EncryptionKey
};

/**
 * Class Cookie
 *
 * Secure encrypted cookies
 *
 * This library makes heavy use of return-type declarations,
 * which are a PHP 7 only feature. Read more about them here:
 *
 * @ref http://php.net/manual/en/functions.returning-values.php#functions.returning-values.type-declaration
 *
 * @package ParagonIE\Halite
 *
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */
final class Cookie 
{
    /**
     * @var EncryptionKey
     */
    protected $key;

    /**
     * Cookie constructor.
     * @param EncryptionKey $key
     */
    public function __construct(EncryptionKey $key)
    {
        $this->key = $key;
    }
    /**
     * Hide this from var_dump(), etc.
     * 
     * @return array
     */
    public function __debugInfo()
    {
        return [
            'key' => 'private'
        ];
    }

    /**
     * Store a value in an encrypted cookie
     *
     * @param string $name
     * @return mixed (typically an array)
     *
     * @param string $name
     * @return mixed|null
     * @throws InvalidDigestLength
     * @throws InvalidSignature
     * @throws CannotPerformOperation
     * @throws InvalidType
     */
    public function fetch(string $name)
    {
        if (!isset($_COOKIE[$name])) {
            return null;
        }
        try {
            /** @var string $stored */
            $stored = $_COOKIE[$name];
            if (!\is_string($stored)) {
                throw new InvalidType('Cookie value is not a string');
            }
            $config = self::getConfig((string) $stored);
            $decrypted = Crypto::decrypt(
                $stored,
                $this->key,
                $config->ENCODING
            );
            return \json_decode($decrypted->getString(), true);
        } catch (InvalidMessage $e) {
            return null;
        }
    }

    /**
     * Get the configuration for this version of halite
     *
     * @param string $stored   A stored password hash
     * @return SymmetricConfig
     *
     * @throws CannotPerformOperation
     * @throws InvalidMessage
     * @throws InvalidType
     */
    protected static function getConfig(string $stored): SymmetricConfig
    {
        $length = Util::safeStrlen($stored);
        // This doesn't even have a header.
        if ($length < 8) {
            throw new InvalidMessage(
                'Encrypted password hash is way too short.'
            );
        }
        if (\hash_equals(Util::safeSubstr($stored, 0, 5), Halite::VERSION_PREFIX)) {
            /** @var string $decoded */
            $decoded = Base64UrlSafe::decode($stored);
            if (!\is_string($decoded)) {
                \sodium_memzero($stored);
                throw new InvalidMessage('Incorrect encoding');
            }
            return SymmetricConfig::getConfig(
                $decoded,
                'encrypt'
            );
        }
        $v = Hex::decode(Util::safeSubstr($stored, 0, 8));
        return SymmetricConfig::getConfig($v, 'encrypt');
    }

    /**
     * Store a value in an encrypted cookie
     *
     * @param string $name
     * @param mixed $value
     * @param int $expire    (defaults to 0)
     * @param string $path   (defaults to '/')
     * @param string $domain (defaults to NULL)
     * @param bool $secure   (defaults to TRUE)
     * @param bool $httpOnly (defaults to TRUE)
     * @return bool
     *
     * @throws InvalidDigestLength
     * @throws CannotPerformOperation
     * @throws InvalidMessage
     * @throws InvalidType
     */
    public function store(
        string $name,
        $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true
    ): bool {
        return \setcookie(
            $name,
            Crypto::encrypt(
                new HiddenString(
                    (string) \json_encode($value)
                ),
                $this->key
            ),
            $expire,
            $path,
            $domain,
            $secure,
            $httpOnly
        );
    }
}
