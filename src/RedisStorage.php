<?php

namespace Sparkinzy\CapPhpServer;

use Exception;

/**
 * Redis Storage Adapter for Cap Server
 * Provides Redis-based persistence for tokens and challenges
 */
class RedisStorage
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