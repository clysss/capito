# 正确的解决方案格式

## 问题发现

在之前的测试中，兑换挑战总是失败，错误信息为 "Invalid solution"。经过分析，发现解决方案格式不正确。

## 正确的解决方案格式

解决方案数组应该包含三个元素：`[salt, target, solution]`，而不是 `[salt, solution]`。

### 错误格式（之前使用的）
```json
{
  "token": "your_token_here",
  "solutions": [
    ["salt1", "solution1"],
    ["salt2", "solution2"],
    // ...
  ]
}
```

### 正确格式
```json
{
  "token": "your_token_here",
  "solutions": [
    ["salt1", "target1", "solution1"],
    ["salt2", "target2", "solution2"],
    // ...
  ]
}
```

## 验证测试

运行 `test_redeem_correct.php` 脚本验证了正确的格式：

```bash
php test_redeem_correct.php
```

输出结果：
```
创建的挑战：
Token: 495abfb980749470835d1626944e9269972c43446450900413

HTTP状态码: 200
服务器响应: {"success":true,"token":"c5d4695a299624e6:4a541c1ca1986652554ea006d4cb5a","expires":1756805073329}
```

## 持久化存储验证

挑战的持久化存储功能已经正常工作，所有创建的挑战都正确保存在 `example_tokens.json` 文件中。

## 总结

1. 解决方案格式必须是 `[salt, target, solution]`
2. 持久化存储功能正常工作
3. 服务器重启后挑战状态能够正确恢复