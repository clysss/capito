<?php

namespace Sparkinzy\CapPhpServer\Tests\Helpers;

/**
 * Factory class for generating test data
 * 用于生成测试数据的工厂类
 */
class TestDataFactory
{
    /**
     * 生成有效的挑战配置
     * @return array
     */
    public static function validChallengeConfig(): array
    {
        return [
            'challengeCount' => 2,
            'challengeSize' => 8,
            'challengeDifficulty' => 1,
            'challengeExpires' => 60,
            'store' => true
        ];
    }

    /**
     * 生成无效的挑战配置集合
     * @return array
     */
    public static function invalidChallengeConfigs(): array
    {
        return [
            'zero_count' => [
                'challengeCount' => 0,
                'challengeSize' => 8,
                'challengeDifficulty' => 1,
                'challengeExpires' => 60
            ],
            'negative_size' => [
                'challengeCount' => 2,
                'challengeSize' => -1,
                'challengeDifficulty' => 1,
                'challengeExpires' => 60
            ],
            'zero_difficulty' => [
                'challengeCount' => 2,
                'challengeSize' => 8,
                'challengeDifficulty' => 0,
                'challengeExpires' => 60
            ],
            'negative_expires' => [
                'challengeCount' => 2,
                'challengeSize' => 8,
                'challengeDifficulty' => 1,
                'challengeExpires' => -10
            ]
        ];
    }

    /**
     * 生成各种格式的解决方案数据用于兼容性测试
     * @param array $challenges 挑战数据
     * @return array
     */
    public static function generateCompatibilitySolutions(array $challenges): array
    {
        $solutions = [];
        
        foreach ($challenges as $index => $challenge) {
            list($salt, $target) = $challenge;
            
            // 找到有效的解决方案值
            $validSolution = null;
            for ($solution = 0; $solution < 100000; $solution++) {
                $hash = hash('sha256', $salt . $solution);
                if (strpos($hash, $target) === 0) {
                    $validSolution = $solution;
                    break;
                }
            }
            
            if ($validSolution !== null) {
                $solutions[] = [
                    'new_format' => [$salt, $target, $validSolution],
                    'old_format' => [$salt, $validSolution],
                    'capjs_025_format' => $validSolution,
                    'capjs_025_index' => $index
                ];
            }
        }
        
        return $solutions;
    }

    /**
     * 生成恶意输入数据用于安全测试
     * @return array
     */
    public static function maliciousInputs(): array
    {
        return [
            'sql_injection' => [
                'token' => "'; DROP TABLE users; --",
                'solutions' => [["test", "00", 123]]
            ],
            'xss_script' => [
                'token' => '<script>alert("xss")</script>',
                'solutions' => [["test", "00", 123]]
            ],
            'null_bytes' => [
                'token' => "test\x00token",
                'solutions' => [["test\x00", "00", 123]]
            ],
            'unicode_overflow' => [
                'token' => str_repeat('🚀', 1000),
                'solutions' => [[str_repeat('🚀', 100), "00", 123]]
            ],
            'extremely_long_string' => [
                'token' => str_repeat('a', 100000),
                'solutions' => [[str_repeat('b', 100000), "00", 123]]
            ],
            'negative_numbers' => [
                'token' => 'valid_token',
                'solutions' => [["salt", "00", -999999]]
            ],
            'float_numbers' => [
                'token' => 'valid_token',
                'solutions' => [["salt", "00", 3.14159]]
            ],
            'array_in_solution' => [
                'token' => 'valid_token',
                'solutions' => [["salt", "00", ["nested", "array"]]]
            ],
            'object_in_solution' => [
                'token' => 'valid_token',
                'solutions' => [["salt", "00", (object)["key" => "value"]]]
            ]
        ];
    }

    /**
     * 生成边界值测试数据
     * @return array
     */
    public static function boundaryValues(): array
    {
        return [
            'min_challenge_count' => 1,
            'max_challenge_count' => 100,
            'min_challenge_size' => 1,
            'max_challenge_size' => 64,
            'min_difficulty' => 1,
            'max_difficulty' => 8,
            'min_expires' => 1,
            'max_expires' => 86400, // 24小时
            'empty_string' => '',
            'whitespace_only' => '   ',
            'max_int' => PHP_INT_MAX,
            'min_int' => PHP_INT_MIN,
            'zero' => 0,
            'null' => null,
            'false' => false,
            'true' => true
        ];
    }

    /**
     * 生成Redis配置用于测试
     * @return array
     */
    public static function redisConfigs(): array
    {
        return [
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 2.5,
                'database' => 0
            ],
            'with_auth' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 2.5,
                'database' => 0,
                'password' => 'test_password'
            ],
            'invalid_host' => [
                'host' => '999.999.999.999',
                'port' => 6379,
                'timeout' => 1,
                'database' => 0
            ],
            'invalid_port' => [
                'host' => '127.0.0.1',
                'port' => 99999,
                'timeout' => 1,
                'database' => 0
            ]
        ];
    }

    /**
     * 生成性能测试配置
     * @return array
     */
    public static function performanceTestConfigs(): array
    {
        return [
            'light_load' => [
                'concurrent_users' => 10,
                'requests_per_user' => 5,
                'challenge_count' => 1,
                'difficulty' => 1
            ],
            'medium_load' => [
                'concurrent_users' => 50,
                'requests_per_user' => 10,
                'challenge_count' => 2,
                'difficulty' => 2
            ],
            'heavy_load' => [
                'concurrent_users' => 100,
                'requests_per_user' => 20,
                'challenge_count' => 3,
                'difficulty' => 2
            ],
            'stress_test' => [
                'concurrent_users' => 200,
                'requests_per_user' => 50,
                'challenge_count' => 5,
                'difficulty' => 3
            ]
        ];
    }

    /**
     * 生成频率限制测试场景
     * @return array
     */
    public static function rateLimitScenarios(): array
    {
        return [
            'normal_usage' => [
                'rps' => 10,
                'burst' => 20,
                'requests' => 15,
                'interval' => 1
            ],
            'burst_usage' => [
                'rps' => 5,
                'burst' => 10,
                'requests' => 15,
                'interval' => 0.1
            ],
            'sustained_overload' => [
                'rps' => 2,
                'burst' => 5,
                'requests' => 20,
                'interval' => 0.1
            ],
            'disabled_rate_limit' => [
                'rps' => 0,
                'burst' => 0,
                'requests' => 100,
                'interval' => 0.01
            ]
        ];
    }
}