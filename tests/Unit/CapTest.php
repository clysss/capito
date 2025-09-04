<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Tests\Helpers\MockStorage;
use Sparkinzy\CapPhpServer\Cap;
use Sparkinzy\CapPhpServer\Storage\MemoryStorage;
use Sparkinzy\CapPhpServer\Storage\FileStorage;
use Sparkinzy\CapPhpServer\Exceptions\CapException;

/**
 * Cap class unit tests
 * Cap主类单元测试
 * 
 * 测试核心业务逻辑：createChallenge, redeemChallenge, validateToken
 */
class CapTest extends TestCase
{
    public function testCanCreateCapInstance(): void
    {
        $cap = $this->createCapInstance();
        $this->assertInstanceOf(Cap::class, $cap);
    }

    public function testCanCreateCapWithCustomConfig(): void
    {
        $config = [
            'challengeCount' => 5,
            'challengeSize' => 32,
            'challengeDifficulty' => 3,
            'challengeExpires' => 300,
            'tokenExpires' => 600
        ];
        
        $cap = $this->createCapInstance($config);
        $retrievedConfig = $cap->getConfig();
        
        $this->assertEquals(5, $retrievedConfig['challengeCount']);
        $this->assertEquals(32, $retrievedConfig['challengeSize']);
        $this->assertEquals(3, $retrievedConfig['challengeDifficulty']);
        $this->assertEquals(300, $retrievedConfig['challengeExpires']);
        $this->assertEquals(600, $retrievedConfig['tokenExpires']);
    }

    public function testGetConfigReturnsCorrectConfiguration(): void
    {
        $cap = $this->createCapInstance();
        $config = $cap->getConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('challengeCount', $config);
        $this->assertArrayHasKey('challengeSize', $config);
        $this->assertArrayHasKey('challengeDifficulty', $config);
        $this->assertArrayHasKey('challengeExpires', $config);
        $this->assertArrayHasKey('tokenExpires', $config);
        $this->assertArrayHasKey('tokenVerifyOnce', $config);
        $this->assertArrayHasKey('rateLimitRps', $config);
        $this->assertArrayHasKey('rateLimitBurst', $config);
    }

    public function testGetStatsReturnsStorageInformation(): void
    {
        $cap = $this->createCapInstance();
        $stats = $cap->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('storage_type', $stats);
        $this->assertArrayHasKey('rate_limiter_enabled', $stats);
        $this->assertArrayHasKey('config', $stats);
    }

    // ======================
    // createChallenge Tests
    // ======================

    public function testCreateChallengeReturnsValidResponse(): void
    {
        $cap = $this->createCapInstance();
        $challenge = $cap->createChallenge();
        
        $this->assertValidChallengeResponse($challenge, true);
    }

    public function testCreateChallengeWithCustomConfiguration(): void
    {
        $cap = $this->createCapInstance();
        $conf = [
            'challengeCount' => 3,
            'challengeSize' => 12,
            'challengeDifficulty' => 1,
            'challengeExpires' => 300
        ];
        
        $challenge = $cap->createChallenge($conf);
        
        $this->assertValidChallengeResponse($challenge, true);
        $this->assertCount(3, $challenge['challenge']);
        
        // 验证挑战的大小和格式
        foreach ($challenge['challenge'] as $challengeItem) {
            $this->assertEquals(12, strlen($challengeItem[0])); // salt长度
            $this->assertEquals(1, strlen($challengeItem[1])); // target长度（难度1）
        }
    }

    public function testCreateChallengeWithoutStoring(): void
    {
        $cap = $this->createCapInstance();
        $conf = ['store' => false];
        
        $challenge = $cap->createChallenge($conf);
        
        $this->assertValidChallengeResponse($challenge, false);
        $this->assertArrayNotHasKey('token', $challenge);
    }

    public function testCreateChallengeWithInvalidParameters(): void
    {
        $cap = $this->createCapInstance();
        
        $invalidConfigs = [
            ['challengeCount' => 0],
            ['challengeSize' => -1],
            ['challengeDifficulty' => 0],
            ['challengeExpires' => -10]
        ];
        
        foreach ($invalidConfigs as $invalidConfig) {
            $this->expectException(CapException::class);
            $this->expectExceptionCode(CapException::INVALID_CHALLENGE);
            $cap->createChallenge($invalidConfig);
        }
    }

    public function testCreateChallengeWithRateLimiting(): void
    {
        $config = [
            'rateLimitRps' => 2,
            'rateLimitBurst' => 3
        ];
        $cap = $this->createCapInstance($config);
        $identifier = 'test_user';
        
        // 前3个请求应该被允许（burst容量）
        for ($i = 0; $i < 3; $i++) {
            $challenge = $cap->createChallenge(null, $identifier);
            $this->assertValidChallengeResponse($challenge, true);
        }
        
        // 第4个请求应该被限制
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::RATE_LIMITED);
        $cap->createChallenge(null, $identifier);
    }

    public function testCreateChallengePerformsCleanup(): void
    {
        $mockStorage = new MockStorage();
        $cap = $this->createCapInstance(null, $mockStorage);
        
        $cap->createChallenge();
        
        $counts = $mockStorage->getOperationCounts();
        $this->assertGreaterThan(0, $counts['cleanup']);
    }

    public function testCreateChallengeFailsWhenStorageFails(): void
    {
        $mockStorage = new MockStorage();
        $mockStorage->setShouldFailOnSet(true);
        $cap = $this->createCapInstance(null, $mockStorage);
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::STORAGE_ERROR);
        $cap->createChallenge();
    }

    // ======================
    // redeemChallenge Tests
    // ======================

    public function testRedeemChallengeWithValidSolution(): void
    {
        $cap = $this->createCapInstance();
        
        // 创建挑战
        $challenge = $cap->createChallenge();
        
        // 生成有效解决方案
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        
        // 提交解决方案
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        
        $response = $cap->redeemChallenge($solution);
        
        $this->assertValidRedeemResponse($response);
    }

    public function testRedeemChallengeWithCapJs025Format(): void
    {
        $cap = $this->createCapInstance();
        
        // 创建挑战
        $challenge = $cap->createChallenge();
        
        // 生成cap.js 0.1.25格式的解决方案
        $solutions = $this->generateCapJs025Solutions($challenge['challenge']);
        
        // 提交解决方案
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        
        $response = $cap->redeemChallenge($solution);
        
        $this->assertValidRedeemResponse($response);
    }

    public function testRedeemChallengeWithInvalidSolution(): void
    {
        $cap = $this->createCapInstance();
        
        // 创建挑战
        $challenge = $cap->createChallenge();
        
        // 生成无效解决方案
        $solutions = $this->generateInvalidSolutions($challenge['challenge']);
        
        // 提交解决方案
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::INVALID_SOLUTIONS);
        $cap->redeemChallenge($solution);
    }

    public function testRedeemChallengeWithMissingToken(): void
    {
        $cap = $this->createCapInstance();
        
        $solution = [
            'solutions' => [['salt', 'target', 123]]
        ];
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::INVALID_CHALLENGE);
        $cap->redeemChallenge($solution);
    }

    public function testRedeemChallengeWithMissingSolutions(): void
    {
        $cap = $this->createCapInstance();
        
        $solution = [
            'token' => 'test_token'
        ];
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::INVALID_CHALLENGE);
        $cap->redeemChallenge($solution);
    }

    public function testRedeemChallengeWithNonExistentToken(): void
    {
        $cap = $this->createCapInstance();
        
        $solution = [
            'token' => 'non_existent_token',
            'solutions' => [['salt', 'target', 123]]
        ];
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::CHALLENGE_EXPIRED);
        $cap->redeemChallenge($solution);
    }

    public function testRedeemChallengeWithExpiredToken(): void
    {
        $mockStorage = new MockStorage();
        $cap = $this->createCapInstance(null, $mockStorage);
        
        // 手动设置过期的挑战
        $expiredToken = 'expired_token';
        $mockStorage->setExpiredChallenge($expiredToken);
        
        $solution = [
            'token' => $expiredToken,
            'solutions' => [['salt', 'target', 123]]
        ];
        
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::CHALLENGE_EXPIRED);
        $cap->redeemChallenge($solution);
    }

    public function testRedeemChallengeWithRateLimiting(): void
    {
        $config = [
            'rateLimitRps' => 1,
            'rateLimitBurst' => 1  // 只允许1个突发请求
        ];
        $cap = $this->createCapInstance($config);
        $identifier = 'test_user';
        
        // 创建第一个挑战（应该成功）
        $challenge1 = $cap->createChallenge(null, $identifier);
        $this->assertValidChallengeResponse($challenge1, true);
        
        // 尝试立即创建第二个挑战，应该被限制
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::RATE_LIMITED);
        $cap->createChallenge(null, $identifier);
    }

    public function testRedeemChallengeTokenIsConsumed(): void
    {
        $cap = $this->createCapInstance();
        
        // 创建挑战
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        
        // 第一次赎回应该成功
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $response = $cap->redeemChallenge($solution);
        $this->assertValidRedeemResponse($response);
        
        // 第二次使用相同token应该失败
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::CHALLENGE_EXPIRED);
        $cap->redeemChallenge($solution);
    }

    // ======================
    // validateToken Tests
    // ======================

    public function testValidateTokenWithValidToken(): void
    {
        $cap = $this->createCapInstance();
        
        // 完整流程：创建挑战 -> 赎回 -> 验证
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution);
        
        // 验证token
        $validation = $cap->validateToken($redeemResponse['token']);
        
        $this->assertValidTokenValidationResponse($validation, true);
    }

    public function testValidateTokenWithInvalidToken(): void
    {
        $cap = $this->createCapInstance();
        
        $validation = $cap->validateToken('invalid_token');
        
        $this->assertValidTokenValidationResponse($validation, false);
        $this->assertEquals('Invalid token format', $validation['message']);
    }

    public function testValidateTokenWithNonExistentToken(): void
    {
        $cap = $this->createCapInstance();
        
        $validation = $cap->validateToken('valid_id:non_existent_token');
        
        $this->assertValidTokenValidationResponse($validation, false);
        $this->assertEquals('Token not found', $validation['message']);
    }

    public function testValidateTokenWithExpiredToken(): void
    {
        $mockStorage = new MockStorage();
        $cap = $this->createCapInstance(null, $mockStorage);
        
        // 手动设置过期的token
        $expiredKey = 'expired_id:expired_hash';
        $mockStorage->setExpiredToken($expiredKey);
        
        $validation = $cap->validateToken('expired_id:expired_token');
        
        $this->assertValidTokenValidationResponse($validation, false);
        $this->assertEquals('Token not found', $validation['message']);
    }

    public function testValidateTokenOnceOnly(): void
    {
        $config = ['tokenVerifyOnce' => true];
        $cap = $this->createCapInstance($config);
        
        // 完整流程
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution);
        
        // 第一次验证应该成功
        $validation1 = $cap->validateToken($redeemResponse['token']);
        $this->assertValidTokenValidationResponse($validation1, true);
        
        // 第二次验证应该失败（一次性验证）
        $validation2 = $cap->validateToken($redeemResponse['token']);
        $this->assertValidTokenValidationResponse($validation2, false);
    }

    public function testValidateTokenKeepToken(): void
    {
        $config = ['tokenVerifyOnce' => false];
        $cap = $this->createCapInstance($config);
        
        // 完整流程
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution);
        
        // 多次验证都应该成功
        for ($i = 0; $i < 3; $i++) {
            $validation = $cap->validateToken($redeemResponse['token']);
            $this->assertValidTokenValidationResponse($validation, true);
        }
    }

    public function testValidateTokenWithCustomKeepTokenConfig(): void
    {
        $cap = $this->createCapInstance();
        
        // 完整流程
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution);
        
        // 第一次验证，保留token
        $validation1 = $cap->validateToken($redeemResponse['token'], ['keepToken' => true]);
        $this->assertValidTokenValidationResponse($validation1, true);
        
        // 第二次验证应该仍然成功
        $validation2 = $cap->validateToken($redeemResponse['token'], ['keepToken' => true]);
        $this->assertValidTokenValidationResponse($validation2, true);
        
        // 第三次验证，不保留token
        $validation3 = $cap->validateToken($redeemResponse['token'], ['keepToken' => false]);
        $this->assertValidTokenValidationResponse($validation3, true);
        
        // 第四次验证应该失败
        $validation4 = $cap->validateToken($redeemResponse['token']);
        $this->assertValidTokenValidationResponse($validation4, false);
    }

    public function testValidateTokenWithRateLimiting(): void
    {
        $config = [
            'rateLimitRps' => 2,
            'rateLimitBurst' => 3
        ];
        $cap = $this->createCapInstance($config);
        $identifier = 'test_user';
        
        // 先创建挑战和赎回（在burst限制内）
        $challenge = $cap->createChallenge(null, $identifier);
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution, $identifier);
        
        // 只做一次验证，因为我们已经消耗了大量限制
        $validation = $cap->validateToken($redeemResponse['token'], ['keepToken' => true], $identifier);
        $this->assertValidTokenValidationResponse($validation, true);
        
        // 现在尝试触发限制（通过创建新挑战）
        $this->expectException(CapException::class);
        $this->expectExceptionCode(CapException::RATE_LIMITED);
        $cap->createChallenge(null, $identifier);
    }

    // ======================
    // Cleanup Tests
    // ======================

    public function testCleanupReturnsTrue(): void
    {
        $cap = $this->createCapInstance();
        $result = $cap->cleanup();
        
        $this->assertTrue($result);
    }

    public function testCleanupHandlesStorageFailure(): void
    {
        $mockStorage = new MockStorage();
        $mockStorage->setShouldFailOnCleanup(true);
        $cap = $this->createCapInstance(null, $mockStorage);
        
        $result = $cap->cleanup();
        
        $this->assertFalse($result);
    }

    // ======================
    // Edge Cases and Error Handling
    // ======================

    public function testHandlesStorageNotAvailable(): void
    {
        $mockStorage = new MockStorage();
        $mockStorage->setAvailable(false);
        $cap = $this->createCapInstance(null, $mockStorage);
        
        // 当存储不可用时，操作仍应正常进行（使用内存存储作为回退）
        $challenge = $cap->createChallenge();
        $this->assertValidChallengeResponse($challenge, true);
    }

    public function testIntegrationWithFileStorage(): void
    {
        $tempFile = $this->getTempFilePath();
        $cap = $this->createCapWithFileStorage($tempFile);
        
        // 完整流程测试
        $challenge = $cap->createChallenge();
        $solutions = $this->generateValidSolutions($challenge['challenge']);
        $solution = [
            'token' => $challenge['token'],
            'solutions' => $solutions
        ];
        $redeemResponse = $cap->redeemChallenge($solution);
        $validation = $cap->validateToken($redeemResponse['token']);
        
        $this->assertValidChallengeResponse($challenge, true);
        $this->assertValidRedeemResponse($redeemResponse);
        $this->assertValidTokenValidationResponse($validation, true);
        
        // 清理
        $this->cleanupTestFile($tempFile);
    }

    public function testMultipleUsersIndependence(): void
    {
        $cap = $this->createCapInstance();
        
        // 为多个用户创建挑战
        $users = ['user1', 'user2', 'user3'];
        $responses = [];
        
        foreach ($users as $user) {
            $challenge = $cap->createChallenge(null, $user);
            $solutions = $this->generateValidSolutions($challenge['challenge']);
            $solution = [
                'token' => $challenge['token'],
                'solutions' => $solutions
            ];
            $redeemResponse = $cap->redeemChallenge($solution, $user);
            $responses[$user] = $redeemResponse;
        }
        
        // 验证每个用户的token都是独立的
        foreach ($users as $user) {
            $validation = $cap->validateToken($responses[$user]['token'], null, $user);
            $this->assertValidTokenValidationResponse($validation, true);
        }
    }

    public function testConcurrentChallengeCreation(): void
    {
        $cap = $this->createCapInstance();
        $challenges = [];
        
        // 模拟并发创建挑战
        for ($i = 0; $i < 10; $i++) {
            $challenge = $cap->createChallenge();
            $challenges[] = $challenge;
        }
        
        // 验证所有挑战都是唯一的
        $tokens = array_column($challenges, 'token');
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(10, $uniqueTokens, 'All challenge tokens should be unique');
        
        // 验证所有挑战都有效
        foreach ($challenges as $challenge) {
            $this->assertValidChallengeResponse($challenge, true);
        }
    }

    public function testLargeScaleOperations(): void
    {
        $cap = $this->createCapInstance();
        $count = 100;
        
        $startTime = microtime(true);
        
        // 大量操作
        for ($i = 0; $i < $count; $i++) {
            $challenge = $cap->createChallenge();
            $solutions = $this->generateValidSolutions($challenge['challenge']);
            $solution = [
                'token' => $challenge['token'],
                'solutions' => $solutions
            ];
            $redeemResponse = $cap->redeemChallenge($solution);
            $validation = $cap->validateToken($redeemResponse['token']);
            
            $this->assertValidTokenValidationResponse($validation, true);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // 性能应该在合理范围内
        $this->assertLessThan(30, $duration, "Large scale operations took too long: {$duration}s");
    }
}