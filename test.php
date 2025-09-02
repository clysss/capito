<?php

require_once 'src/Cap.php';

use CapServer\Cap;

// Test the Cap PHP implementation
echo "Testing Cap PHP Server Implementation\n";
echo "====================================\n\n";

// Initialize Cap server
$config = [
    'tokensStorePath' => './test_tokens.json',
    'noFSState'       => false,
];

$cap = new Cap($config);

// Test 1: Create Challenge
echo "1. Testing Challenge Creation...\n";
try {
    $challengeConfig = [
        'challengeCount'      => 5,  // Smaller number for testing
        'challengeSize'       => 16,
        'challengeDifficulty' => 2,
        'expiresMs'           => 60000, // 1 minute
        'store'               => true,
    ];
    
    $challenge = $cap->createChallenge($challengeConfig);
    
    echo "   ✓ Challenge created successfully\n";
    echo "   - Token: " . $challenge['token'] . "\n";
    echo "   - Expires: " . date('Y-m-d H:i:s', $challenge['expires'] / 1000) . "\n";
    echo "   - Challenges count: " . count($challenge['challenge']) . "\n";
    
    // Display first challenge
    $firstChallenge = $challenge['challenge'][0];
    echo "   - First challenge: [" . $firstChallenge[0] . ", " . $firstChallenge[1] . "]\n\n";
    
    $testToken = $challenge['token'];
    $testChallenges = $challenge['challenge'];
    
} catch (Exception $e) {
    echo "   ✗ Error creating challenge: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Solve a challenge (simulate client solving)
echo "2. Testing Challenge Solution...\n";
try {
    // Simulate solving one challenge (in real usage, client would solve all)
    $solutions = [];
    foreach ($testChallenges as $challenge) {
        list($salt, $target) = $challenge;
        
        // Simple brute-force solution (for testing only)
        $solutionFound = false;
        for ($nonce = 0; $nonce < 1000; $nonce++) {
            $hash = hash('sha256', $salt . $nonce);
            if (strpos($hash, $target) === 0) {
                $solutions[] = [$salt, $target, $nonce];
                $solutionFound = true;
                break;
            }
        }
        
        if (!$solutionFound) {
            echo "   ✗ Could not find solution for challenge [" . $salt . ", " . $target . "]\n";
            // For testing, we'll just use a dummy solution
            $solutions[] = [$salt, $target, 0];
        }
    }
    
    $solutionData = [
        'token' => $testToken,
        'solutions' => $solutions,
    ];
    
    $result = $cap->redeemChallenge($solutionData);
    
    if ($result['success']) {
        echo "   ✓ Challenge solved successfully\n";
        echo "   - Verification token: " . $result['token'] . "\n";
        echo "   - Expires: " . date('Y-m-d H:i:s', $result['expires'] / 1000) . "\n\n";
        
        $verificationToken = $result['token'];
    } else {
        echo "   ✗ Challenge solution failed: " . $result['message'] . "\n\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "   ✗ Error solving challenge: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 3: Validate token
echo "3. Testing Token Validation...\n";
try {
    $validationResult = $cap->validateToken($verificationToken);
    
    if ($validationResult['success']) {
        echo "   ✓ Token validation successful\n\n";
    } else {
        echo "   ✗ Token validation failed\n\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "   ✗ Error validating token: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 4: Cleanup
echo "4. Testing Cleanup...\n";
try {
    $cleanupResult = $cap->cleanup();
    
    if ($cleanupResult) {
        echo "   ✓ Cleanup completed successfully\n";
    } else {
        echo "   - Cleanup completed (no changes needed)\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Error during cleanup: " . $e->getMessage() . "\n";
    exit(1);
}

// Clean up test file
if (file_exists('./test_tokens.json')) {
    unlink('./test_tokens.json');
}

echo "\n====================================\n";
echo "All tests passed! Cap PHP Server is working correctly.\n";
echo "\nTo run the HTTP server example:\n";
echo "  cd example\n";
echo "  php -S localhost:8080 http_server.php\n";

?>