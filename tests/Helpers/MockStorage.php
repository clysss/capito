<?php

namespace Sparkinzy\CapPhpServer\Tests\Helpers;

use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * Mock storage implementation for testing various failure scenarios
 * 用于测试各种失败场景的模拟存储实现
 */
class MockStorage implements StorageInterface
{
    private array $challenges = [];
    private array $tokens = [];
    private bool $available = true;
    private bool $shouldFailOnSet = false;
    private bool $shouldFailOnGet = false;
    private bool $shouldFailOnCleanup = false;
    private array $operationCounts = [
        'setChallenge' => 0,
        'getChallenge' => 0,
        'setToken' => 0,
        'getToken' => 0,
        'cleanup' => 0
    ];

    /**
     * 设置存储是否可用
     * @param bool $available
     */
    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    /**
     * 设置写入操作是否失败
     * @param bool $shouldFail
     */
    public function setShouldFailOnSet(bool $shouldFail): void
    {
        $this->shouldFailOnSet = $shouldFail;
    }

    /**
     * 设置读取操作是否失败
     * @param bool $shouldFail
     */
    public function setShouldFailOnGet(bool $shouldFail): void
    {
        $this->shouldFailOnGet = $shouldFail;
    }

    /**
     * 设置清理操作是否失败
     * @param bool $shouldFail
     */
    public function setShouldFailOnCleanup(bool $shouldFail): void
    {
        $this->shouldFailOnCleanup = $shouldFail;
    }

    /**
     * 获取操作计数
     * @return array
     */
    public function getOperationCounts(): array
    {
        return $this->operationCounts;
    }

    /**
     * 重置操作计数
     */
    public function resetOperationCounts(): void
    {
        $this->operationCounts = [
            'setChallenge' => 0,
            'getChallenge' => 0,
            'setToken' => 0,
            'getToken' => 0,
            'cleanup' => 0
        ];
    }

    /**
     * 获取当前存储的挑战数量
     * @return int
     */
    public function getChallengeCount(): int
    {
        return count($this->challenges);
    }

    /**
     * 获取当前存储的令牌数量
     * @return int
     */
    public function getTokenCount(): int
    {
        return count($this->tokens);
    }

    /**
     * 清空所有数据
     */
    public function clear(): void
    {
        $this->challenges = [];
        $this->tokens = [];
        $this->resetOperationCounts();
    }

    /**
     * Set challenge token with expiration
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setChallenge(string $token, int $expiresTs): bool
    {
        $this->operationCounts['setChallenge']++;
        
        if ($this->shouldFailOnSet) {
            return false;
        }
        
        $this->challenges[$token] = $expiresTs;
        return true;
    }

    /**
     * Get challenge expiration time
     * @param string $token Challenge token
     * @param bool $delete Whether to delete after get (atomic operation)
     * @return int|null Expiration timestamp or null if not found
     */
    public function getChallenge(string $token, bool $delete = false): ?int
    {
        $this->operationCounts['getChallenge']++;
        
        if ($this->shouldFailOnGet) {
            return null;
        }
        
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
        $this->operationCounts['setToken']++;
        
        if ($this->shouldFailOnSet) {
            return false;
        }
        
        $this->tokens[$key] = $expiresTs;
        return true;
    }

    /**
     * Get verification token expiration time
     * @param string $key Token key (id:hash format)
     * @param bool $delete Whether to delete after get (atomic operation)
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $key, bool $delete = false): ?int
    {
        $this->operationCounts['getToken']++;
        
        if ($this->shouldFailOnGet) {
            return null;
        }
        
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
        $this->operationCounts['cleanup']++;
        
        if ($this->shouldFailOnCleanup) {
            return false;
        }
        
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
        
        return true;
    }

    /**
     * Check if storage is available/connected
     * @return bool Availability status
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * 模拟延迟操作
     * @param int $microseconds 延迟微秒数
     */
    public function simulateDelay(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * 手动设置过期的挑战
     * @param string $token
     */
    public function setExpiredChallenge(string $token): void
    {
        $this->challenges[$token] = time() - 3600; // 1小时前过期
    }

    /**
     * 手动设置过期的令牌
     * @param string $key
     */
    public function setExpiredToken(string $key): void
    {
        $this->tokens[$key] = time() - 3600; // 1小时前过期
    }
}