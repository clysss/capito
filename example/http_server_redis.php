<?php

require_once __DIR__ . '/../src/Cap.php';
require_once __DIR__ . '/../src/RedisStorage.php';

use Sparkinzy\CapPhpServer\Cap;

// Redis configuration - modify these settings according to your Redis setup
$redisConfig = [
    'host' => '127.0.0.1',      // Redis server host
    'port' => 6379,            // Redis server port
    'password' => null,        // Redis password (if required)
    'database' => 1,            // Redis database number
    'timeout' => 2.0,          // Connection timeout in seconds
    'prefix' => 'cap:'         // Key prefix for Redis keys
];

// Initialize the cap server with Redis storage
$config = [
    'redis' => $redisConfig,
    'tokensStorePath' => __DIR__ . '/example_tokens_redis.json', // Fallback file
    'noFSState' => false
];

$capServer = new Cap($config);

// Set up HTTP routes
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/') {
    homeHandler();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/challenge') {
    handleChallenge($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/redeem') {
    handleVerify($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/validate') {
    handleValidate($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/status') {
    handleStatus($capServer);
} else {
    http_response_code(404);
    echo "Not Found";
}

function homeHandler()
{
    // Serve static files from the static directory
    $staticDir = __DIR__ . '/static/';
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    if ($requestPath === '/') {
        if (file_exists($staticDir . 'index.html')) {
            readfile($staticDir . 'index.html');
        } else {
            echo "<h1>Cap PHP Server with Redis Demo</h1>";
            echo "<p>Using Redis for persistent storage</p>";
            echo "<p>Static files not found. Please make sure the static directory exists.</p>";
        }
        return;
    }

    // Handle other static files
    $filePath = $staticDir . ltrim($requestPath, '/');
    if (file_exists($filePath) && is_file($filePath)) {
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isset($mimeTypes[$extension])) {
            header('Content-Type: ' . $mimeTypes[$extension]);
        }
        
        readfile($filePath);
    } else {
        http_response_code(404);
        echo "Not Found";
    }
}

function handleChallenge(Cap $capServer)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo "Method Not Allowed";
        return;
    }

    header('Content-Type: application/json');

    $config = [
        'challengeCount'      => 50,
        'challengeSize'       => 32,
        'challengeDifficulty' => 4,
        'expiresMs'           => 300000,
        'store'               => true,
    ];

    try {
        $challenge = $capServer->createChallenge($config);
        echo json_encode($challenge);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create challenge: ' . $e->getMessage()]);
    }
}

function handleVerify(Cap $capServer)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo "Method Not Allowed";
        return;
    }

    header('Content-Type: application/json');

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    if (!isset($input['token']) || $input['token'] === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    if (!isset($input['solutions']) || count($input['solutions']) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Solution is required']);
        return;
    }

    try {
        $solution = [
            'token' => $input['token'],
            'solutions' => $input['solutions'],
        ];

        $result = $capServer->redeemChallenge($solution);
        
        $response = [
            'success' => $result['success'],
        ];

        if ($result['success'] && isset($result['token'])) {
            $response['token'] = $result['token'];
        }
        if ($result['success'] && isset($result['expires'])) {
            $response['expires'] = $result['expires'];
        }

        // 记录调试信息
        $logMessage = date('Y-m-d H:i:s') . " - Redis - Token: " . $input['token'] . ", Result: " . json_encode($result) . "\n";
        file_put_contents('debug_redis.log', $logMessage, FILE_APPEND);

        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to redeem challenge: ' . $e->getMessage()]);
    }
}

function handleValidate(Cap $capServer)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo "Method Not Allowed";
        return;
    }

    header('Content-Type: application/json');

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    if (!isset($input['token']) || $input['token'] === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Token is required']);
        return;
    }

    try {
        $result = $capServer->validateToken($input['token'], null);
        
        // 记录调试信息
        $logMessage = date('Y-m-d H:i:s') . " - Redis - Validate Token: " . $input['token'] . ", Result: " . json_encode($result) . "\n";
        file_put_contents('debug_redis.log', $logMessage, FILE_APPEND);
        
        echo json_encode([
            'success' => $result['success'],
            'message' => '1',
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to validate token: ' . $e->getMessage()]);
    }
}

function handleStatus(Cap $capServer)
{
    header('Content-Type: application/json');
    
    $status = [
        'storage' => 'redis',
        'redis_connected' => false,
        'timestamp' => time()
    ];
    
    // Check Redis connection status through reflection
    try {
        $reflection = new ReflectionClass($capServer);
        $redisStorageProperty = $reflection->getProperty('redisStorage');
        $redisStorageProperty->setAccessible(true);
        $redisStorage = $redisStorageProperty->getValue($capServer);
        
        if ($redisStorage !== null && method_exists($redisStorage, 'isConnected')) {
            $status['redis_connected'] = $redisStorage->isConnected();
        }
    } catch (Exception $e) {
        $status['redis_connected'] = false;
        $status['error'] = $e->getMessage();
    }
    
    echo json_encode($status);
}

// Start the server with Redis support
// echo "Starting Redis-enabled server on http://localhost:8082\n";
// echo "Redis storage: " . ($status['redis_connected'] ? 'connected' : 'disconnected') . "\n";
?>