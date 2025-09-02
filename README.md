# sparkinzy/cap_php_server

一个基于PHP的轻量级、现代化的开源CAPTCHA替代方案，使用SHA-256工作量证明机制。

## 特性

- 🚀 高性能：基于SHA-256的工作量证明机制
- 💾 多存储支持：Redis持久化存储 + 文件存储回退
- 🔒 安全性：防重放攻击、一次性token验证
- 📦 标准Composer包：易于集成到任何PHP项目中
- 🧪 完整测试：包含完整的单元测试和集成测试

## 安装

```bash
composer require sparkinzy/cap_php_server
```

## 快速开始

### 基本使用

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Sparkinzy\CapPhpServer\Cap;

// 初始化CAPTCHA服务器
$cap = new Cap([
    'storage' => 'file',
    'file_storage_path' => __DIR__ . '/challenges'
]);

// 创建挑战
$challenge = $cap->createChallenge(5, 300); // 5个挑战项，300秒过期

// 客户端计算解决方案（通常在前端JavaScript中完成）
// 解决方案格式: [salt, target, solutionValue]

// 验证解决方案
$result = $cap->redeemChallenge($challenge['token'], $solution);

if ($result['success']) {
    echo "验证成功！验证token: " . $result['validation_token'];
} else {
    echo "验证失败: " . $result['error'];
}
```

### Redis存储配置

```php
<?php
use Sparkinzy\CapPhpServer\Cap;

$cap = new Cap([
    'storage' => 'redis',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0
    ],
    'file_storage_path' => __DIR__ . '/challenges' // Redis失败时的回退存储
]);
```

## HTTP服务器集成

查看 `example/http_server_redis.php` 获取完整的HTTP服务器实现示例。

## 验证机制

### 挑战创建
1. 服务器生成随机挑战项和token
2. 设置过期时间并存储挑战数据
3. 返回挑战配置给客户端

### 客户端计算
1. 使用暴力破解找到满足SHA-256哈希前缀匹配的solution
2. 解决方案格式必须为 `[salt, target, solutionValue]`

### 服务器验证
1. 验证token和解决方案有效性
2. 检查挑战状态和过期时间
3. 生成验证token用于后续验证

## 配置选项

| 选项 | 类型 | 默认值 | 描述 |
|------|------|--------|------|
| storage | string | 'file' | 存储类型：'file' 或 'redis' |
| file_storage_path | string | './challenges' | 文件存储路径 |
| redis.host | string | '127.0.0.1' | Redis主机地址 |
| redis.port | int | 6379 | Redis端口 |
| redis.password | string|null | null | Redis密码 |
| redis.database | int | 0 | Redis数据库 |

## 性能特点

- **人类用户**: 1-3秒计算时间
- **机器人**: 高计算成本，阻止率 >95%
- **验证成功率**: >99%

## 致谢

本项目受到Go语言版本 [samwafgo/cap_go_server](https://github.com/samwafgo/cap_go_server) 的启发，特此致谢。

## 许可证

Apache-2.0 License

## 作者

sparkinzy (sparkinzy@163.com)