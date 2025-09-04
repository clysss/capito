<?php

namespace Sparkinzy\CapPhpServer\Tests\Unit\Exceptions;

use Sparkinzy\CapPhpServer\Tests\Helpers\TestCase;
use Sparkinzy\CapPhpServer\Exceptions\CapException;

/**
 * CapException unit tests
 * Cap异常类单元测试
 * 
 * 测试异常处理、错误码和错误消息
 */
class CapExceptionTest extends TestCase
{
    public function testExtendsException(): void
    {
        $exception = new CapException(CapException::INVALID_CHALLENGE);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testConstructorWithCode(): void
    {
        $exception = new CapException(CapException::INVALID_CHALLENGE);
        
        $this->assertEquals(CapException::INVALID_CHALLENGE, $exception->getCode());
        $this->assertEquals('Invalid challenge body', $exception->getMessage());
    }

    public function testConstructorWithCustomMessage(): void
    {
        $customMessage = 'Custom error message';
        $exception = new CapException(CapException::INVALID_CHALLENGE, $customMessage);
        
        $this->assertEquals(CapException::INVALID_CHALLENGE, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testConstructorWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new CapException(CapException::STORAGE_ERROR, null, $previous);
        
        $this->assertEquals(CapException::STORAGE_ERROR, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testAllErrorCodes(): void
    {
        $expectedCodes = [
            CapException::INVALID_CHALLENGE,
            CapException::CHALLENGE_EXPIRED,
            CapException::INVALID_SOLUTIONS,
            CapException::STORAGE_ERROR,
            CapException::RATE_LIMITED,
            CapException::GENERATE_FAILED,
            CapException::STORAGE_NOT_DEFINED
        ];

        foreach ($expectedCodes as $code) {
            $exception = new CapException($code);
            $this->assertEquals($code, $exception->getCode());
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    public function testAllErrorMessages(): void
    {
        $expectedMessages = [
            CapException::INVALID_CHALLENGE => 'Invalid challenge body',
            CapException::CHALLENGE_EXPIRED => 'Challenge expired',
            CapException::INVALID_SOLUTIONS => 'Invalid solutions',
            CapException::STORAGE_ERROR => 'Storage operation failed',
            CapException::RATE_LIMITED => 'Rate limit exceeded',
            CapException::GENERATE_FAILED => 'Generate random string failed',
            CapException::STORAGE_NOT_DEFINED => 'Storage not defined'
        ];

        foreach ($expectedMessages as $code => $expectedMessage) {
            $exception = new CapException($code);
            $this->assertEquals($expectedMessage, $exception->getMessage());
        }
    }

    public function testInvalidChallengeStaticMethod(): void
    {
        $exception = CapException::invalidChallenge();
        
        $this->assertEquals(CapException::INVALID_CHALLENGE, $exception->getCode());
        $this->assertEquals('Invalid challenge body', $exception->getMessage());
    }

    public function testInvalidChallengeWithCustomMessage(): void
    {
        $customMessage = 'Custom invalid challenge message';
        $exception = CapException::invalidChallenge($customMessage);
        
        $this->assertEquals(CapException::INVALID_CHALLENGE, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testChallengeExpiredStaticMethod(): void
    {
        $exception = CapException::challengeExpired();
        
        $this->assertEquals(CapException::CHALLENGE_EXPIRED, $exception->getCode());
        $this->assertEquals('Challenge expired', $exception->getMessage());
    }

    public function testChallengeExpiredWithCustomMessage(): void
    {
        $customMessage = 'Custom expired message';
        $exception = CapException::challengeExpired($customMessage);
        
        $this->assertEquals(CapException::CHALLENGE_EXPIRED, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testInvalidSolutionsStaticMethod(): void
    {
        $exception = CapException::invalidSolutions();
        
        $this->assertEquals(CapException::INVALID_SOLUTIONS, $exception->getCode());
        $this->assertEquals('Invalid solutions', $exception->getMessage());
    }

    public function testInvalidSolutionsWithCustomMessage(): void
    {
        $customMessage = 'Custom invalid solutions message';
        $exception = CapException::invalidSolutions($customMessage);
        
        $this->assertEquals(CapException::INVALID_SOLUTIONS, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testStorageErrorStaticMethod(): void
    {
        $exception = CapException::storageError();
        
        $this->assertEquals(CapException::STORAGE_ERROR, $exception->getCode());
        $this->assertEquals('Storage operation failed', $exception->getMessage());
    }

    public function testStorageErrorWithCustomMessage(): void
    {
        $customMessage = 'Custom storage error message';
        $exception = CapException::storageError($customMessage);
        
        $this->assertEquals(CapException::STORAGE_ERROR, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testRateLimitedStaticMethod(): void
    {
        $exception = CapException::rateLimited();
        
        $this->assertEquals(CapException::RATE_LIMITED, $exception->getCode());
        $this->assertEquals('Rate limit exceeded', $exception->getMessage());
    }

    public function testRateLimitedWithCustomMessage(): void
    {
        $customMessage = 'Custom rate limit message';
        $exception = CapException::rateLimited($customMessage);
        
        $this->assertEquals(CapException::RATE_LIMITED, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testGenerateFailedStaticMethod(): void
    {
        $exception = CapException::generateFailed();
        
        $this->assertEquals(CapException::GENERATE_FAILED, $exception->getCode());
        $this->assertEquals('Generate random string failed', $exception->getMessage());
    }

    public function testGenerateFailedWithCustomMessage(): void
    {
        $customMessage = 'Custom generate failed message';
        $exception = CapException::generateFailed($customMessage);
        
        $this->assertEquals(CapException::GENERATE_FAILED, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testStorageNotDefinedStaticMethod(): void
    {
        $exception = CapException::storageNotDefined();
        
        $this->assertEquals(CapException::STORAGE_NOT_DEFINED, $exception->getCode());
        $this->assertEquals('Storage not defined', $exception->getMessage());
    }

    public function testStorageNotDefinedWithCustomMessage(): void
    {
        $customMessage = 'Custom storage not defined message';
        $exception = CapException::storageNotDefined($customMessage);
        
        $this->assertEquals(CapException::STORAGE_NOT_DEFINED, $exception->getCode());
        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testUnknownErrorCode(): void
    {
        $unknownCode = 999;
        $exception = new CapException($unknownCode);
        
        $this->assertEquals($unknownCode, $exception->getCode());
        $this->assertEquals('Unknown error', $exception->getMessage());
    }

    public function testErrorCodeConstants(): void
    {
        // 验证错误码常量的值
        $this->assertEquals(1, CapException::INVALID_CHALLENGE);
        $this->assertEquals(2, CapException::CHALLENGE_EXPIRED);
        $this->assertEquals(3, CapException::INVALID_SOLUTIONS);
        $this->assertEquals(4, CapException::STORAGE_ERROR);
        $this->assertEquals(5, CapException::RATE_LIMITED);
        $this->assertEquals(6, CapException::GENERATE_FAILED);
        $this->assertEquals(7, CapException::STORAGE_NOT_DEFINED);
    }

    public function testMessagesConstant(): void
    {
        $messages = CapException::MESSAGES;
        
        $this->assertIsArray($messages);
        $this->assertArrayHasKey(CapException::INVALID_CHALLENGE, $messages);
        $this->assertArrayHasKey(CapException::CHALLENGE_EXPIRED, $messages);
        $this->assertArrayHasKey(CapException::INVALID_SOLUTIONS, $messages);
        $this->assertArrayHasKey(CapException::STORAGE_ERROR, $messages);
        $this->assertArrayHasKey(CapException::RATE_LIMITED, $messages);
        $this->assertArrayHasKey(CapException::GENERATE_FAILED, $messages);
        $this->assertArrayHasKey(CapException::STORAGE_NOT_DEFINED, $messages);
    }

    public function testExceptionIsCatchable(): void
    {
        $caught = false;
        
        try {
            throw CapException::invalidChallenge('Test exception');
        } catch (CapException $e) {
            $caught = true;
            $this->assertEquals(CapException::INVALID_CHALLENGE, $e->getCode());
            $this->assertEquals('Test exception', $e->getMessage());
        }
        
        $this->assertTrue($caught, 'Exception should be catchable');
    }

    public function testExceptionIsCatchableAsBaseException(): void
    {
        $caught = false;
        
        try {
            throw CapException::storageError('Test storage error');
        } catch (\Exception $e) {
            $caught = true;
            $this->assertInstanceOf(CapException::class, $e);
            $this->assertEquals(CapException::STORAGE_ERROR, $e->getCode());
        }
        
        $this->assertTrue($caught, 'Exception should be catchable as base Exception');
    }

    public function testExceptionStack(): void
    {
        $innerException = new \RuntimeException('Inner error');
        $outerException = new CapException(CapException::STORAGE_ERROR, 'Outer error', $innerException);
        
        $this->assertSame($innerException, $outerException->getPrevious());
        $this->assertEquals('Inner error', $outerException->getPrevious()->getMessage());
    }

    public function testExceptionSerialization(): void
    {
        $exception = CapException::rateLimited('Rate limit test');
        
        $serialized = serialize($exception);
        $unserialized = unserialize($serialized);
        
        $this->assertEquals($exception->getCode(), $unserialized->getCode());
        $this->assertEquals($exception->getMessage(), $unserialized->getMessage());
    }

    public function testExceptionToString(): void
    {
        $exception = CapException::invalidSolutions('Test solutions error');
        $string = (string)$exception;
        
        $this->assertNotFalse(strpos($string, 'CapException'));
        $this->assertNotFalse(strpos($string, 'Test solutions error'));
        $this->assertNotFalse(strpos($string, (string)CapException::INVALID_SOLUTIONS));
    }

    public function testExceptionTrace(): void
    {
        $exception = CapException::challengeExpired();
        $trace = $exception->getTrace();
        
        $this->assertIsArray($trace);
        $this->assertNotEmpty($trace);
        
        // 验证堆栈跟踪包含当前测试方法
        $found = false;
        foreach ($trace as $frame) {
            if (isset($frame['function']) && $frame['function'] === 'testExceptionTrace') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Stack trace should contain current test method');
    }

    public function testMultipleExceptionsOfSameType(): void
    {
        $exception1 = CapException::invalidChallenge('First error');
        $exception2 = CapException::invalidChallenge('Second error');
        
        $this->assertEquals($exception1->getCode(), $exception2->getCode());
        $this->assertNotEquals($exception1->getMessage(), $exception2->getMessage());
        $this->assertEquals('First error', $exception1->getMessage());
        $this->assertEquals('Second error', $exception2->getMessage());
    }

    public function testExceptionWithSpecialCharacters(): void
    {
        $specialMessage = "Error with special chars: 🚀 中文 'quotes' \"double\" \n\t";
        $exception = CapException::storageError($specialMessage);
        
        $this->assertEquals($specialMessage, $exception->getMessage());
    }

    public function testExceptionWithVeryLongMessage(): void
    {
        $longMessage = str_repeat('This is a very long error message. ', 1000);
        $exception = CapException::generateFailed($longMessage);
        
        $this->assertEquals($longMessage, $exception->getMessage());
        $this->assertGreaterThan(30000, strlen($exception->getMessage()));
    }

    public function testExceptionWithEmptyMessage(): void
    {
        $exception = CapException::storageNotDefined('');
        
        $this->assertEquals('', $exception->getMessage());
        $this->assertEquals(CapException::STORAGE_NOT_DEFINED, $exception->getCode());
    }

    public function testExceptionWithNullMessage(): void
    {
        $exception = CapException::rateLimited(null);
        
        $this->assertEquals('Rate limit exceeded', $exception->getMessage());
        $this->assertEquals(CapException::RATE_LIMITED, $exception->getCode());
    }
}