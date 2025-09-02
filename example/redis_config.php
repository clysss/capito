<?php

/**
 * Redis Configuration Example for Cap PHP Server
 * 
 * This file demonstrates how to configure Redis storage for the Cap server.
 * Copy this file and modify the Redis connection settings as needed.
 */

return [
    // Redis connection configuration
    'redis' => [
        'host' => '127.0.0.1',      // Redis server host
        'port' => 6379,            // Redis server port
        'password' => null,         // Redis password (if required)
        'database' => 0,            // Redis database number
        'timeout' => 2.0,           // Connection timeout in seconds
        'prefix' => 'cap:',         // Key prefix for Redis keys
    ],
    
    // File storage configuration (fallback)
    'tokensStorePath' => __DIR__ . '/example_tokens.json',
    'noFSState' => false,
];

/**
 * Usage Example:
 * 
 * $config = require __DIR__ . '/redis_config.php';
 * $capServer = new CapServer\Cap($config);
 * 
 * This will automatically use Redis if available, falling back to file storage
 * if Redis connection fails.
 */

/**
 * Redis Server Setup:
 * 
 * 1. Install Redis server:
 *    Ubuntu/Debian: sudo apt-get install redis-server
 *    CentOS/RHEL: sudo yum install redis
 *    macOS: brew install redis
 * 
 * 2. Start Redis server:
 *    sudo systemctl start redis-server
 *    or: redis-server
 * 
 * 3. Verify Redis is running:
 *    redis-cli ping  # Should return "PONG"
 */
?>