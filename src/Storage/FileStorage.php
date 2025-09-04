<?php

namespace Sparkinzy\CapPhpServer\Storage;

use Sparkinzy\CapPhpServer\Interfaces\StorageInterface;
use Sparkinzy\CapPhpServer\Exceptions\CapException;

/**
 * File storage implementation
 * Enhanced version of the original file storage with unified interface
 */
class FileStorage implements StorageInterface
{
    private string $filePath;
    private array $data;
    private bool $isLoaded = false;

    /**
     * Create a new file storage instance
     * @param string $filePath Path to storage file
     */
    public function __construct(string $filePath = '.data/tokensList.json')
    {
        $this->filePath = $filePath;
        $this->ensureDirectoryExists();
        $this->loadData();
    }

    /**
     * Set challenge token with expiration
     * @param string $token Challenge token
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setChallenge(string $token, int $expiresTs): bool
    {
        try {
            $this->ensureLoaded();
            $this->data['challengesList'][$token] = [
                'expires' => $expiresTs,
                'token' => $token
            ];
            return $this->saveData();
        } catch (CapException $e) {
            error_log("FileStorage: Failed to set challenge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get challenge expiration time
     * @param string $token Challenge token
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getChallenge(string $token, bool $delete = false): ?int
    {
        try {
            $this->ensureLoaded();
            
            if (!isset($this->data['challengesList'][$token])) {
                return null;
            }

            $expiresTs = $this->data['challengesList'][$token]['expires'];
            
            if ($delete) {
                unset($this->data['challengesList'][$token]);
                $this->saveData();
            }

            return $expiresTs;
        } catch (CapException $e) {
            error_log("FileStorage: Failed to get challenge: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set verification token with expiration
     * @param string $key Token key (id:hash format)
     * @param int $expiresTs Expiration timestamp (seconds)
     * @return bool Success status
     */
    public function setToken(string $key, int $expiresTs): bool
    {
        try {
            $this->ensureLoaded();
            $this->data['tokensList'][$key] = $expiresTs;
            return $this->saveData();
        } catch (CapException $e) {
            error_log("FileStorage: Failed to set token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get verification token expiration time
     * @param string $key Token key (id:hash format)
     * @param bool $delete Whether to delete after get
     * @return int|null Expiration timestamp or null if not found
     */
    public function getToken(string $key, bool $delete = false): ?int
    {
        try {
            $this->ensureLoaded();
            
            if (!isset($this->data['tokensList'][$key])) {
                return null;
            }

            $expiresTs = $this->data['tokensList'][$key];
            
            if ($delete) {
                unset($this->data['tokensList'][$key]);
                $this->saveData();
            }

            return $expiresTs;
        } catch (CapException $e) {
            error_log("FileStorage: Failed to get token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up expired items
     * @return bool Success status
     */
    public function cleanup(): bool
    {
        try {
            $this->ensureLoaded();
            $now = time();
            $changed = false;

            // Clean expired challenges
            foreach ($this->data['challengesList'] as $token => $data) {
                if ($data['expires'] < $now) {
                    unset($this->data['challengesList'][$token]);
                    $changed = true;
                }
            }

            // Clean expired tokens
            foreach ($this->data['tokensList'] as $key => $expiresTs) {
                if ($expiresTs < $now) {
                    unset($this->data['tokensList'][$key]);
                    $changed = true;
                }
            }

            if ($changed) {
                return $this->saveData();
            }

            return true;
        } catch (CapException $e) {
            error_log("FileStorage: Failed to cleanup: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if storage is available
     * @return bool Availability status
     */
    public function isAvailable(): bool
    {
        return is_writable(dirname($this->filePath)) && 
               (file_exists($this->filePath) ? is_writable($this->filePath) : true);
    }

    /**
     * Get storage file path
     * @return string File path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get current storage statistics
     * @return array Storage statistics
     */
    public function getStats(): array
    {
        $this->ensureLoaded();
        return [
            'file_path' => $this->filePath,
            'file_exists' => file_exists($this->filePath),
            'file_size' => file_exists($this->filePath) ? filesize($this->filePath) : 0,
            'challenges_count' => count($this->data['challengesList'] ?? []),
            'tokens_count' => count($this->data['tokensList'] ?? []),
            'is_writable' => is_writable(dirname($this->filePath))
        ];
    }

    /**
     * Ensure data is loaded
     * @throws CapException
     */
    private function ensureLoaded(): void
    {
        if (!$this->isLoaded) {
            $this->loadData();
        }
    }

    /**
     * Load data from file
     * @throws CapException
     */
    private function loadData(): void
    {
        if (!file_exists($this->filePath)) {
            $this->data = [
                'challengesList' => [],
                'tokensList' => []
            ];
            $this->isLoaded = true;
            return;
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw CapException::storageError("Failed to read storage file: {$this->filePath}");
        }

        $decoded = json_decode($content, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw CapException::storageError("Invalid JSON in storage file: " . json_last_error_msg());
        }

        $this->data = [
            'challengesList' => $decoded['challengesList'] ?? [],
            'tokensList' => $decoded['tokensList'] ?? []
        ];
        $this->isLoaded = true;
    }

    /**
     * Save data to file
     * @return bool Success status
     * @throws CapException
     */
    private function saveData(): bool
    {
        $encoded = json_encode($this->data, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw CapException::storageError("Failed to encode data to JSON");
        }

        $result = file_put_contents($this->filePath, $encoded, LOCK_EX);
        if ($result === false) {
            throw CapException::storageError("Failed to write to storage file: {$this->filePath}");
        }

        return true;
    }

    /**
     * Ensure storage directory exists
     * @throws CapException
     */
    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->filePath);
        
        if ($directory !== '.' && !is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw CapException::storageError("Failed to create storage directory: {$directory}");
            }
        }
    }
}