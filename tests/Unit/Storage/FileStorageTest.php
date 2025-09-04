<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit\Storage;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Storage\FileStorage;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;

/**
 * FileStorage unit tests
 * 文件存储单元测试
 */
class FileStorageTest extends TestCase
{
    private FileStorage $storage;
    private string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = $this->getTempFilePath();
        $this->storage = new FileStorage($this->testFilePath);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFile($this->testFilePath);
        parent::tearDown();
    }

    public function testImplementsStorageInterface(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    public function testIsAvailableWhenDirectoryIsWritable(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testGetFilePathReturnsCorrectPath(): void
    {
        $this->assertEquals($this->testFilePath, $this->storage->getFilePath());
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
        $this->assertArrayHasKey('file_path', $stats);
        $this->assertArrayHasKey('file_exists', $stats);
        $this->assertArrayHasKey('file_size', $stats);
        $this->assertArrayHasKey('challenges_count', $stats);
        $this->assertArrayHasKey('tokens_count', $stats);
        $this->assertArrayHasKey('is_writable', $stats);

        $this->assertEquals($this->testFilePath, $stats['file_path']);
        $this->assertTrue($stats['file_exists']);
        $this->assertGreaterThan(0, $stats['file_size']);
        $this->assertEquals(2, $stats['challenges_count']);
        $this->assertEquals(1, $stats['tokens_count']);
        $this->assertTrue($stats['is_writable']);
    }

    public function testPersistenceAcrossInstances(): void
    {
        $token = 'persistent_challenge';
        $key = 'persistent_id:persistent_hash';
        $expiresTs = time() + 3600;

        // 在第一个实例中设置数据
        $this->storage->setChallenge($token, $expiresTs);
        $this->storage->setToken($key, $expiresTs);

        // 创建新实例，应该能读取到数据
        $newStorage = new FileStorage($this->testFilePath);
        
        $retrievedChallengeExpires = $newStorage->getChallenge($token);
        $retrievedTokenExpires = $newStorage->getToken($key);

        $this->assertEquals($expiresTs, $retrievedChallengeExpires);
        $this->assertEquals($expiresTs, $retrievedTokenExpires);
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

    public function testHandlesEmptyFileCorrectly(): void
    {
        // 创建空文件
        file_put_contents($this->testFilePath, '');
        
        // 空文件应该抛出异常
        $this->expectException(\Sparkinzy\CapPhpServer\Exceptions\CapException::class);
        $this->expectExceptionMessage('Invalid JSON in storage file');
        
        $storage = new FileStorage($this->testFilePath);
    }

    public function testHandlesInvalidJsonCorrectly(): void
    {
        // 写入无效的JSON
        file_put_contents($this->testFilePath, '{invalid json}');
        
        // 无效JSON应该抛出异常
        $this->expectException(\Sparkinzy\CapPhpServer\Exceptions\CapException::class);
        $this->expectExceptionMessage('Invalid JSON in storage file');
        
        $storage = new FileStorage($this->testFilePath);
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

    public function testSpecialCharactersInKeys(): void
    {
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

    public function testFilePermissionIssues(): void
    {
        // 测试只读目录（如果可能的话）
        $readOnlyDir = sys_get_temp_dir() . '/readonly_' . uniqid();
        
        if (mkdir($readOnlyDir) && chmod($readOnlyDir, 0444)) {
            $readOnlyFile = $readOnlyDir . '/test.json';
            $storage = new FileStorage($readOnlyFile);
            
            // 在只读目录中应该检测到不可用
            $this->assertFalse($storage->isAvailable());
            
            // 清理
            chmod($readOnlyDir, 0755);
            rmdir($readOnlyDir);
        } else {
            $this->markTestSkipped('Unable to create read-only directory for permission test');
        }
    }

    public function testConcurrentAccess(): void
    {
        // 模拟并发访问场景
        $token1 = 'concurrent_challenge_1';
        $token2 = 'concurrent_challenge_2';
        $expires = time() + 3600;

        // 第一个实例写入数据
        $storage1 = new FileStorage($this->testFilePath);
        $storage1->setChallenge($token1, $expires);

        // 第二个实例写入另一个数据（会覆盖第一个的数据）
        $storage2 = new FileStorage($this->testFilePath);
        $storage2->setChallenge($token2, $expires);

        // 第三个实例验证数据持久性
        $storage3 = new FileStorage($this->testFilePath);
        // 由于文件存储的覆盖特性，只有最后一次写入的数据会被保留
        $this->assertNotNull($storage3->getChallenge($token2));
    }

    public function testLargeDataSet(): void
    {
        // 测试大量数据的处理
        $startTime = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $this->storage->setChallenge("challenge_$i", time() + 3600);
            $this->storage->setToken("id_$i:hash_$i", time() + 3600);
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // 验证性能在合理范围内（1000个操作应该在几秒内完成）
        $this->assertLessThan(10, $duration, "Large dataset operations took too long: {$duration}s");

        // 验证数据完整性
        $stats = $this->storage->getStats();
        $this->assertEquals(1000, $stats['challenges_count']);
        $this->assertEquals(1000, $stats['tokens_count']);
    }
}