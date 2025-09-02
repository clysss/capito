<?php

namespace Sparkinzy\CapPhpServer;

use Exception;

/**
 * Cap PHP Server - A PHP implementation of Cap
 * A lightweight, modern open-source CAPTCHA alternative using SHA-256 proof-of-work
 */
class Cap
{
    private $config;
    private $state;
    private $redisStorage;
    
    const DEFAULT_TOKENS_STORE = '.data/tokensList.json';
    const DEFAULT_CHALLENGE_COUNT = 50;
    const DEFAULT_CHALLENGE_SIZE = 32;
    const DEFAULT_CHALLENGE_DIFFICULTY = 4;
    const DEFAULT_EXPIRES_MS = 600000;      // 10 minutes
    const DEFAULT_TOKEN_EXPIRES_MS = 1200000; // 20 minutes

    /**
     * Create a new Cap instance
     * @param array|null $configObj Configuration options
     */
    public function __construct(?array $configObj = null)
    {
        $this->config = [
            'tokensStorePath' => self::DEFAULT_TOKENS_STORE,
            'noFSState' => false,
            'redis' => null,
            'state' => [
                'challengesList' => [],
                'tokensList' => []
            ]
        ];

        if ($configObj !== null) {
            if (isset($configObj['tokensStorePath']) && $configObj['tokensStorePath'] !== '') {
                $this->config['tokensStorePath'] = $configObj['tokensStorePath'];
            }
            if (isset($configObj['noFSState'])) {
                $this->config['noFSState'] = $configObj['noFSState'];
            }
            if (isset($configObj['redis'])) {
                $this->config['redis'] = $configObj['redis'];
            }
            if (isset($configObj['state'])) {
                $this->config['state'] = $configObj['state'];
            }
        }

        $this->state = $this->config['state'];

        // Initialize Redis storage if configured
        if ($this->config['redis'] !== null) {
            try {
                $this->redisStorage = new RedisStorage($this->config['redis']);
                if ($this->redisStorage->isConnected()) {
                    $this->state = $this->redisStorage->loadState();
                } else {
                    error_log("Warning: Redis connection failed, falling back to file storage");
                    $this->loadTokens();
                }
            } catch (Exception $e) {
                error_log("Warning: Redis initialization failed: " . $e->getMessage());
                $this->loadTokens();
            }
        } elseif (!$this->config['noFSState']) {
            $this->loadTokens();
        }
    }

    /**
     * Create a new challenge
     * @param array|null $conf Challenge configuration
     * @return array Challenge response
     * @throws Exception
     */
    public function createChallenge(?array $conf = null): array
    {
        $this->cleanExpiredTokens();

        // Set default values
        $challengeCount = self::DEFAULT_CHALLENGE_COUNT;
        $challengeSize = self::DEFAULT_CHALLENGE_SIZE;
        $challengeDifficulty = self::DEFAULT_CHALLENGE_DIFFICULTY;
        $expiresMs = self::DEFAULT_EXPIRES_MS;
        $store = true;

        if ($conf !== null) {
            if (isset($conf['challengeCount']) && $conf['challengeCount'] > 0) {
                $challengeCount = $conf['challengeCount'];
            }
            if (isset($conf['challengeSize']) && $conf['challengeSize'] > 0) {
                $challengeSize = $conf['challengeSize'];
            }
            if (isset($conf['challengeDifficulty']) && $conf['challengeDifficulty'] > 0) {
                $challengeDifficulty = $conf['challengeDifficulty'];
            }
            if (isset($conf['expiresMs']) && $conf['expiresMs'] > 0) {
                $expiresMs = $conf['expiresMs'];
            }
            if (isset($conf['store'])) {
                $store = $conf['store'];
            }
        }

        // Generate challenges
        $challenges = [];
        for ($i = 0; $i < $challengeCount; $i++) {
            $salt = $this->generateRandomHex($challengeSize);
            $target = $this->generateRandomHex($challengeDifficulty);
            $challenges[] = [$salt, $target];
        }

        $token = $this->generateRandomHex(50);
        $expires = (int)(microtime(true) * 1000) + $expiresMs;

        if (!$store) {
            return [
                'challenge' => $challenges,
                'expires' => $expires
            ];
        }

        $this->state['challengesList'][$token] = [
            'challenge' => $challenges,
            'expires' => $expires,
            'token' => $token
        ];

        if (!$this->config['noFSState']) {
            try {
                $this->saveTokens();
            } catch (Exception $e) {
                // Log error but don't fail the operation
                error_log("Warning: failed to save tokens: " . $e->getMessage());
            }
        }

        return [
            'challenge' => $challenges,
            'token' => $token,
            'expires' => $expires
        ];
    }

    /**
     * Redeem a challenge solution
     * @param array $solution Solution data
     * @return array Redeem response
     * @throws Exception
     */
    public function redeemChallenge(array $solution): array
    {
        if (!isset($solution['token']) || $solution['token'] === '' || !isset($solution['solutions'])) {
            return [
                'success' => false,
                'message' => 'Invalid body'
            ];
        }

        $this->cleanExpiredTokens();

        $token = $solution['token'];
        
        // 检查挑战是否存在
        if (!isset($this->state['challengesList'][$token])) {
            return [
                'success' => false,
                'message' => 'Challenge not found or invalid'
            ];
        }
        
        // 检查挑战是否过期
        if ($this->state['challengesList'][$token]['expires'] < (int)(microtime(true) * 1000)) {
            unset($this->state['challengesList'][$token]);
            return [
                'success' => false,
                'message' => 'Challenge expired'
            ];
        }

        $challengeData = $this->state['challengesList'][$token];
        unset($this->state['challengesList'][$token]);

        // Validate all challenges
        foreach ($challengeData['challenge'] as $challenge) {
            list($salt, $target) = $challenge;
            $found = false;

            foreach ($solution['solutions'] as $sol) {
                if (count($sol) !== 3) {
                    continue;
                }

                list($solSalt, $solTarget, $solValue) = $sol;
                if ($solSalt !== $salt || $solTarget !== $target) {
                    continue;
                }

                // Convert solution value to string
                $solStr = (string)$solValue;

                // Verify the solution
                $hash = hash('sha256', $salt . $solStr);
                if (strpos($hash, $target) === 0) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return [
                    'success' => false,
                    'message' => 'Invalid solution'
                ];
            }
        }

        // Generate verification token
        $vertoken = $this->generateRandomHex(30);
        $expires = (int)(microtime(true) * 1000) + self::DEFAULT_TOKEN_EXPIRES_MS;
        $hash = hash('sha256', $vertoken);
        $id = $this->generateRandomHex(16);
        $key = $id . ':' . $hash;

        $this->state['tokensList'][$key] = $expires;

        if (!$this->config['noFSState']) {
            try {
                $this->saveTokens();
            } catch (Exception $e) {
                // Log error but don't fail the operation
                error_log("Warning: failed to save tokens: " . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'token' => $id . ':' . $vertoken,
            'expires' => $expires
        ];
    }

    /**
     * Validate a verification token
     * @param string $token Token to validate
     * @param array|null $conf Token configuration
     * @return array Validation response
     */
    public function validateToken(string $token, ?array $conf = null): array
    {
        $this->cleanExpiredTokens();

        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return ['success' => false];
        }

        list($id, $vertoken) = $parts;
        $hash = hash('sha256', $vertoken);
        $key = $id . ':' . $hash;

        if (isset($this->state['tokensList'][$key])) {
            $keepToken = $conf !== null && isset($conf['keepToken']) ? $conf['keepToken'] : false;
            if (!$keepToken) {
                unset($this->state['tokensList'][$key]);
            }

            if (!$this->config['noFSState']) {
                try {
                    $this->saveTokens();
                } catch (Exception $e) {
                    // Log error but don't fail the operation
                    error_log("Warning: failed to save tokens: " . $e->getMessage());
                }
            }

            return ['success' => true];
        }

        return ['success' => false];
    }

    /**
     * Clean up expired tokens
     * @return bool Whether tokens were changed
     */
    public function cleanup(): bool
    {
        $tokensChanged = $this->cleanExpiredTokens();

        if ($tokensChanged && !$this->config['noFSState']) {
            try {
                $this->saveTokens();
                return true;
            } catch (Exception $e) {
                error_log("Warning: failed to save tokens during cleanup: " . $e->getMessage());
                return false;
            }
        }

        return $tokensChanged;
    }

    /**
     * Load tokens from storage file
     */
    private function loadTokens(): void
    {
        $filePath = $this->config['tokensStorePath'];
        $dirPath = dirname($filePath);
        
        if ($dirPath !== '.' && !is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                error_log("Warning: couldn't create tokens directory: " . $dirPath);
                $this->state['tokensList'] = [];
                $this->state['challengesList'] = [];
                return;
            }
        }

        if (!file_exists($filePath)) {
            // File doesn't exist, create empty one
            error_log("[cap] Tokens file not found, creating a new empty one");
            try {
                file_put_contents($filePath, '{"tokensList":{},"challengesList":{}}', LOCK_EX);
            } catch (Exception $e) {
                error_log("Warning: couldn't create tokens file: " . $e->getMessage());
            }
            $this->state['tokensList'] = [];
            $this->state['challengesList'] = [];
            return;
        }

        try {
            $data = file_get_contents($filePath);
            if ($data === false) {
                throw new Exception("Failed to read tokens file");
            }
            
            $stateData = json_decode($data, true);
            if ($stateData === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON format: " . json_last_error_msg());
            }

            $this->state['tokensList'] = $stateData['tokensList'] ?? [];
            $this->state['challengesList'] = $stateData['challengesList'] ?? [];
            $this->cleanExpiredTokens();
        } catch (Exception $e) {
            error_log("Warning: couldn't parse tokens file, using empty state: " . $e->getMessage());
            $this->state['tokensList'] = [];
            $this->state['challengesList'] = [];
        }
    }

    /**
     * Save tokens to storage (file or Redis)
     * @throws Exception
     */
    private function saveTokens(): void
    {
        // Use Redis storage if configured and connected
        if ($this->config['redis'] !== null && isset($this->redisStorage) && $this->redisStorage->isConnected()) {
            if (!$this->redisStorage->saveState($this->state)) {
                throw new Exception("Failed to save state to Redis");
            }
        } else {
            // Fall back to file storage
            $stateData = [
                'tokensList' => $this->state['tokensList'],
                'challengesList' => $this->state['challengesList']
            ];
            
            $data = json_encode($stateData, JSON_PRETTY_PRINT);
            if ($data === false) {
                throw new Exception("Failed to encode tokens data");
            }

            $result = file_put_contents($this->config['tokensStorePath'], $data, LOCK_EX);
            if ($result === false) {
                throw new Exception("Failed to write tokens file");
            }
        }
    }

    /**
     * Clean expired tokens and challenges
     * @return bool Whether tokens were changed
     */
    private function cleanExpiredTokens(): bool
    {
        $now = (int)(microtime(true) * 1000);
        $tokensChanged = false;

        // Clean expired challenges
        foreach ($this->state['challengesList'] as $key => $value) {
            if ($value['expires'] < $now) {
                unset($this->state['challengesList'][$key]);
            }
        }

        // Clean expired tokens
        foreach ($this->state['tokensList'] as $key => $value) {
            if ($value < $now) {
                unset($this->state['tokensList'][$key]);
                $tokensChanged = true;
            }
        }

        // If using Redis, also clean expired items in Redis
        if ($this->config['redis'] !== null && isset($this->redisStorage) && $this->redisStorage->isConnected()) {
            $redisCleaned = $this->redisStorage->cleanExpired();
            $tokensChanged = $tokensChanged || $redisCleaned;
        }

        return $tokensChanged;
    }

    /**
     * Generate random hex string
     * @param int $length Length of hex string
     * @return string Random hex string
     * @throws Exception
     */
    private function generateRandomHex(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $bytes = random_bytes((int)ceil($length / 2));
        $hex = bin2hex($bytes);
        return substr($hex, 0, $length);
    }
}