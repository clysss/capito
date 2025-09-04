<?php

namespace Sparkinzy\CapPhpServer;

/**
 * Rate limiter implementation using token bucket algorithm
 * Inspired by go-cap rate limiter design
 */
class RateLimiter
{
    private array $buckets = [];
    private int $rps;
    private int $burst;

    /**
     * Create a new rate limiter
     * @param int $rps Requests per second
     * @param int $burst Maximum burst capacity
     */
    public function __construct(int $rps = 10, int $burst = 50)
    {
        $this->rps = $rps;
        $this->burst = $burst;
    }

    /**
     * Check if request is allowed for the given key
     * @param string $key Identifier for rate limiting (e.g., IP address)
     * @param int|null $limit Custom limit for this request (optional)
     * @param int|null $window Custom window for this request (optional)
     * @return bool Whether request is allowed
     */
    public function allow(string $key, ?int $limit = null, ?int $window = null): bool
    {
        $limit = $limit ?? $this->rps;
        $window = $window ?? 1; // 1 second window
        
        if ($limit <= 0 || $this->burst <= 0) {
            return true; // Rate limiting disabled
        }

        $now = microtime(true);
        
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tokens' => $this->burst,
                'last_refill' => $now
            ];
        }

        $bucket = &$this->buckets[$key];
        
        // Calculate tokens to add based on time elapsed
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $limit / $window;
        
        // Refill tokens
        $bucket['tokens'] = min($this->burst, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        // Check if we can consume a token
        if ($bucket['tokens'] >= 1) {
            $bucket['tokens']--;
            return true;
        }

        return false;
    }

    /**
     * Reset rate limit for a specific key
     * @param string $key Identifier to reset
     */
    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    /**
     * Clean up old bucket entries
     * @param int $maxAge Maximum age in seconds (default: 1 hour)
     */
    public function cleanup(int $maxAge = 3600): void
    {
        $now = microtime(true);
        
        foreach ($this->buckets as $key => $bucket) {
            if ($now - $bucket['last_refill'] > $maxAge) {
                unset($this->buckets[$key]);
            }
        }
    }

    /**
     * Get current token count for a key
     * @param string $key Identifier
     * @return float Current token count
     */
    public function getTokens(string $key): float
    {
        if (!isset($this->buckets[$key])) {
            return $this->burst;
        }

        $now = microtime(true);
        $bucket = $this->buckets[$key];
        
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $this->rps;
        
        return min($this->burst, $bucket['tokens'] + $tokensToAdd);
    }

    /**
     * Set rate limit parameters
     * @param int $rps Requests per second
     * @param int $burst Maximum burst capacity
     */
    public function setLimits(int $rps, int $burst): void
    {
        $this->rps = $rps;
        $this->burst = $burst;
    }

    /**
     * Get current rate limit settings
     * @return array ['rps' => int, 'burst' => int]
     */
    public function getLimits(): array
    {
        return [
            'rps' => $this->rps,
            'burst' => $this->burst
        ];
    }
}