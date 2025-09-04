<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit\Storage;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Storage\MemoryStorage;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * MemoryStorage unit tests
 * 内存存储单元测试
 */
class MemoryStorageTest extends TestCase
{
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new MemoryStorage(5); // 5秒清理间隔
    }

    protected function tearDown(): void
    {
        $this->storage->clear();
        parent::tearDown();
    }

    public function testImplementsStorageInterface(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    public function testIsAlwaysAvailable(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testCanSetAndGetChallenge(): void
    {
        $token = 'test_challenge_token';
        $expiresTs = time() + 3600;

        $result = $this->storage->setChallenge($token, $expiresTs);
        $this->assertTrue($result);

        $retrievedExpiresTs = $this->storage->getChallenge($token);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);
    }

    public function testCanSetAndGetToken(): void
    {
        $key = 'test_id:test_hash';
        $expiresTs = time() + 3600;

        $result = $this->storage->setToken($key, $expiresTs);
        $this->assertTrue($result);

        $retrievedExpiresTs = $this->storage->getToken($key);
        $this->assertEquals($expiresTs, $retrievedExpiresTs);
    }

    public function testGetNonExistentChallengeReturnsNull(): void
    {
        $result = $this->storage->getChallenge('non_existent_token');
        $this->assertNull($result);
    }

    public function testGetNonExistentTokenReturnsNull(): void
    {
        $result = $this->storage->getToken('non_existent_key');
        $this->assertNull($result);
    }

    public function testGetChallengeWithDeleteRemovesChallenge(): void
    {
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

    public function testGetStatsReturnsCorrectInformation(): void
    {
        $this->storage->setChallenge('challenge1', time() + 3600);
        $this->storage->setChallenge('challenge2', time() + 3600);
        $this->storage->setToken('key1', time() + 3600);

        $stats = $this->storage->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('challenges_count', $stats);
        $this->assertArrayHasKey('tokens_count', $stats);
        $this->assertArrayHasKey('last_cleanup', $stats);
        $this->assertArrayHasKey('cleanup_interval', $stats);

        $this->assertEquals(2, $stats['challenges_count']);
        $this->assertEquals(1, $stats['tokens_count']);
        $this->assertEquals(5, $stats['cleanup_interval']);
    }

    public function testClearRemovesAllData(): void
    {
        $this->storage->setChallenge('challenge1', time() + 3600);
        $this->storage->setToken('key1', time() + 3600);

        $this->storage->clear();

        $stats = $this->storage->getStats();
        $this->assertEquals(0, $stats['challenges_count']);
        $this->assertEquals(0, $stats['tokens_count']);
    }

    public function testCanOverwriteExistingChallenge(): void
    {
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

        // 验证统计信息
        $stats = $this->storage->getStats();
        $this->assertEquals(10, $stats['challenges_count']);
        $this->assertEquals(10, $stats['tokens_count']);
    }

    public function testConstructorWithCustomCleanupInterval(): void
    {
        $customInterval = 60;
        $storage = new MemoryStorage($customInterval);

        $stats = $storage->getStats();
        $this->assertEquals($customInterval, $stats['cleanup_interval']);
    }

    public function testBoundaryValues(): void
    {
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

        // 清理后只有未来的时间应该保留 (注意: now_expire 也应该被保留，因为检查条件是 < now)
        $this->storage->cleanup();
        $this->assertNull($this->storage->getChallenge('zero_expire'));
        $this->assertNull($this->storage->getChallenge('past_expire'));
        $this->assertNotNull($this->storage->getChallenge('now_expire')); // now 的值不会被清理
        $this->assertNotNull($this->storage->getChallenge('future_expire'));
    }

    public function testEmptyKeysAndTokens(): void
    {
        // 测试空字符串键
        $this->storage->setChallenge('', time() + 3600);
        $this->storage->setToken('', time() + 3600);

        $this->assertIsInt($this->storage->getChallenge(''));
        $this->assertIsInt($this->storage->getToken(''));
    }

    public function testSpecialCharactersInKeys(): void
    {
        $specialChars = ['🚀', '中文', 'special:chars', 'with\nnewline', 'with\ttab'];
        
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
}