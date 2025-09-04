<?php

namespace Sparkinzy\CapPhpServer\Tests\Helpers;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Sparkinzy\CapPhpServer\Cap;
use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;
use Sparkinzy\CapPhpServer\Storage\MemoryStorage;
use Sparkinzy\CapPhpServer\Storage\FileStorage;

/**
 * Base test case with common utilities for Cap PHP Server tests
 * 为所有测试提供通用的工具方法和设置
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Default test configuration
     * @var array
     */
    protected static array $defaultConfig = [
        'challengeCount' => 2,
        'challengeSize' => 8,
        'challengeDifficulty' => 1, // 降低难度以便快速测试
        'challengeExpires' => 60,   // 1分钟过期
        'tokenExpires' => 120,      // 2分钟过期
        'tokenVerifyOnce' => true,
        'rateLimitRps' => 100,      // 测试时提高限制
        'rateLimitBurst' => 500,
        'autoCleanupInterval' => 5,
        'noFSState' => true         // 默认不使用文件系统
    ];

    /**
     * 创建用于测试的Cap实例
     * @param array|null $config 配置选项
     * @param StorageInterface|null $storage 自定义存储
     * @return Cap
     */
    protected function createCapInstance(?array $config = null, ?StorageInterface $storage = null): Cap
    {
        $finalConfig = array_merge(self::$defaultConfig, $config ?? []);
        
        if ($storage !== null) {
            $finalConfig['storage'] = $storage;
        } else if (!isset($finalConfig['storage'])) {
            // 默认使用内存存储进行测试
            $finalConfig['storage'] = new MemoryStorage();
        }
        
        return new Cap($finalConfig);
    }

    /**
     * 创建文件存储的Cap实例 (用于文件存储测试)
     * @param string|null $filePath 文件路径
     * @param array|null $config 配置选项
     * @return Cap
     */
    protected function createCapWithFileStorage(?string $filePath = null, ?array $config = null): Cap
    {
        $filePath = $filePath ?? $this->getTempFilePath();
        $finalConfig = array_merge(self::$defaultConfig, $config ?? []);
        $finalConfig['storage'] = new FileStorage($filePath);
        $finalConfig['noFSState'] = false;
        
        return new Cap($finalConfig);
    }

    /**
     * 获取临时文件路径用于测试
     * @return string
     */
    protected function getTempFilePath(): string
    {
        return sys_get_temp_dir() . '/cap_test_' . uniqid() . '.json';
    }

    /**
     * 生成有效的解决方案
     * @param array $challenges 挑战数据
     * @return array 解决方案数组
     */
    protected function generateValidSolutions(array $challenges): array
    {
        $solutions = [];
        
        foreach ($challenges as $index => $challenge) {
            list($salt, $target) = $challenge;
            
            // 暴力破解找到有效解决方案
            for ($solution = 0; $solution < 1000000; $solution++) {
                $hash = hash('sha256', $salt . $solution);
                if (strpos($hash, $target) === 0) {
                    $solutions[] = [$salt, $target, $solution];
                    break;
                }
            }
        }
        
        return $solutions;
    }

    /**
     * 生成cap.js 0.1.25格式的解决方案（数字数组）
     * @param array $challenges 挑战数据
     * @return array 解决方案数组
     */
    protected function generateCapJs025Solutions(array $challenges): array
    {
        $solutions = [];
        
        foreach ($challenges as $index => $challenge) {
            list($salt, $target) = $challenge;
            
            // 暴力破解找到有效解决方案
            for ($solution = 0; $solution < 1000000; $solution++) {
                $hash = hash('sha256', $salt . $solution);
                if (strpos($hash, $target) === 0) {
                    $solutions[$index] = $solution; // cap.js 0.1.25格式
                    break;
                }
            }
        }
        
        return $solutions;
    }

    /**
     * 生成无效的解决方案用于测试
     * @param array $challenges 挑战数据
     * @return array 无效解决方案数组
     */
    protected function generateInvalidSolutions(array $challenges): array
    {
        $solutions = [];
        
        foreach ($challenges as $index => $challenge) {
            list($salt, $target) = $challenge;
            // 使用错误的解决方案值
            $solutions[] = [$salt, $target, 999999999];
        }
        
        return $solutions;
    }

    /**
     * 断言挑战响应格式正确
     * @param array $challenge 挑战响应
     * @param bool $shouldHaveToken 是否应该包含token
     */
    protected function assertValidChallengeResponse(array $challenge, bool $shouldHaveToken = true): void
    {
        $this->assertArrayHasKey('challenge', $challenge);
        $this->assertArrayHasKey('expires', $challenge);
        $this->assertIsArray($challenge['challenge']);
        $this->assertIsInt($challenge['expires']);
        $this->assertGreaterThan(time() * 1000, $challenge['expires']);
        
        if ($shouldHaveToken) {
            $this->assertArrayHasKey('token', $challenge);
            $this->assertIsString($challenge['token']);
            $this->assertNotEmpty($challenge['token']);
        }
        
        // 验证挑战格式
        foreach ($challenge['challenge'] as $challengeItem) {
            $this->assertIsArray($challengeItem);
            $this->assertCount(2, $challengeItem);
            $this->assertIsString($challengeItem[0]); // salt
            $this->assertIsString($challengeItem[1]); // target
        }
    }

    /**
     * 断言赎回响应格式正确
     * @param array $response 赎回响应
     */
    protected function assertValidRedeemResponse(array $response): void
    {
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('token', $response);
        $this->assertArrayHasKey('expires', $response);
        $this->assertTrue($response['success']);
        $this->assertIsString($response['token']);
        $this->assertIsInt($response['expires']);
        $this->assertGreaterThan(time() * 1000, $response['expires']);
        
        // 验证token格式 (id:vertoken)
        $this->assertNotFalse(strpos($response['token'], ':'));
        $parts = explode(':', $response['token']);
        $this->assertCount(2, $parts);
        $this->assertNotEmpty($parts[0]); // id
        $this->assertNotEmpty($parts[1]); // vertoken
    }

    /**
     * 断言令牌验证响应格式正确
     * @param array $response 验证响应
     * @param bool $shouldBeValid 是否应该有效
     */
    protected function assertValidTokenValidationResponse(array $response, bool $shouldBeValid = true): void
    {
        $this->assertArrayHasKey('success', $response);
        $this->assertIsBool($response['success']);
        $this->assertEquals($shouldBeValid, $response['success']);
        
        if (!$shouldBeValid) {
            $this->assertArrayHasKey('message', $response);
            $this->assertIsString($response['message']);
        }
    }

    /**
     * 等待指定时间（用于过期测试）
     * @param int $seconds 等待秒数
     */
    protected function waitSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * 清理测试文件
     * @param string $filePath 文件路径
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * setUp方法 - 在每个测试方法之前运行
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 清理可能存在的调试日志文件
        $debugFile = __DIR__ . '/../../debug_capjs_detailed.log';
        if (file_exists($debugFile)) {
            unlink($debugFile);
        }
    }

    /**
     * tearDown方法 - 在每个测试方法之后运行
     */
    protected function tearDown(): void
    {
        // 清理临时文件
        $pattern = sys_get_temp_dir() . '/cap_test_*.json';
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        parent::tearDown();
    }
}