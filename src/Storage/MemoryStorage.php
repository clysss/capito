<?php

namespace Sparkinzy\CapPhpServer\Storage;

use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * Memory storage implementation
 * Inspired by go-cap MemoryStorage design
 */
class MemoryStorage implements StorageInterface
{
    private array $challenges = [];
    private array $tokens = [];
    private int $lastCleanup;
    private int $cleanupInterval;

    /**
     * Create a new memory storage instance
     * @param int $cleanupInterval Cleanup interval in seconds (default: 5 minutes)
     */
    public function __construct(int $cleanupInterval = 300)
    {
        $this->cleanupInterval = $cleanupInterval;
        $this->lastCleanup = time();
    }

    /**
     * Set challenge token with expiration
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setChallenge(string $token, int $expiresTs): bool
    {
        $this->challenges[$token] = $expiresTs;
        $this->maybeCleanup();
        return true;
    }

    /**
     * Get challenge expiration time
     * @param string $token Challenge token
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getChallenge(string $token, bool $delete = false): ?int
    {
        if (!isset($this->challenges[$token])) {
            return null;
        }

        $expiresTs = $this->challenges[$token];
        
        if ($delete) {
            unset($this->challenges[$token]);
        }

        return $expiresTs;
    }

    /**
     * Set verification token with expiration
     * @param string $key Token key (id:hash format)
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setToken(string $key, int $expiresTs): bool
    {
        $this->tokens[$key] = $expiresTs;
        $this->maybeCleanup();
        return true;
    }

    /**
     * Get verification token expiration time
     * @param string $key Token key (id:hash format)
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $key, bool $delete = false): ?int
    {
        if (!isset($this->tokens[$key])) {
            return null;
        }

        $expiresTs = $this->tokens[$key];
        
        if ($delete) {
            unset($this->tokens[$key]);
        }

        return $expiresTs;
    }

    /**
     * Clean up expired items
     * @return bool Success status
     */
    public function cleanup(): bool
    {
        $now = time();
        
        // Clean expired challenges
        foreach ($this->challenges as $token => $expiresTs) {
            if ($expiresTs < $now) {
                unset($this->challenges[$token]);
            }
        }

        // Clean expired tokens
        foreach ($this->tokens as $key => $expiresTs) {
            if ($expiresTs < $now) {
                unset($this->tokens[$key]);
            }
        }

        $this->lastCleanup = $now;
        return true;
    }

    /**
     * Check if storage is available
     * @return bool Always true for memory storage
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get current storage statistics
     * @return array Storage statistics
     */
    public function getStats(): array
    {
        return [
            'challenges_count' => count($this->challenges),
            'tokens_count' => count($this->tokens),
            'last_cleanup' => $this->lastCleanup,
            'cleanup_interval' => $this->cleanupInterval
        ];
    }

    /**
     * Clear all data (useful for testing)
     */
    public function clear(): void
    {
        $this->challenges = [];
        $this->tokens = [];
    }

    /**
     * Perform cleanup if interval has passed
     */
    private function maybeCleanup(): void
    {
        if (time() - $this->lastCleanup >= $this->cleanupInterval) {
            $this->cleanup();
        }
    }
}