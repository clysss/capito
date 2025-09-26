<?php
// CAUTION : this code has not been fully tested
// use it, debug it, and please commit update !
/*
    'storage' => new RedisStorage([
        'host'     => '127.0.0.1', // Or the IP/hostname of your Redis server
        'port'     => 6379,       // Default Redis port
        'password' => null,       // Your Redis password, or null if none is set
        'database' => 0,          // The database index to use
        'prefix'   => 'cap:',     // Optional: a key prefix to avoid collisions
        'timeout'  => 2.0         // Connection timeout in seconds
    ])
*/

namespace Capito\CapPhpServer\Storage;

use Exception;
use Redis;
use Capito\CapPhpServer\Interfaces\StorageInterface;

/**
 * Redis Storage Adapter for Cap Server
 * Provides Redis-based persistence for tokens and challenges
 * Implements StorageInterface for unified storage access
 */
class RedisStorage implements StorageInterface
{
    private $redis = null;
    private string $prefix;
    private bool $connected = false;

    const DEFAULT_PREFIX = 'cap:';

    /**
     * Create a new RedisStorage instance
     * @param array $config Redis configuration
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->redis = new Redis();
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
            
            if (!$this->redis->ping()) {
                throw new Exception("Redis connection test failed");
            }
            
        } catch (Exception $e) {
            throw new Exception("Redis connection error: " . $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function setChallenge(string $token, int $expiresTs, array $data): bool
    {
        try {
            $data['expires'] = $expiresTs * 1000;
            return $this->redis->setex($this->getKey($token), $expiresTs, json_encode($data)) !== false;
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to set challenge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getChallenge(string $token, bool $delete = false): ?array
    {
        try {
            $jsonData = $this->redis->get($this->getKey($token));
            
            if ($jsonData === false) {
                return null;
            }
            
            $data = json_decode($jsonData, true);
            if ($data === null || !isset($data['expires'])) {
                return null;
            }
            
            return $data;
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to get challenge: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function setToken(string $key, int $expiresTs, string $challengeToken): bool
    {
        try {
            $pipeline = $this->redis->pipeline();
            $pipeline->setex($this->getKey($key), $expiresTs, (string)($expiresTs * 1000));
            $pipeline->del($this->getKey($challengeToken));
            $result = $pipeline->exec();
            
            // The result is an array of responses. The first is for SETEX, the second for DEL.
            // Check if both operations were successful.
            return $result[0] !== false && $result[1] !== false;
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to set token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getToken(string $token, bool $delete = false, bool $cleanup = false): ?int
    {
        try {
            if ($cleanup) {
                $this->cleanup();
            }

            $expiresStr = $this->redis->get($this->getKey($token));
            
            if ($expiresStr === false) {
                return null;
            }
            
            if ($delete) {
                $this->redis->del($this->getKey($token));
            }
            
            return (int)($expiresStr / 1000);
        } catch (Exception $e) {
            error_log("RedisStorage: Failed to get token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function cleanup(): bool
    {
        // Redis handles cleanup automatically via TTL.
        // We only need to check if we can connect.
        try {
            return $this->redis->ping();
        } catch (Exception $e) {
            error_log("RedisStorage: Cleanup failed due to connection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->connected && $this->redis->ping();
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
     * Close Redis connection
     */
    public function close(): void
    {
        if ($this->connected && $this->redis !== null) {
            $this->redis->close();
            $this->connected = false;
        }
    }

    /**
     * Destructor - close connection
     */
    public function __destruct()
    {
        $this->close();
    }
}
