<?php

namespace Sparkinzy\CapPhpServer\Interfaces;

/**
 * Storage interface for Cap challenges and tokens
 * Inspired by go-cap Storage interface design
 */
interface StorageInterface
{
    /**
     * Set challenge token with expiration
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setChallenge(string $token, int $expiresTs): bool;

    /**
     * Get challenge expiration time
     * @param string $token Challenge token
     * @param bool $delete Whether to delete after get (atomic operation)
     * @return int|null Expiration timestamp or null if not found
     */
    public function getChallenge(string $token, bool $delete = false): ?int;

    /**
     * Set verification token with expiration
     * @param string $key Token key (id:hash format)
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setToken(string $key, int $expiresTs): bool;

    /**
     * Get verification token expiration time
     * @param string $key Token key (id:hash format)
     * @param bool $delete Whether to delete after get (atomic operation)
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $key, bool $delete = false): ?int;

    /**
     * Clean up expired items
     * @return bool Success status
     */
    public function cleanup(): bool;

    /**
     * Check if storage is available/connected
     * @return bool Availability status
     */
    public function isAvailable(): bool;
}