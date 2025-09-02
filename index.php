<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Cap.php';
require_once __DIR__ . '/src/RedisStorage.php';

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
    'tokensStorePath' => __DIR__ . '/.data/tokensList.json', // Fallback file
    'noFSState' => false
];

$capServer = new Cap($config);

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Set up HTTP routes
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestPath === '/') {
    homeHandler();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestPath === '/challenge') {
    handleChallenge($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestPath === '/redeem') {
    handleVerify($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestPath === '/validate') {
    handleValidate($capServer);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestPath === '/status') {
    handleStatus($capServer);
} else {
    // For Nginx, handle static files through Nginx configuration
    http_response_code(404);
    echo "Not Found";
}

function homeHandler()
{
    // Serve the main HTML page
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cap PHP Server with Redis</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
        }
        .status {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #4caf50;
        }
        .endpoints {
            background: #f0f8ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #2196f3;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Cap PHP Server with Redis</h1>
        
        <div class="status">
            <h3>🚀 Server Status: Running</h3>
            <p>This server uses Redis for persistent storage of CAPTCHA challenges and tokens.</p>
        </div>
        
        <div class="endpoints">
            <h3>📋 Available Endpoints:</h3>
            <ul>
                <li><strong>POST</strong> <code>/challenge</code> - Create a new CAPTCHA challenge</li>
                <li><strong>POST</strong> <code>/redeem</code> - Redeem a solved challenge</li>
                <li><strong>POST</strong> <code>/validate</code> - Validate a token</li>
                <li><strong>GET</strong> <code>/status</code> - Check server status</li>
            </ul>
        </div>
        
        <div>
            <h3>🔧 Configuration:</h3>
            <p>Redis is configured for persistent storage. Make sure Redis server is running.</p>
        </div>
    </div>
</body>
</html>';
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
        file_put_contents(__DIR__ . '/debug_redis.log', $logMessage, FILE_APPEND);

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
        file_put_contents(__DIR__ . '/debug_redis.log', $logMessage, FILE_APPEND);
        
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

?>