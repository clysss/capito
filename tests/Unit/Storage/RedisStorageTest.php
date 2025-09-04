<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit\Storage;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Storage\RedisStorage;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * RedisStorage unit tests
 * Redis存储单元测试
 * 
 * 注意：这些测试需要Redis服务器运行才能完全执行
 * 如果Redis不可用，测试会被跳过
 */
class RedisStorageTest extends TestCase
{
    private ?RedisStorage $storage = null;
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 检查Redis扩展是否可用
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not available');
            return;
        }

        try {
            $config = [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 2.5,
                'database' => 15 // 使用测试数据库
            ];
            
            $this->storage = new RedisStorage($config);
            $this->redisAvailable = $this->storage->isAvailable();
            
            if (!$this->redisAvailable) {
                $this->markTestSkipped('Redis server is not available at 127.0.0.1:6379');
                return;
            }

            // 清理测试数据库
            $this->cleanupRedisTestData();
            
        } catch (\Exception $e) {
            $this->markTestSkipped('Failed to connect to Redis: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->storage && $this->redisAvailable) {
            $this->cleanupRedisTestData();
        }
        parent::tearDown();
    }

    private function cleanupRedisTestData(): void
    {
        if ($this->storage && $this->redisAvailable) {
            // 删除所有测试相关的键
            $reflection = new \ReflectionClass($this->storage);
            $redisProperty = $reflection->getProperty('redis');
            $redisProperty->setAccessible(true);
            $redis = $redisProperty->getValue($this->storage);
            
            if ($redis) {
                $keys = $redis->keys('cap_test:*');
                if (!empty($keys)) {
                    $redis->del($keys);
                }
                $keys = $redis->keys('cap:challenge:*');
                if (!empty($keys)) {
                    $redis->del($keys);
                }
                $keys = $redis->keys('cap:token:*');
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        }
    }

    public function testImplementsStorageInterface(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }
        
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    public function testIsAvailableWhenRedisConnected(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }
        
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testIsNotAvailableWhenRedisDisconnected(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        $config = [
            'host' => '127.0.0.1', // 使用本地主机但无效端口
            'port' => 99999,       // 无效端口
            'timeout' => 1
        ];
        
        try {
            $storage = new RedisStorage($config);
            $this->assertFalse($storage->isAvailable());
        } catch (\Exception $e) {
            // 预期会抛出异常，这是正常行为
            $this->assertNotFalse(strpos($e->getMessage(), 'Redis connection error'));
        }
    }

    public function testCanSetAndGetChallenge(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $token = 'test_challenge_token';
        $expiresTs = time() + 3600;

        $result = $this->storage->setChallenge($token, $expiresTs);
        $this->assertTrue($result);

        $retrievedExpiresTs = $this->storage->getChallenge($token);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);
    }

    public function testCanSetAndGetToken(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $key = 'test_id:test_hash';
        $expiresTs = time() + 3600;

        $result = $this->storage->setToken($key, $expiresTs);
        $this->assertTrue($result);

        $retrievedExpiresTs = $this->storage->getToken($key);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);
    }

    public function testGetNonExistentChallengeReturnsNull(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $result = $this->storage->getChallenge('non_existent_token');
        $this->assertNull($result);
    }

    public function testGetNonExistentTokenReturnsNull(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $result = $this->storage->getToken('non_existent_key');
        $this->assertNull($result);
    }

    public function testGetChallengeWithDeleteRemovesChallenge(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $token = 'test_challenge_token';
        $expiresTs = time() + 3600;

        $this->storage->setChallenge($token, $expiresTs);
        
        // 获取并删除
        $retrievedExpiresTs = $this->storage->getChallenge($token, true);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);

        // 再次获取应该返回null
        $secondRetrieve = $this->storage->getChallenge($token);
        $this->assertNull($secondRetrieve);
    }

    public function testGetTokenWithDeleteRemovesToken(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $key = 'test_id:test_hash';
        $expiresTs = time() + 3600;

        $this->storage->setToken($key, $expiresTs);
        
        // 获取并删除
        $retrievedExpiresTs = $this->storage->getToken($key, true);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);

        // 再次获取应该返回null
        $secondRetrieve = $this->storage->getToken($key);
        $this->assertNull($secondRetrieve);
    }

    public function testCleanupRemovesExpiredItems(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $now = time();
        $expiredToken = 'expired_challenge';
        $validToken = 'valid_challenge';
        $expiredKey = 'expired_id:expired_hash';
        $validKey = 'valid_id:valid_hash';

        // 设置过期的挑战和令牌
        $this->storage->setChallenge($expiredToken, $now - 3600); // 1小时前过期
        $this->storage->setToken($expiredKey, $now - 3600);

        // 设置有效的挑战和令牌
        $this->storage->setChallenge($validToken, $now + 3600); // 1小时后过期
        $this->storage->setToken($validKey, $now + 3600);

        // 执行清理
        $result = $this->storage->cleanup();
        $this->assertTrue($result);

        // 验证过期项被删除，有效项保留
        $this->assertNull($this->storage->getChallenge($expiredToken));
        $this->assertNull($this->storage->getToken($expiredKey));
        $this->assertNotNull($this->storage->getChallenge($validToken));
        $this->assertNotNull($this->storage->getToken($validKey));
    }

    public function testCanOverwriteExistingChallenge(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $token = 'test_challenge';
        $firstExpires = time() + 3600;
        $secondExpires = time() + 7200;

        $this->storage->setChallenge($token, $firstExpires);
        $this->storage->setChallenge($token, $secondExpires);

        $retrievedExpires = $this->storage->getChallenge($token);
        $this->assertEquals($secondExpires, $retrievedExpires);
    }

    public function testCanOverwriteExistingToken(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $key = 'test_key';
        $firstExpires = time() + 3600;
        $secondExpires = time() + 7200;

        $this->storage->setToken($key, $firstExpires);
        $this->storage->setToken($key, $secondExpires);

        $retrievedExpires = $this->storage->getToken($key);
        $this->assertEquals($secondExpires, $retrievedExpires);
    }

    public function testMultipleChallengesAndTokens(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $challenges = [];
        $tokens = [];

        // 设置多个挑战和令牌
        for ($i = 0; $i < 10; $i++) {
            $challengeToken = "challenge_$i";
            $tokenKey = "id_$i:hash_$i";
            $expires = time() + 3600 + $i;

            $challenges[$challengeToken] = $expires;
            $tokens[$tokenKey] = $expires;

            $this->storage->setChallenge($challengeToken, $expires);
            $this->storage->setToken($tokenKey, $expires);
        }

        // 验证所有项都存在
        foreach ($challenges as $token => $expectedExpires) {
            $actualExpires = $this->storage->getChallenge($token);
            $this->assertEquals($expectedExpires, $actualExpires);
        }

        foreach ($tokens as $key => $expectedExpires) {
            $actualExpires = $this->storage->getToken($key);
            $this->assertEquals($expectedExpires, $actualExpires);
        }
    }

    public function testRedisConfiguration(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        // 测试各种配置选项
        $configs = [
            'minimal' => [
                'host' => '127.0.0.1',
                'port' => 6379
            ],
            'with_timeout' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 5
            ],
            'with_database' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 2.5,
                'database' => 15
            ]
        ];

        foreach ($configs as $name => $config) {
            $storage = new RedisStorage($config);
            
            if ($storage->isAvailable()) {
                $result = $storage->setChallenge("test_$name", time() + 3600);
                $this->assertTrue($result, "Failed to set challenge with config: $name");
                
                $retrieved = $storage->getChallenge("test_$name");
                $this->assertNotNull($retrieved, "Failed to retrieve challenge with config: $name");
            }
        }
    }

    public function testConnectionFailureHandling(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        // 测试连接失败情况
        $invalidConfigs = [
            'invalid_port' => [
                'host' => '127.0.0.1',
                'port' => 99999,
                'timeout' => 1
            ]
        ];

        foreach ($invalidConfigs as $name => $config) {
            try {
                $storage = new RedisStorage($config);
                $this->assertFalse($storage->isAvailable(), "Expected unavailable for config: $name");
                
                // 操作应该失败但不抛出异常
                $this->assertFalse($storage->setChallenge('test', time() + 3600));
                $this->assertNull($storage->getChallenge('test'));
                $this->assertFalse($storage->setToken('test', time() + 3600));
                $this->assertNull($storage->getToken('test'));
                $this->assertFalse($storage->cleanup());
            } catch (\Exception $e) {
                // 如果抛出异常，验证异常消息
                $this->assertNotFalse(strpos($e->getMessage(), 'Redis connection error'), "Unexpected exception for config: $name");
            }
        }
    }

    public function testBoundaryValues(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $now = time();
        
        // 测试边界时间值
        $this->storage->setChallenge('zero_expire', 0);
        $this->storage->setChallenge('past_expire', $now - 1);
        $this->storage->setChallenge('now_expire', $now);
        $this->storage->setChallenge('future_expire', $now + 1);

        // 在清理前所有值都应该存在
        $this->assertEquals(0, $this->storage->getChallenge('zero_expire'));
        $this->assertEquals($now - 1, $this->storage->getChallenge('past_expire'));
        $this->assertEquals($now, $this->storage->getChallenge('now_expire'));
        $this->assertEquals($now + 1, $this->storage->getChallenge('future_expire'));

        // 清理后只有未来的时间应该保留
        $this->storage->cleanup();
        $this->assertNull($this->storage->getChallenge('zero_expire'));
        $this->assertNull($this->storage->getChallenge('past_expire'));
        $this->assertNull($this->storage->getChallenge('now_expire'));
        $this->assertNotNull($this->storage->getChallenge('future_expire'));
    }

    public function testSpecialCharactersInKeys(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        $specialChars = ['🚀', '中文', 'special:chars'];
        
        foreach ($specialChars as $char) {
            $challengeToken = "challenge_$char";
            $tokenKey = "id_$char:hash_$char";
            $expires = time() + 3600;

            $this->storage->setChallenge($challengeToken, $expires);
            $this->storage->setToken($tokenKey, $expires);

            $this->assertEquals($expires, $this->storage->getChallenge($challengeToken));
            $this->assertEquals($expires, $this->storage->getToken($tokenKey));
        }
    }

    public function testLargeDataSet(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        // 测试大量数据的处理
        $startTime = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $this->storage->setChallenge("challenge_$i", time() + 3600);
            $this->storage->setToken("id_$i:hash_$i", time() + 3600);
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Redis应该有很好的性能
        $this->assertLessThan(5, $duration, "Large dataset operations took too long: {$duration}s");

        // 验证数据完整性（抽样检查）
        for ($i = 0; $i < 100; $i += 10) {
            $this->assertNotNull($this->storage->getChallenge("challenge_$i"));
            $this->assertNotNull($this->storage->getToken("id_$i:hash_$i"));
        }
    }

    public function testRedisConnectionRecovery(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis not available');
        }

        // 正常设置数据
        $token = 'recovery_test';
        $expires = time() + 3600;
        $this->storage->setChallenge($token, $expires);
        $this->assertNotNull($this->storage->getChallenge($token));

        // 模拟连接丢失（通过创建新的实例）
        $newStorage = new RedisStorage([
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 2.5,
            'database' => 15
        ]);

        // 新实例应该能够访问相同的数据
        $this->assertNotNull($newStorage->getChallenge($token));
    }
}