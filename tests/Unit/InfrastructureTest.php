<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Cap;
use Sparkinzy\CapPhpServer\Storage\MemoryStorage;

/**
 * Basic infrastructure test to verify testing setup works correctly
 * 基础设施测试，验证测试设置是否正常工作
 */
class InfrastructureTest extends TestCase
{
    public function testCanCreateCapInstance(): void
    {
        $cap = $this->createCapInstance();
        $this->assertInstanceOf(Cap::class, $cap);
    }

    public function testCanCreateChallengeWithMemoryStorage(): void
    {
        $cap = $this->createCapInstance();
        $challenge = $cap->createChallenge();
        
        $this->assertValidChallengeResponse($challenge, true);
    }

    public function testTestDataFactoryWorks(): void
    {
        $config = \Sparkinzy\CapPhpServer\Tests\Helpers\TestDataFactory::validChallengeConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('challengeCount', $config);
    }

    public function testMockStorageWorks(): void
    {
        $mockStorage = new \Sparkinzy\CapPhpServer\Tests\Helpers\MockStorage();
        $this->assertTrue($mockStorage->isAvailable());
        
        $result = $mockStorage->setChallenge('test_token', time() + 3600);
        $this->assertTrue($result);
        
        $expiresTs = $mockStorage->getChallenge('test_token');
        $this->assertIsInt($expiresTs);
    }

    public function testTempFileCleanupWorks(): void
    {
        $tempFile = $this->getTempFilePath();
        file_put_contents($tempFile, 'test');
        $this->assertFileExists($tempFile);
        
        $this->cleanupTestFile($tempFile);
        $this->assertFileDoesNotExist($tempFile);
    }
}