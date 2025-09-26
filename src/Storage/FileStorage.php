<?php

namespace Capito\CapPhpServer\Storage;

use Exception;
use Capito\CapPhpServer\Interfaces\StorageInterface;

/**
 * File-based Storage Adapter for Cap Server
 * Provides file-based persistence for tokens and challenges
 * Implements StorageInterface for unified storage access
 */
class FileStorage implements StorageInterface
{
    private string $filePath;
    private array $state = ['challengesList' => [], 'tokensList' => []];

    public function __construct(array $config)
    {
        $this->filePath = $config['path'] ?? '.data/cap_storage.json';
        if (!is_dir(dirname($this->filePath))) {
            mkdir(dirname($this->filePath), 0755, true);
        }
        $this->loadStateFromFile();
    }
    
    /**
     * Set challenge data with expiration and full data.
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @param array $data Full challenge data
     * @return bool
     */
    public function setChallenge(string $token, int $expiresTs, array $data): bool
    {
        try {
            $this->state['challengesList'][$token] = $data;
            $this->saveStateToFile();
            return true;
        } catch (Exception $e) {
            error_log("FileStorage: Failed to set challenge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the full challenge data without deleting it.
     * @param string $token Challenge token
     * @return array|null Full challenge data array or null if not found
     */
    public function getChallenge(string $token): ?array
    {
        try {
            if (!isset($this->state['challengesList'][$token])) {
                return null;
            }
            return $this->state['challengesList'][$token];
        } catch (Exception $e) {
            error_log("FileStorage: Failed to get challenge: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Sets a new verification token and removes the old challenge token.
     * @param string $token New verification token key.
     * @param int $expiresTs Expiration timestamp (seconds).
     * @param string $challengeToken The old challenge token to remove.
     * @return bool
     */
    public function setToken(string $token, int $expiresTs, string $challengeToken): bool
    {
        try {
            // Unset the old challenge token
            if (isset($this->state['challengesList'][$challengeToken])) {
                unset($this->state['challengesList'][$challengeToken]);
            }
            // Set the new verification token
            $this->state['tokensList'][$token] = $expiresTs * 1000;
            $this->saveStateToFile();
            return true;
        } catch (Exception $e) {
            error_log("FileStorage: Failed to set token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get verification token expiration time.
     * @param string $token Token key (id:hash format)
     * @param bool $delete Whether to delete the specific token after retrieval.
     * @param bool $cleanup Whether to perform a full cleanup of all expired data after retrieval.
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $token, bool $delete = false, bool $cleanup = false): ?int
    {
        try {
            if ($cleanup) {
                $this->_performCleanup();
            }
            if (!isset($this->state['tokensList'][$token])) {
                return null;
            }
            $expires = $this->state['tokensList'][$token];
            $changed = false;
            if ($delete) {
                unset($this->state['tokensList'][$token]);
                $changed = true;
            }
            if ($changed) {
                $this->saveStateToFile();
            }
            return (int)($expires / 1000);
        } catch (Exception $e) {
            error_log("FileStorage: Failed to get token: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up expired items.
     * @return bool Whether cleanup was successful
     */
    public function cleanup(): bool
    {
        try {
            if ($this->_performCleanup()) {
                return $this->saveStateToFile();
            }
            return false; // No cleanup performed
        } catch (Exception $e) {
            error_log("FileStorage: Cleanup failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * A private helper method to perform the core cleanup logic.
     * @return bool Returns true if any items were cleaned.
     */
    private function _performCleanup(): bool
    {
        $this->loadStateFromFile();
        $cleaned = false;
        $now = microtime(true) * 1000;
        foreach ($this->state['challengesList'] as $token => $data) {
            if ($data['expires'] < $now) {
                unset($this->state['challengesList'][$token]);
                $cleaned = true;
            }
        }
        foreach ($this->state['tokensList'] as $key => $expires) {
            if ($expires < $now) {
                unset($this->state['tokensList'][$key]);
                $cleaned = true;
            }
        }
        return $cleaned;
    }

    /**
     * Check if storage is available.
     * @return bool
     */
    public function isAvailable(): bool
    {
        return is_writable(dirname($this->filePath));
    }
    
    /**
     * Private helper to load state from file.
     */
    private function loadStateFromFile(): void
    {
        if (file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);
            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $this->state = $decoded;
                }
            }
        }
    }

    /**
     * Private helper to save state to file.
     * @return bool
     */
    private function saveStateToFile(): bool
    {
        return file_put_contents($this->filePath, json_encode($this->state, JSON_PRETTY_PRINT)) !== false;
    }
}
