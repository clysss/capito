<?php

namespace Capito\CapPhpServer\Interfaces;

/**
 * Interface for the storage layer of the CAP system.
 *
 * All storage adapters must implement this interface to be compatible.
 */
interface StorageInterface
{
    /**
     * Set a new challenge token in storage.
     *
     * @param string $token The unique challenge token to store.
     * @param int $expiresTs The expiration timestamp for the challenge.
     * @param array $data Additional data to associate with the challenge.
     * @return bool True on success, false on failure.
     */
    public function setChallenge(string $token, int $expiresTs, array $data): bool;

    /**
     * Retrieve a challenge token from storage.
     *
     * @param string $token The challenge token to retrieve.
     * @return array|null The associated data array if the challenge exists and is valid, otherwise null.
     */
    public function getChallenge(string $token): ?array;

    /**
     * Sets a new verification token, typically after a challenge is solved.
     *
     * @param string $token The new token to store.
     * @param int $expiresTs The expiration timestamp for the new token.
     * @param string $challengeToken The (old) challenge token to delete (update).
     * @return bool True on success, false on failure.
     */
    public function setToken(string $token, int $expiresTs, string $challengeToken): bool;

    /**
     * Retrieve a verification token from storage.
     *
     * @param string $token The verification token to retrieve.
     * @param bool $delete If true, the token will be deleted upon retrieval.
     * @param bool $cleanup If true, expired tokens will be cleaned up.
     * @return int|null The expiration timestamp if the token is valid, otherwise null.
     */
    public function getToken(string $token, bool $delete = false, bool $cleanup = false): ?int;

    /**
     * Remove all expired tokens from storage.
     *
     * @return bool True on success, false on failure.
     */
    public function cleanup(): bool;

    /**
     * Check if the storage is available and ready for use.
     *
     * @return bool True if available, false otherwise.
     */
    public function isAvailable(): bool;
}