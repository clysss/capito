<?php

namespace Sparkinzy\CapPhpServer;

use Exception;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;
use Sparkinzy\CapPhpServer\Storage\FileStorage;
use Sparkinzy\CapPhpServer\Storage\MemoryStorage;
use Sparkinzy\CapPhpServer\Exceptions\CapException;

/**
 * Cap PHP Server - A PHP implementation of Cap
 * A lightweight, modern open-source CAPTCHA alternative using SHA-256 proof-of-work
 * Enhanced with rate limiting, unified storage interface, and go-cap compatibility
 */
class Cap
{
    private array $config;
    private array $state;
    private ?StorageInterface $storage = null;
    private ?RateLimiter $rateLimiter = null;
    
    const DEFAULT_TOKENS_STORE = '.data/tokensList.json';
    
    // Challenge configuration (optimized based on memory recommendations)
    const DEFAULT_CHALLENGE_COUNT = 3;
    const DEFAULT_CHALLENGE_SIZE = 16;
    const DEFAULT_CHALLENGE_DIFFICULTY = 2;
    const DEFAULT_EXPIRES_MS = 600000;      // 10 minutes
    const DEFAULT_TOKEN_EXPIRES_MS = 1200000; // 20 minutes
    
    // Rate limiting configuration (inspired by go-cap)
    const DEFAULT_RATE_LIMIT_RPS = 10;
    const DEFAULT_RATE_LIMIT_BURST = 50;
    
    // Additional configuration
    const DEFAULT_TOKEN_VERIFY_ONCE = true;
    const DEFAULT_AUTO_CLEANUP_INTERVAL = 300; // 5 minutes

    /**
     * Create a new Cap instance
     * @param array|null $configObj Configuration options
     * 
     * Configuration options:
     * - tokensStorePath: string - Path to token storage file
     * - noFSState: bool - Disable file system state storage
     * - redis: array - Redis configuration
     * - storage: StorageInterface - Custom storage implementation
     * - challengeCount: int - Number of challenges (default: 3)
     * - challengeSize: int - Challenge size in hex chars (default: 16)
     * - challengeDifficulty: int - Challenge difficulty (default: 2)
     * - challengeExpires: int - Challenge expiration in seconds (default: 600)
     * - tokenExpires: int - Token expiration in seconds (default: 1200)
     * - tokenVerifyOnce: bool - One-time token verification (default: true)
     * - rateLimitRps: int - Rate limit requests per second (default: 10)
     * - rateLimitBurst: int - Rate limit burst capacity (default: 50)
     * - autoCleanupInterval: int - Auto cleanup interval in seconds (default: 300)
     */
    public function __construct(?array $configObj = null)
    {
        // Initialize default configuration with enhanced options
        $this->config = [
            // Legacy options for backward compatibility
            'tokensStorePath' => self::DEFAULT_TOKENS_STORE,
            'noFSState' => false,
            'redis' => null,
            'state' => [
                'challengesList' => [],
                'tokensList' => []
            ],
            
            // Enhanced configuration options inspired by go-cap
            'storage' => null,
            'challengeCount' => self::DEFAULT_CHALLENGE_COUNT,
            'challengeSize' => self::DEFAULT_CHALLENGE_SIZE,
            'challengeDifficulty' => self::DEFAULT_CHALLENGE_DIFFICULTY,
            'challengeExpires' => self::DEFAULT_EXPIRES_MS / 1000, // Convert to seconds
            'tokenExpires' => self::DEFAULT_TOKEN_EXPIRES_MS / 1000, // Convert to seconds
            'tokenVerifyOnce' => self::DEFAULT_TOKEN_VERIFY_ONCE,
            'rateLimitRps' => self::DEFAULT_RATE_LIMIT_RPS,
            'rateLimitBurst' => self::DEFAULT_RATE_LIMIT_BURST,
            'autoCleanupInterval' => self::DEFAULT_AUTO_CLEANUP_INTERVAL
        ];

        // Apply user configuration
        if ($configObj !== null) {
            foreach ($configObj as $key => $value) {
                if (array_key_exists($key, $this->config)) {
                    $this->config[$key] = $value;
                }
            }
        }

        $this->state = $this->config['state'];

        // Initialize storage
        $this->initializeStorage();
        
        // Load state from persistent storage if available
        $this->loadStateFromPersistentStorage();
        
        // Initialize rate limiter
        $this->initializeRateLimiter();
    }

    /**
     * Initialize storage based on configuration
     * Priority: custom storage > Redis > file storage > memory storage
     */
    private function initializeStorage(): void
    {
        try {
            // Custom storage implementation
            if ($this->config['storage'] instanceof StorageInterface) {
                $this->storage = $this->config['storage'];
                return;
            }
            
            // Redis storage
            if ($this->config['redis'] !== null) {
                $redisStorage = new Storage\RedisStorage($this->config['redis']);
                if ($redisStorage->isAvailable()) {
                    $this->storage = $redisStorage;
                    $this->loadStateFromLegacyStorage();
                    return;
                } else {
                    error_log("Warning: Redis connection failed, falling back to file storage");
                }
            }
            
            // File storage (default)
            if (!$this->config['noFSState']) {
                $this->storage = new FileStorage($this->config['tokensStorePath']);
                $this->loadStateFromLegacyStorage();
                return;
            }
            
            // Memory storage (fallback)
            $this->storage = new MemoryStorage($this->config['autoCleanupInterval']);
            
        } catch (Exception $e) {
            error_log("Warning: Storage initialization failed: " . $e->getMessage() . ", using memory storage");
            $this->storage = new MemoryStorage($this->config['autoCleanupInterval']);
        }
    }

    /**
     * Initialize rate limiter
     */
    private function initializeRateLimiter(): void
    {
        if ($this->config['rateLimitRps'] > 0 && $this->config['rateLimitBurst'] > 0) {
            $this->rateLimiter = new RateLimiter(
                $this->config['rateLimitRps'],
                $this->config['rateLimitBurst']
            );
        }
    }

    /**
     * Load state from legacy storage format for migration
     */
    private function loadStateFromLegacyStorage(): void
    {
        // If using Redis storage, load the legacy format
        if ($this->storage instanceof Storage\RedisStorage) {
            $legacyState = $this->storage->loadState();
            $this->migrateStateToNewFormat($legacyState);
        }
        // For file storage, the FileStorage class handles legacy format automatically
    }

    /**
     * Migrate legacy state format to new storage interface
     */
    private function migrateStateToNewFormat(array $legacyState): void
    {
        $now = time();
        
        // Migrate challenges
        foreach ($legacyState['challengesList'] ?? [] as $token => $data) {
            if (isset($data['expires']) && $data['expires'] / 1000 > $now) {
                $this->storage->setChallenge($token, (int)($data['expires'] / 1000));
            }
        }
        
        // Migrate tokens
        foreach ($legacyState['tokensList'] ?? [] as $key => $expires) {
            if ($expires / 1000 > $now) {
                $this->storage->setToken($key, (int)($expires / 1000));
            }
        }
    }

    /**
     * Check rate limit for the given identifier
     * @param string $identifier Rate limit identifier (e.g., IP address)
     * @return bool Whether request is allowed
     * @throws CapException If rate limited
     */
    private function checkRateLimit(string $identifier): bool
    {
        if ($this->rateLimiter === null) {
            return true; // Rate limiting disabled
        }
        
        if (!$this->rateLimiter->allow($identifier)) {
            throw CapException::rateLimited("Rate limit exceeded for: {$identifier}");
        }
        
        return true;
    }

    /**
     * Create a new challenge
     * @param array|null $conf Challenge configuration (optional override)
     * @param string|null $identifier Rate limit identifier (e.g., IP address)
     * @return array Challenge response
     * @throws CapException
     */
    public function createChallenge(?array $conf = null, ?string $identifier = null): array
    {
        // Apply rate limiting
        if ($identifier !== null) {
            $this->checkRateLimit($identifier);
        }
        
        // Perform cleanup
        $this->storage->cleanup();

        // Use configuration values with optional overrides
        $challengeCount = $conf['challengeCount'] ?? $this->config['challengeCount'];
        $challengeSize = $conf['challengeSize'] ?? $this->config['challengeSize'];
        $challengeDifficulty = $conf['challengeDifficulty'] ?? $this->config['challengeDifficulty'];
        $expiresSeconds = $conf['challengeExpires'] ?? $this->config['challengeExpires'];
        $store = $conf['store'] ?? true;

        // Validate parameters
        if ($challengeCount <= 0 || $challengeSize <= 0 || $challengeDifficulty <= 0 || $expiresSeconds <= 0) {
            throw CapException::invalidChallenge('Invalid challenge parameters');
        }

        // Generate challenges
        $challenges = [];
        for ($i = 0; $i < $challengeCount; $i++) {
            $salt = $this->generateRandomHex($challengeSize);
            $target = $this->generateRandomHex($challengeDifficulty);
            $challenges[] = [$salt, $target];
        }

        $token = $this->generateRandomHex(50);
        $expiresTs = time() + $expiresSeconds;
        $expiresMs = $expiresTs * 1000; // For API compatibility

        if (!$store) {
            return [
                'challenge' => $challenges,
                'expires' => $expiresMs
            ];
        }

        // Store challenge using new storage interface
        if (!$this->storage->setChallenge($token, $expiresTs)) {
            throw CapException::storageError('Failed to store challenge');
        }
        
        // Store challenge data in legacy format for compatibility
        $this->state['challengesList'][$token] = [
            'challenge' => $challenges,
            'expires' => $expiresMs,
            'token' => $token
        ];
        
        // Save state to persistent storage if supported
        $this->saveStateToPersistentStorage();

        return [
            'challenge' => $challenges,
            'token' => $token,
            'expires' => $expiresMs
        ];
    }

    /**
     * Redeem a challenge solution
     * @param array $solution Solution data
     * @param string|null $identifier Rate limit identifier (e.g., IP address)
     * @return array Redeem response
     * @throws CapException
     */
    public function redeemChallenge(array $solution, ?string $identifier = null): array
    {
        // Apply rate limiting
        if ($identifier !== null) {
            $this->checkRateLimit($identifier);
        }
        
        // Validate input
        if (!isset($solution['token']) || $solution['token'] === '' || !isset($solution['solutions'])) {
            throw CapException::invalidChallenge('Invalid solution body: missing token or solutions');
        }

        $token = $solution['token'];
        
        // Get challenge data using new storage interface
        $expiresTs = $this->storage->getChallenge($token, true); // Delete after get
        
        if ($expiresTs === null) {
            throw CapException::challengeExpired('Challenge not found or already used');
        }
        
        if ($expiresTs < time()) {
            throw CapException::challengeExpired('Challenge expired');
        }
        
        // Get challenge data from legacy state for validation
        if (!isset($this->state['challengesList'][$token])) {
            // Try to load state from persistent storage
            $this->loadStateFromPersistentStorage();
            
            if (!isset($this->state['challengesList'][$token])) {
                throw CapException::invalidChallenge('Challenge data not found in state');
            }
        }
        
        $challengeData = $this->state['challengesList'][$token];
        unset($this->state['challengesList'][$token]);
        
        // Save updated state back to persistent storage
        $this->saveStateToPersistentStorage();

        // Validate solutions
        $this->validateSolutions($solution['solutions'], $challengeData['challenge'], $token);

        // Generate verification token
        $vertoken = $this->generateRandomHex(30);
        $tokenExpiresTs = time() + $this->config['tokenExpires'];
        $hash = hash('sha256', $vertoken);
        $id = $this->generateRandomHex(16);
        $key = $id . ':' . $hash;

        // Store verification token using new storage interface
        if (!$this->storage->setToken($key, $tokenExpiresTs)) {
            throw CapException::storageError('Failed to store verification token');
        }
        
        // Store in legacy format for compatibility
        $this->state['tokensList'][$key] = $tokenExpiresTs * 1000; // Convert to milliseconds for compatibility

        return [
            'success' => true,
            'token' => $id . ':' . $vertoken,
            'expires' => $tokenExpiresTs * 1000 // Convert to milliseconds for API compatibility
        ];
    }

    /**
     * Validate solutions against challenges
     * @param array $solutions Solutions to validate
     * @param array $challenges Challenge data
     * @param string $token Token for debug logging
     * @throws CapException If solutions are invalid
     */
    private function validateSolutions(array $solutions, array $challenges, string $token): void
    {
        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'token' => $token,
            'received_solutions' => $solutions,
            'challenge_data' => $challenges,
            'validation_steps' => []
        ];
        
        foreach ($challenges as $challengeIndex => $challenge) {
            list($salt, $target) = $challenge;
            $found = false;
            $challengeDebug = [
                'challenge_index' => $challengeIndex,
                'expected_salt' => $salt,
                'expected_target' => $target,
                'attempts' => []
            ];

            foreach ($solutions as $solIndex => $sol) {
                $attemptDebug = [
                    'solution_index' => $solIndex,
                    'raw_solution' => $sol,
                    'is_array' => is_array($sol),
                    'array_length' => is_array($sol) ? count($sol) : 'N/A'
                ];
                
                // Handle cap.js 0.1.25 number array format [1, 27, 7]
                if (!is_array($sol) && is_numeric($sol)) {
                    // For cap.js 0.1.25: solutions[challengeIndex] = solution_value
                    if ($solIndex === $challengeIndex) {
                        $solValue = $sol;
                        $hashInput = $salt . $solValue;
                        $hash = hash('sha256', $hashInput);
                        $isMatch = strpos($hash, $target) === 0;
                        
                        $attemptDebug['format'] = 'capjs_0.1.25_number';
                        $attemptDebug['solution_value_original'] = $solValue;
                        $attemptDebug['solution_value_string'] = (string)$solValue;
                        $attemptDebug['solution_value_type'] = gettype($solValue);
                        $attemptDebug['hash_input'] = $hashInput;
                        $attemptDebug['calculated_hash'] = $hash;
                        $attemptDebug['target_to_match'] = $target;
                        $attemptDebug['hash_starts_with_target'] = $isMatch;
                        $attemptDebug['strpos_result'] = strpos($hash, $target);
                        
                        $challengeDebug['attempts'][] = $attemptDebug;
                        
                        if ($isMatch) {
                            $found = true;
                            $challengeDebug['found_valid_solution'] = true;
                            break;
                        }
                    } else {
                        $attemptDebug['skip_reason'] = 'index_mismatch_capjs_format';
                        $challengeDebug['attempts'][] = $attemptDebug;
                    }
                    continue;
                }
                
                // Skip if not an array and not a number
                if (!is_array($sol)) {
                    $attemptDebug['skip_reason'] = 'not_array_or_number';
                    $challengeDebug['attempts'][] = $attemptDebug;
                    continue;
                }
                
                // Support both old format [salt, solution] and new format [salt, target, solution]
                if (count($sol) === 2) {
                    // Old format: [salt, solution] - only match salt, target is derived from current challenge
                    list($solSalt, $solValue) = $sol;
                    if ($solSalt !== $salt) {
                        $attemptDebug['skip_reason'] = 'salt_mismatch_old_format';
                        $attemptDebug['received_salt'] = $solSalt;
                        $challengeDebug['attempts'][] = $attemptDebug;
                        continue;
                    }
                    $attemptDebug['format'] = 'old_2_element';
                } elseif (count($sol) === 3) {
                    // New format: [salt, target, solution]
                    list($solSalt, $solTarget, $solValue) = $sol;
                    if ($solSalt !== $salt || $solTarget !== $target) {
                        $attemptDebug['skip_reason'] = 'salt_or_target_mismatch_new_format';
                        $attemptDebug['received_salt'] = $solSalt;
                        $attemptDebug['received_target'] = $solTarget;
                        $challengeDebug['attempts'][] = $attemptDebug;
                        continue;
                    }
                    $attemptDebug['format'] = 'new_3_element';
                } else {
                    $attemptDebug['skip_reason'] = 'invalid_array_length';
                    $challengeDebug['attempts'][] = $attemptDebug;
                    continue;
                }

                // Convert solution value to string
                $solStr = (string)$solValue;
                $attemptDebug['solution_value_original'] = $solValue;
                $attemptDebug['solution_value_string'] = $solStr;
                $attemptDebug['solution_value_type'] = gettype($solValue);

                // Verify the solution
                $hashInput = $salt . $solStr;
                $hash = hash('sha256', $hashInput);
                $isMatch = strpos($hash, $target) === 0;
                
                $attemptDebug['hash_input'] = $hashInput;
                $attemptDebug['calculated_hash'] = $hash;
                $attemptDebug['target_to_match'] = $target;
                $attemptDebug['hash_starts_with_target'] = $isMatch;
                $attemptDebug['strpos_result'] = strpos($hash, $target);
                
                $challengeDebug['attempts'][] = $attemptDebug;
                
                if ($isMatch) {
                    $found = true;
                    $challengeDebug['found_valid_solution'] = true;
                    break;
                }
            }
            
            $challengeDebug['challenge_solved'] = $found;
            $debugInfo['validation_steps'][] = $challengeDebug;

            if (!$found) {
                // Log detailed debug info before throwing exception
                $logMessage = "CAPJS COMPATIBILITY DEBUG - Invalid Solution:\n" . 
                             json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . 
                             str_repeat('=', 120) . "\n";
                error_log($logMessage);
                file_put_contents(__DIR__ . '/../debug_capjs_detailed.log', $logMessage, FILE_APPEND);
                
                throw CapException::invalidSolutions('Invalid solution for challenge ' . $challengeIndex);
            }
        }
    }

    /**
     * Validate a verification token
     * @param string $token Token to validate
     * @param array|null $conf Token configuration
     * @param string|null $identifier Rate limit identifier (e.g., IP address)
     * @return array Validation response
     * @throws CapException
     */
    public function validateToken(string $token, ?array $conf = null, ?string $identifier = null): array
    {
        // Apply rate limiting
        if ($identifier !== null) {
            $this->checkRateLimit($identifier);
        }
        
        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return ['success' => false, 'message' => 'Invalid token format'];
        }

        list($id, $vertoken) = $parts;
        $hash = hash('sha256', $vertoken);
        $key = $id . ':' . $hash;

        // Determine if token should be kept after validation
        $keepToken = $conf['keepToken'] ?? !$this->config['tokenVerifyOnce'];
        
        // Get token using new storage interface
        $expiresTs = $this->storage->getToken($key, !$keepToken); // Delete if not keeping
        
        if ($expiresTs === null) {
            return ['success' => false, 'message' => 'Token not found'];
        }
        
        if ($expiresTs < time()) {
            return ['success' => false, 'message' => 'Token expired'];
        }
        
        // Update legacy state for compatibility
        if ($keepToken) {
            $this->state['tokensList'][$key] = $expiresTs * 1000; // Convert to milliseconds
        } else {
            unset($this->state['tokensList'][$key]);
        }

        return ['success' => true];
    }

    /**
     * Clean up expired tokens and challenges
     * @return bool Whether cleanup was successful
     */
    public function cleanup(): bool
    {
        try {
            return $this->storage->cleanup();
        } catch (Exception $e) {
            error_log("Warning: cleanup failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current configuration
     * @return array Current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get storage statistics
     * @return array Storage statistics
     */
    public function getStats(): array
    {
        $stats = [
            'storage_type' => get_class($this->storage),
            'rate_limiter_enabled' => $this->rateLimiter !== null,
            'config' => [
                'challengeCount' => $this->config['challengeCount'],
                'challengeSize' => $this->config['challengeSize'],
                'challengeDifficulty' => $this->config['challengeDifficulty'],
                'challengeExpires' => $this->config['challengeExpires'],
                'tokenExpires' => $this->config['tokenExpires'],
                'tokenVerifyOnce' => $this->config['tokenVerifyOnce'],
                'rateLimitRps' => $this->config['rateLimitRps'],
                'rateLimitBurst' => $this->config['rateLimitBurst']
            ]
        ];
        
        // Add storage-specific stats if available
        if (method_exists($this->storage, 'getStats')) {
            $stats['storage_stats'] = $this->storage->getStats();
        }
        
        // Add rate limiter stats if available
        if ($this->rateLimiter !== null) {
            $stats['rate_limiter_stats'] = $this->rateLimiter->getLimits();
        }
        
        return $stats;
    }

    /**
     * Get storage instance for advanced usage
     * @return StorageInterface Storage instance
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get rate limiter instance for advanced usage
     * @return RateLimiter|null Rate limiter instance or null if disabled
     */
    public function getRateLimiter(): ?RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Save state to persistent storage if supported
     */
    private function saveStateToPersistentStorage(): void
    {
        if (method_exists($this->storage, 'saveState')) {
            $this->storage->saveState($this->state);
        }
    }

    /**
     * Load state from persistent storage if supported
     */
    private function loadStateFromPersistentStorage(): void
    {
        if (method_exists($this->storage, 'loadState')) {
            $loadedState = $this->storage->loadState();
            // Merge loaded state with current state
            $this->state['challengesList'] = array_merge(
                $this->state['challengesList'] ?? [],
                $loadedState['challengesList'] ?? []
            );
            $this->state['tokensList'] = array_merge(
                $this->state['tokensList'] ?? [],
                $loadedState['tokensList'] ?? []
            );
        }
    }

    // Legacy methods for backward compatibility (marked as deprecated)

    /**
     * Load tokens from storage file (legacy method - deprecated)
     * @deprecated Use the new storage interface instead
     */
    private function loadTokens(): void
    {
        // This method is now handled by the storage initialization
        // Kept for backward compatibility but does nothing
        error_log("Warning: loadTokens() is deprecated, use the new storage interface");
    }

    /**
     * Save tokens to storage (legacy method - deprecated)
     * @deprecated Use the new storage interface instead
     * @throws Exception
     */
    private function saveTokens(): void
    {
        // This method is now handled by the storage interface
        // Kept for backward compatibility but does nothing
        error_log("Warning: saveTokens() is deprecated, use the new storage interface");
    }

    /**
     * Clean expired tokens and challenges (legacy method - deprecated)
     * @deprecated Use the new storage cleanup() method instead
     * @return bool Whether tokens were changed
     */
    private function cleanExpiredTokens(): bool
    {
        // Delegate to the new storage interface
        return $this->storage->cleanup();
    }

    /**
     * Generate random hex string
     * @param int $length Length of hex string
     * @return string Random hex string
     * @throws CapException If generation fails
     */
    private function generateRandomHex(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        try {
            $bytes = random_bytes((int)ceil($length / 2));
            $hex = bin2hex($bytes);
            return substr($hex, 0, $length);
        } catch (Exception $e) {
            throw CapException::generateFailed('Failed to generate random hex: ' . $e->getMessage());
        }
    }
}