<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\RateLimiter;

/**
 * RateLimiter unit tests
 * 频率限制器单元测试
 * 
 * 测试token bucket算法的实现
 */
class RateLimiterTest extends TestCase
{
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rateLimiter = new RateLimiter(10, 50); // 10 RPS, 50 burst
    }

    public function testCanCreateRateLimiter(): void
    {
        $rateLimiter = new RateLimiter();
        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    public function testCanCreateRateLimiterWithCustomParams(): void
    {
        $rateLimiter = new RateLimiter(5, 25);
        $limits = $rateLimiter->getLimits();
        
        $this->assertEquals(5, $limits['rps']);
        $this->assertEquals(25, $limits['burst']);
    }

    public function testGetLimitsReturnsCorrectValues(): void
    {
        $limits = $this->rateLimiter->getLimits();
        
        $this->assertIsArray($limits);
        $this->assertArrayHasKey('rps', $limits);
        $this->assertArrayHasKey('burst', $limits);
        $this->assertEquals(10, $limits['rps']);
        $this->assertEquals(50, $limits['burst']);
    }

    public function testSetLimitsUpdatesValues(): void
    {
        $this->rateLimiter->setLimits(20, 100);
        $limits = $this->rateLimiter->getLimits();
        
        $this->assertEquals(20, $limits['rps']);
        $this->assertEquals(100, $limits['burst']);
    }

    public function testAllowReturnsTrueForInitialRequest(): void
    {
        $key = 'test_user';
        $result = $this->rateLimiter->allow($key);
        
        $this->assertTrue($result);
    }

    public function testBurstCapacityIsRespected(): void
    {
        $key = 'burst_test_user';
        $burstLimit = 50;
        
        // 应该能处理突发请求直到达到限制
        for ($i = 0; $i < $burstLimit; $i++) {
            $result = $this->rateLimiter->allow($key);
            $this->assertTrue($result, "Request $i should be allowed");
        }
        
        // 超出突发限制应该被拒绝
        $result = $this->rateLimiter->allow($key);
        $this->assertFalse($result, 'Request exceeding burst should be denied');
    }

    public function testTokenRefillOverTime(): void
    {
        $key = 'refill_test_user';
        
        // 消耗所有突发容量
        for ($i = 0; $i < 50; $i++) {
            $this->rateLimiter->allow($key);
        }
        
        // 下一个请求应该被拒绝
        $this->assertFalse($this->rateLimiter->allow($key));
        
        // 等待一秒让token重新填充
        sleep(1);
        
        // 现在应该有大约10个新token（基于10 RPS）
        for ($i = 0; $i < 10; $i++) {
            $result = $this->rateLimiter->allow($key);
            $this->assertTrue($result, "Refilled request $i should be allowed");
        }
        
        // 再次超出限制应该被拒绝
        $this->assertFalse($this->rateLimiter->allow($key));
    }

    public function testGetTokensReturnsCorrectCount(): void
    {
        $key = 'token_count_user';
        
        // 初始状态应该有完整的突发容量
        $initialTokens = $this->rateLimiter->getTokens($key);
        $this->assertEquals(50, $initialTokens);
        
        // 消耗一些token
        $this->rateLimiter->allow($key);
        $this->rateLimiter->allow($key);
        
        // 由于时间流逝，token数量可能略有不同，所以使用范围检查
        $remainingTokens = $this->rateLimiter->getTokens($key);
        $this->assertGreaterThanOrEqual(47, $remainingTokens);
        $this->assertLessThanOrEqual(49, $remainingTokens);
    }

    public function testResetClearsUserBucket(): void
    {
        $key = 'reset_test_user';
        
        // 消耗所有token
        for ($i = 0; $i < 50; $i++) {
            $this->rateLimiter->allow($key);
        }
        
        // 验证没有token了
        $this->assertFalse($this->rateLimiter->allow($key));
        
        // 重置用户
        $this->rateLimiter->reset($key);
        
        // 现在应该又有完整的突发容量
        $this->assertTrue($this->rateLimiter->allow($key));
        $tokens = $this->rateLimiter->getTokens($key);
        $this->assertGreaterThanOrEqual(49, $tokens); // 减去刚才消耗的1个
    }

    public function testCleanupRemovesOldEntries(): void
    {
        $key1 = 'cleanup_user_1';
        $key2 = 'cleanup_user_2';
        
        // 创建一些桶
        $this->rateLimiter->allow($key1);
        $this->rateLimiter->allow($key2);
        
        // 使用反射来验证桶的存在
        $reflection = new \ReflectionClass($this->rateLimiter);
        $bucketsProperty = $reflection->getProperty('buckets');
        $bucketsProperty->setAccessible(true);
        $buckets = $bucketsProperty->getValue($this->rateLimiter);
        
        $this->assertArrayHasKey($key1, $buckets);
        $this->assertArrayHasKey($key2, $buckets);
        
        // 执行清理（使用默认的1小时）
        $this->rateLimiter->cleanup(0); // 0秒，清理所有条目
        
        $bucketsAfterCleanup = $bucketsProperty->getValue($this->rateLimiter);
        $this->assertEmpty($bucketsAfterCleanup);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $key1 = 'user_1';
        $key2 = 'user_2';
        
        // 用完key1的所有token
        for ($i = 0; $i < 50; $i++) {
            $this->rateLimiter->allow($key1);
        }
        
        // key1应该被限制
        $this->assertFalse($this->rateLimiter->allow($key1));
        
        // 但key2应该仍然可以使用
        $this->assertTrue($this->rateLimiter->allow($key2));
    }

    public function testCustomLimitPerRequest(): void
    {
        $key = 'custom_limit_user';
        
        // 使用自定义限制：2 RPS，但仍然受burst限制影响
        $this->assertTrue($this->rateLimiter->allow($key, 2));
        
        // 立即的第二个请求仍然可能被允许，因为有burst容量
        // 所以我们需要消耗更多请求来达到真正的限制
        $allowedCount = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($this->rateLimiter->allow($key, 2)) {
                $allowedCount++;
            } else {
                break;
            }
        }
        
        // 应该在某个点被限制住
        $this->assertLessThan(100, $allowedCount, 'Rate limiter should have kicked in');
        $this->assertGreaterThan(1, $allowedCount, 'At least some requests should be allowed');
    }

    public function testZeroLimitDisablesRateLimiting(): void
    {
        $rateLimiter = new RateLimiter(0, 0);
        $key = 'unlimited_user';
        
        // 即使大量请求也应该被允许
        for ($i = 0; $i < 1000; $i++) {
            $result = $rateLimiter->allow($key);
            $this->assertTrue($result, "Request $i should be allowed when rate limiting is disabled");
        }
    }

    public function testNegativeLimitDisablesRateLimiting(): void
    {
        $rateLimiter = new RateLimiter(-1, -1);
        $key = 'negative_limit_user';
        
        // 负数限制应该禁用限流
        for ($i = 0; $i < 100; $i++) {
            $result = $rateLimiter->allow($key);
            $this->assertTrue($result, "Request $i should be allowed with negative limits");
        }
    }

    public function testVeryLowLimits(): void
    {
        $rateLimiter = new RateLimiter(1, 1); // 1 RPS, 1 burst
        $key = 'low_limit_user';
        
        // 第一个请求应该被允许
        $this->assertTrue($rateLimiter->allow($key));
        
        // 第二个请求应该被拒绝
        $this->assertFalse($rateLimiter->allow($key));
        
        // 等待1秒后应该可以再次请求
        sleep(1);
        $this->assertTrue($rateLimiter->allow($key));
    }

    public function testHighLimits(): void
    {
        $rateLimiter = new RateLimiter(1000, 10000); // 1000 RPS, 10000 burst
        $key = 'high_limit_user';
        
        // 应该能处理大量请求
        for ($i = 0; $i < 5000; $i++) {
            $result = $rateLimiter->allow($key);
            $this->assertTrue($result, "Request $i should be allowed with high limits");
        }
    }

    public function testManyUsers(): void
    {
        $userCount = 100;
        $requestsPerUser = 10;
        
        // 为多个用户创建请求
        for ($userId = 0; $userId < $userCount; $userId++) {
            $key = "user_$userId";
            
            for ($request = 0; $request < $requestsPerUser; $request++) {
                $result = $this->rateLimiter->allow($key);
                $this->assertTrue($result, "Request $request for user $userId should be allowed");
            }
        }
    }

    public function testTokenRefillCalculation(): void
    {
        $rateLimiter = new RateLimiter(10, 20); // 10 RPS, 20 burst
        $key = 'refill_calc_user';
        
        // 消耗所有token
        for ($i = 0; $i < 20; $i++) {
            $rateLimiter->allow($key);
        }
        
        // 等待0.5秒，应该有约5个token
        usleep(500000); // 500ms
        
        $tokens = $rateLimiter->getTokens($key);
        $this->assertGreaterThanOrEqual(4, $tokens);
        $this->assertLessThanOrEqual(6, $tokens);
    }

    public function testBurstCapacityLimit(): void
    {
        $rateLimiter = new RateLimiter(1, 5); // 1 RPS, 5 burst
        $key = 'burst_limit_user';
        
        // 消耗所有burst
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($rateLimiter->allow($key));
        }
        
        // 等待很长时间（应该不会超过burst限制）
        sleep(10);
        
        $tokens = $rateLimiter->getTokens($key);
        $this->assertLessThanOrEqual(5, $tokens, 'Tokens should not exceed burst capacity');
    }

    public function testEdgeCaseTimingIssues(): void
    {
        $rateLimiter = new RateLimiter(100, 1); // 100 RPS, 1 burst
        $key = 'timing_test_user';
        
        // 第一个请求
        $this->assertTrue($rateLimiter->allow($key));
        
        // 立即的第二个请求应该被拒绝
        $this->assertFalse($rateLimiter->allow($key));
        
        // 等待足够的时间重新填充
        usleep(20000); // 20ms should be enough for 100 RPS
        
        $this->assertTrue($rateLimiter->allow($key));
    }

    public function testEmptyKeyHandling(): void
    {
        // 测试空字符串键
        $this->assertTrue($this->rateLimiter->allow(''));
        
        // 测试空键是否独立
        for ($i = 0; $i < 50; $i++) {
            $this->rateLimiter->allow('');
        }
        
        $this->assertFalse($this->rateLimiter->allow(''));
        
        // 其他键应该不受影响
        $this->assertTrue($this->rateLimiter->allow('normal_key'));
    }

    public function testSpecialCharacterKeys(): void
    {
        $specialKeys = [
            '🚀 emoji key',
            '中文键',
            'key:with:colons',
            'key with spaces',
            'key\nwith\nnewlines',
            'key\twith\ttabs'
        ];
        
        foreach ($specialKeys as $key) {
            $result = $this->rateLimiter->allow($key);
            $this->assertTrue($result, "Special key '$key' should be allowed");
        }
    }

    public function testPerformanceWithManyKeys(): void
    {
        $keyCount = 1000;
        $startTime = microtime(true);
        
        // 创建大量不同的键
        for ($i = 0; $i < $keyCount; $i++) {
            $key = "performance_user_$i";
            $this->rateLimiter->allow($key);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // 1000个键的操作应该在合理时间内完成
        $this->assertLessThan(1, $duration, "Performance test took too long: {$duration}s");
    }

    public function testMemoryUsageWithManyKeys(): void
    {
        $memoryBefore = memory_get_usage();
        
        // 创建大量键
        for ($i = 0; $i < 10000; $i++) {
            $key = "memory_user_$i";
            $this->rateLimiter->allow($key);
        }
        
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // 内存使用应该在合理范围内（每个键应该不超过1KB）
        $this->assertLessThan(10000 * 1024, $memoryUsed, "Memory usage too high: {$memoryUsed} bytes");
    }

    public function testCustomWindowParameter(): void
    {
        $key = 'window_test_user';
        
        // 使用自定义窗口：2秒（避免除零错误）
        $this->assertTrue($this->rateLimiter->allow($key, 2, 2));
        $this->assertTrue($this->rateLimiter->allow($key, 2, 2));
        
        // 继续请求直到被限制
        $allowedCount = 2;
        for ($i = 0; $i < 100; $i++) {
            if ($this->rateLimiter->allow($key, 2, 2)) {
                $allowedCount++;
            } else {
                break;
            }
        }
        
        // 应该在某个点被限制
        $this->assertLessThan(102, $allowedCount, 'Rate limiter should have kicked in');
    }
}