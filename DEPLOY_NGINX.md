# Nginx 部署指南

本文档介绍如何使用 Nginx 部署 Cap PHP Server with Redis。

## 前提条件

- Nginx 已安装并运行
- PHP-FPM 已安装并配置
- Redis 服务器已安装并运行
- Composer 已安装

## 部署步骤

### 1. 安装依赖

```bash
cd /home/sparkinzy/php-work/agreement/cap_php_server
composer install
```

### 2. 配置 Nginx

复制示例配置文件：

```bash
sudo cp nginx.conf.example /etc/nginx/sites-available/cap_php_server
```

编辑配置文件，修改以下内容：
- `server_name`: 改为您的域名或 IP 地址
- `root`: 确认路径正确
- `fastcgi_pass`: 根据您的 PHP 版本调整 socket 路径

启用站点：

```bash
sudo ln -s /etc/nginx/sites-available/cap_php_server /etc/nginx/sites-enabled/
```

### 3. 配置 PHP-FPM

确保 PHP-FPM 已安装并运行：

```bash
sudo systemctl status php8.1-fpm  # 根据您的 PHP 版本调整
```

### 4. 配置 Redis

编辑 Redis 配置（如果需要）：

```bash
# 修改 Redis 配置
sudo nano /etc/redis/redis.conf

# 重启 Redis
sudo systemctl restart redis
```

### 5. 设置文件权限

```bash
# 设置适当的文件权限
sudo chown -R www-data:www-data /home/sparkinzy/php-work/agreement/cap_php_server
sudo chmod -R 755 /home/sparkinzy/php-work/agreement/cap_php_server
```

### 6. 重启 Nginx

```bash
sudo nginx -t  # 测试配置
sudo systemctl restart nginx
```

### 7. 验证部署

访问您的服务器地址，应该看到 Cap PHP Server 的主页。

测试 API 端点：

```bash
# 测试挑战创建
curl -X POST http://your-domain.com/challenge

# 测试状态检查
curl http://your-domain.com/status
```

## 故障排除

### 常见问题

1. **502 Bad Gateway**
   - 检查 PHP-FPM 是否运行
   - 检查 fastcgi_pass 配置是否正确

2. **Redis 连接失败**
   - 检查 Redis 服务器是否运行
   - 检查 Redis 配置中的主机和端口

3. **文件权限问题**
   - 确保 Nginx 用户（通常是 www-data）有读取权限

### 日志检查

```bash
# Nginx 错误日志
sudo tail -f /var/log/nginx/cap_php_server_error.log

# Nginx 访问日志
sudo tail -f /var/log/nginx/cap_php_server_access.log

# PHP-FPM 日志
sudo tail -f /var/log/php8.1-fpm.log
```

## 性能优化

### Nginx 优化

```nginx
# 在 nginx.conf 的 http 块中添加
gzip on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
```

### PHP-FPM 优化

编辑 `/etc/php/8.1/fpm/pool.d/www.conf`：

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 10
```

### Redis 优化

确保 Redis 配置了适当的内存限制和持久化策略。

## 安全考虑

1. **启用 HTTPS**
   - 使用 Let's Encrypt 获取免费 SSL 证书
   - 配置 Nginx 重定向 HTTP 到 HTTPS

2. **防火墙配置**
   - 只开放必要的端口（80, 443）
   - 限制对 Redis 端口的访问

3. **文件权限**
   - 确保配置文件和日志文件有适当的权限
   - 避免将敏感信息存储在 web 可访问的目录中

## 监控和维护

### 监控 Redis

```bash
# 查看 Redis 内存使用
redis-cli info memory

# 查看连接数
redis-cli info clients
```

### 监控 PHP-FPM

```bash
# 查看 PHP-FPM 状态
sudo systemctl status php8.1-fpm

# 查看进程数
ps aux | grep php-fpm
```

### 定期清理

定期清理旧的调试日志和临时文件。

---

如有问题，请检查日志文件或联系系统管理员。