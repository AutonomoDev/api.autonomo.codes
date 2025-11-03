<?php declare(strict_types=1);
// ==== src/WAHA/Services/ConversationService.php ====

namespace Autonomo\API\WAHA\Services;

use Exception;

/**
 * Manages storing and retrieving conversation history from the local filesystem.
 */
class ConversationService
{
    private string $storagePath;
    public int $timeoutSeconds;

    /**
     * @param string $storagePath The directory to store conversation JSON files.
     * @param int $timeoutSeconds The number of seconds of inactivity before a conversation expires.
     */
    public function __construct(string $storagePath, int $timeoutSeconds = 900) // 900 seconds = 15 minutes
    {
        $this->storagePath = rtrim($storagePath, '/');
        $this->timeoutSeconds = $timeoutSeconds;

        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0775, true)) {
                throw new Exception("Failed to create conversation storage directory: {$this->storagePath}");
            }
        }
        if (!is_writable($this->storagePath)) {
            throw new Exception("Conversation storage directory is not writable: {$this->storagePath}");
        }
    }

    /**
     * Retrieves the active conversation for a given chat ID.
     * Returns null if no active conversation is found (or it has expired).
     *
     * @param string $chatId
     * @return array|null The conversation data or null.
     */
    public function getActiveConversation(string $chatId): ?array
    {
        $filePath = $this->getConversationPath($chatId);

        if (!file_exists($filePath)) {
            return null;
        }

        $raw = file_get_contents($filePath);
        $conversation = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Corrupted file, treat as non-existent
            error_log("Failed to decode conversation file: " . $filePath);
            unlink($filePath);
            return null;
        }

        $lastUpdated = $conversation['last_updated'] ?? 0;
        $isExpired = (time() - $lastUpdated) > $this->timeoutSeconds;

        if ($isExpired) {
            // Conversation has expired, delete the old file
            unlink($filePath);
            return null;
        }

        return $conversation;
    }

    /**
     * Saves or updates a conversation file.
     *
     * @param string $chatId
     * @param array $conversationData
     * @return void
     */
    public function saveConversation(string $chatId, array $conversationData): void
    {
        $filePath = $this->getConversationPath($chatId);
        $conversationData['last_updated'] = time();
        
        file_put_contents(
            $filePath,
            json_encode($conversationData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Creates the data structure for a new conversation.
     *
     * @param string $initialTopic
     * @return array
     */
    public function startNewConversation(string $initialTopic = 'Uncategorized'): array
    {
        return [
            'id' => 'conv-' . bin2hex(random_bytes(8)),
            'created_at' => time(),
            'last_updated' => time(),
            'topic' => $initialTopic,
            'messages' => [],
        ];
    }
    
    /**
     * Appends a message to the conversation's message history.
     *
     * @param array $conversationData The conversation data (passed by reference).
     * @param string $role 'user' or 'assistant'.
     * @param string $content The message text.
     * @return void
     */
    public function appendMessage(array &$conversationData, string $role, string $content): void
    {
        $conversationData['messages'][] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    /**
     * Generates a safe file path for a conversation.
     *
     * @param string $chatId
     * @return string
     */
    private function getConversationPath(string $chatId): string
    {
        // Sanitize the chat ID to create a safe filename
        $safeFilename = preg_replace('/[^a-zA-Z0-9.\-@_]/', '', $chatId);
        return $this->storagePath . '/' . $safeFilename . '.json';
    }
}
