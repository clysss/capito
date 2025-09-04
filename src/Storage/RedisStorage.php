<?php

namespace Sparkinzy\CapPhpServer\Storage;

use Exception;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * Redis Storage Adapter for Cap Server
 * Provides Redis-based persistence for tokens and challenges
 * Implements StorageInterface for unified storage access
 */
class RedisStorage implements StorageInterface
{
    private $redis = null;
    private $prefix;
    private $connected = false;
    
    const DEFAULT_PREFIX = 'cap:';
    const CHALLENGES_KEY = 'challenges';
    const TOKENS_KEY = 'tokens';

    /**
     * Create a new RedisStorage instance
     * @param array $config Redis configuration
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->redis = new \Redis();
        $this->prefix = $config['prefix'] ?? self::DEFAULT_PREFIX;
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        $timeout = $config['timeout'] ?? 2.0;
        
        try {
            $this->connected = $this->redis->connect($host, $port, $timeout);
            
            if (!$this->connected) {
                throw new Exception("Failed to connect to Redis server");
            }
            
            if ($password !== null) {
                if (!$this->redis->auth($password)) {
                    throw new Exception("Redis authentication failed");
                }
            }
            
            if ($database !== 0) {
                if (!$this->redis->select($database)) {
                    throw new Exception("Failed to select Redis database");
                }
            }
            
            // Test connection
            if (!$this->redis->ping()) {
                throw new Exception("Redis connection test failed");
            }
            
        } catch (Exception $e) {
            throw new Exception("Redis connection error: " . $e->getMessage());
        }
    }

    /**
     * Set challenge token with expiration
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setChallenge(string $token, int $expiresTs): bool
    {
        try {
            $data = [
                'expires' => $expiresTs * 1000, // Convert to milliseconds for consistency
                'token' => $token
            ];
            return $this->redis->hSet($this->getKey(self::CHALLENGES_KEY), $token, json_encode($data)) !== false;
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to set challenge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get challenge expiration time
     * @param string $token Challenge token
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getChallenge(string $token, bool $delete = false): ?int
    {
        try {
            $challengesKey = $this->getKey(self::CHALLENGES_KEY);
            $jsonData = $this->redis->hGet($challengesKey, $token);
            
            if ($jsonData === false) {
                return null;
            }
            
            $data = json_decode($jsonData, true);
            if ($data === null || !isset($data['expires'])) {
                return null;
            }
            
            if ($delete) {
                $this->redis->hDel($challengesKey, $token);
            }
            
            return (int)($data['expires'] / 1000); // Convert back to seconds
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to get challenge: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set verification token with expiration
     * @param string $key Token key (id:hash format)
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setToken(string $key, int $expiresTs): bool
    {
        try {
            return $this->redis->hSet($this->getKey(self::TOKENS_KEY), $key, (string)($expiresTs * 1000)) !== false;
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to set token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get verification token expiration time
     * @param string $key Token key (id:hash format)
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $key, bool $delete = false): ?int
    {
        try {
            $tokensKey = $this->getKey(self::TOKENS_KEY);
            $expiresStr = $this->redis->hGet($tokensKey, $key);
            
            if ($expiresStr === false) {
                return null;
            }
            
            if ($delete) {
                $this->redis->hDel($tokensKey, $key);
            }
            
            return (int)($expiresStr / 1000); // Convert back to seconds
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to get token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up expired items
     * @return bool Success status
     */
    public function cleanup(): bool
    {
        return $this->cleanExpired();
    }

    /**
     * Check if storage is available
     * @return bool Availability status
     */
    public function isAvailable(): bool
    {
        return $this->isConnected();
    }

    // Legacy methods for backward compatibility

    /**
     * Load tokens and challenges from Redis
     * @return array State data
     */
    public function loadState(): array
    {
        try {
            $challengesData = $this->redis->hGetAll($this->getKey(self::CHALLENGES_KEY));
            $tokensData = $this->redis->hGetAll($this->getKey(self::TOKENS_KEY));
            
            $state = [
                'challengesList' => [],
                'tokensList' => []
            ];
            
            // Parse challenges
            foreach ($challengesData as $token => $jsonData) {
                $data = json_decode($jsonData, true);
                if ($data !== null) {
                    $state['challengesList'][$token] = $data;
                }
            }
            
            // Parse tokens
            foreach ($tokensData as $key => $expires) {
                $state['tokensList'][$key] = (int)$expires;
            }
            
            return $state;
            
        } catch (Exception $e) {
            error_log("Warning: Failed to load state from Redis: " . $e->getMessage());
            return [
                'challengesList' => [],
                'tokensList' => []
            ];
        }
    }

    /**
     * Save tokens and challenges to Redis
     * @param array $state State data to save
     * @return bool Success status
     */
    public function saveState(array $state): bool
    {
        try {
            $pipe = $this->redis->multi(\Redis::PIPELINE);
            
            // Save challenges
            $challengesKey = $this->getKey(self::CHALLENGES_KEY);
            $pipe->del($challengesKey);
            foreach ($state['challengesList'] as $token => $data) {
                $pipe->hSet($challengesKey, $token, json_encode($data));
            }
            
            // Save tokens
            $tokensKey = $this->getKey(self::TOKENS_KEY);
            $pipe->del($tokensKey);
            foreach ($state['tokensList'] as $key => $expires) {
                $pipe->hSet($tokensKey, $key, (string)$expires);
            }
            
            $pipe->exec();
            return true;
            
        } catch (Exception $e) {
            error_log("Warning: Failed to save state to Redis: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean expired tokens and challenges from Redis
     * @return bool Whether any items were cleaned
     */
    public function cleanExpired(): bool
    {
        try {
            $now = (int)(microtime(true) * 1000);
            $cleaned = false;
            
            // Clean expired challenges
            $challengesKey = $this->getKey(self::CHALLENGES_KEY);
            $challenges = $this->redis->hGetAll($challengesKey);
            
            foreach ($challenges as $token => $jsonData) {
                $data = json_decode($jsonData, true);
                if ($data !== null && isset($data['expires']) && $data['expires'] < $now) {
                    $this->redis->hDel($challengesKey, $token);
                    $cleaned = true;
                }
            }
            
            // Clean expired tokens
            $tokensKey = $this->getKey(self::TOKENS_KEY);
            $tokens = $this->redis->hGetAll($tokensKey);
            
            foreach ($tokens as $key => $expires) {
                if ((int)$expires < $now) {
                    $this->redis->hDel($tokensKey, $key);
                    $cleaned = true;
                }
            }
            
            return $cleaned;
            
        } catch (Exception $e) {
            error_log("Warning: Failed to clean expired items from Redis: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if Redis connection is active
     * @return bool Connection status
     */
    public function isConnected(): bool
    {
        return $this->redis !== null && method_exists($this->redis, 'isConnected') && $this->redis->isConnected();
    }

    /**
     * Close Redis connection
     */
    public function close(): void
    {
        if ($this->connected) {
            $this->redis->close();
            $this->connected = false;
        }
    }

    /**
     * Get full Redis key with prefix
     * @param string $key Base key
     * @return string Full key
     */
    private function getKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Destructor - close connection
     */
    public function __destruct()
    {
        $this->close();
    }
}