<?php declare(strict_types=1);
// ==== src/WAHA/Services/WhatsAppMessageProcessor.php ====

namespace Autonomo\API\WAHA\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Kreait\Firebase\Exception\DatabaseException;
use Kreait\Firebase\Exception\RuntimeException;

class WhatsAppMessageProcessor
{
    private FirebaseTicketService $firebase;
    private ConversationService $conversationService;
    private WhatsAppService $wa;
    private LLMWhatsAppBridge $AI;
    private PhoneNumberFormatter $formatter;
    private ConversationAnalytics $analytics;
    private array $allowedCategories;
    private bool $simulateMode;
    private string $logDir;

    public function __construct(
        string $conversationStoragePath,
        int $conversationTimeoutSeconds,
        string $firebaseServiceAccountPath,
        string $firebaseDatabaseUrl,
        bool $simulateMode = false
    ) {
        $this->simulateMode = $simulateMode;
        $this->logDir = '/srv/http/waha';

        // Initialize all services, passing simulateMode to WhatsAppService
        $this->firebase = new FirebaseTicketService(
            $firebaseServiceAccountPath,
            $firebaseDatabaseUrl
        );

        $this->conversationService = new ConversationService(
            $conversationStoragePath,
            $conversationTimeoutSeconds
        );

        // Pass the simulateMode flag to WhatsAppService
        $this->wa = new WhatsAppService($this->simulateMode);
        $this->AI = new LLMWhatsAppBridge();
        $this->formatter = new PhoneNumberFormatter();

        $this->analytics = new ConversationAnalytics();

        $this->allowedCategories = ['HVAC', 'Plumbing', 'Electrical', 'Noise Complaint', 'FAQ'];
    }

    /**
     * Entry point for processing raw webhook payloads (e.g., from the queue).
     * This method parses the raw payload and then calls the core processing logic.
     *
     * @param string $rawPayload The raw JSON payload from the WhatsApp webhook.
     * @return array An array containing processing results (e.g., reply, ticketId, category).
     * @throws Exception If any critical processing step fails.
     */
    public function processPayload(string $rawPayload): array
    {
        $data = json_decode($rawPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON payload: " . json_last_error_msg());
        }

        $payload   = $data['payload'] ?? [];
        $chatId    = $payload['from'] ?? null;
        $messageId = $payload['id'] ?? null;
        $message   = trim($payload['body'] ?? '');
        $isFromMe  = $payload['fromMe'] ?? false;

        // Ignore invalid, empty, or self-sent messages for actual webhooks.
        if (!$chatId || !$messageId || $isFromMe || $message === '') {
            error_log("WAHA Message Processor: Ignored webhook message - no chatId, messageId, or self-sent/empty. ChatID: {$chatId}, MsgID: {$messageId}.");
            return ['status' => 'ignored_invalid_or_self_message'];
        }

        // Call the shared core processing logic
        return $this->_processMessageCore($chatId, $message, $messageId, $payload);
    }

    /**
     * Entry point for simulating an incoming WhatsApp message directly from the dev UI.
     * This bypasses raw payload parsing and constructs a pseudo-payload for the core logic.
     *
     * @param string $chatId The simulated WhatsApp chat ID (e.g., '+1234567890').
     * @param string $userMessage The message typed by the user in the dev UI.
     * @return array An array containing processing results.
     * @throws Exception If processing fails.
     */
    public function simulateIncomingMessage(string $chatId, string $userMessage): array
    {
        // For simulation, we generate a unique message ID and assume it's valid user input.
        $messageId = 'simulated_msg_' . uniqid();
        $timestamp = (new DateTimeImmutable())->getTimestamp();

        // Create a minimal payload structure to match what the webhook would provide.
        // We ensure a '+' prefix for the chat ID to match libphonenumber parsing expectations.
        if (str_contains($chatId, '@') === false) {
            if (strlen($chatId) === 14) {
                $formattedChatId = "$chatId@lid";
            } else {
                $formattedChatId = "$chatId@c.us";
            }
        } else {
            $formattedChatId = $chatId;
        }

        $payload = [
            'id'       => $messageId,
            'type'     => 'chat',
            'body'     => $userMessage,
            'from'     => $formattedChatId,
            't'        => $timestamp,
            'isNewMsg' => true,
            'fromMe'   => false,
            '_data'    => [
                'id'         => ['id' => $messageId, 'fromMe' => false, '_serialized' => true],
                'body'       => $userMessage,
                'from'       => $formattedChatId,
                't'          => $timestamp,
                'type'       => 'chat',
                'notifyName' => 'Simulated User',
                '_serialized'=> true
            ]
        ];

        error_log("WAHA Message Processor: Simulating message from '{$formattedChatId}': '{$userMessage}'");

        // Call the shared core processing logic
        return $this->_processMessageCore($formattedChatId, $userMessage, $messageId, $payload);
    }

    /**
     * The core logic for processing a WhatsApp message, shared by both webhook and simulation modes.
     *
     * @param string $chatId The WhatsApp chat ID.
     * @param string $message The incoming message content.
     * @param string $messageId The WhatsApp message ID.
     * @param array $payload The full parsed webhook payload (or simulated payload).
     * @return array Processing results.
     * @throws Exception On critical failure.
     */
    private function _processMessageCore(string $chatId, string $message, string $messageId, array $payload): array
    {
        // Track processing time for metrics
        $processingStartTime = microtime(true);

        $this->wa->sendSeen($chatId, $messageId);
        if (!$this->simulateMode) {
            usleep(mt_rand(15000, 2500000));
        }
        $this->wa->startTyping($chatId);

        try {
            // ================================
            // === CONVERSATION LOGIC START ===
            // ================================

            // Get active conversation from file storage, or start a new one.
            $conversation = $this->conversationService->getActiveConversation($chatId);
            if ($conversation === null) {
                $conversation = $this->conversationService->startNewConversation();
                error_log("WAHA Message Processor: Started new conversation for chatId: {$chatId} (Simulate: {$this->simulateMode})");
            } else {
                error_log("WAHA Message Processor: Resumed conversation for chatId: {$chatId}, topic: {$conversation['topic']} (Simulate: {$this->simulateMode})");
            }

            // Add the user's new message to the history.
            $this->conversationService->appendMessage($conversation, 'user', $message);

            file_put_contents($this->logDir . '/whatsapp.log', "[INCOMING] " . print_r(['chatId' => $chatId, 'message' => $message], true) . "\n", FILE_APPEND);

            $replyResponse = $this->AI->chat($conversation['messages'], $chatId);
            $this->wa->stopTyping($chatId);

            file_put_contents($this->logDir . '/whatsapp.log', "[LLM_RESPONSE] " . print_r($replyResponse, true) . "\n", FILE_APPEND);

            $initialLLMReplyText = $replyResponse['content'][0]['text'] ?? 'Sorry, I could not process that.';

            // If the LLM reply contains any internal commands/notes, log the full raw reply for debugging.
            if (str_contains($initialLLMReplyText, '### ') || str_contains($initialLLMReplyText, '+++ ')) {
                file_put_contents($this->logDir . '/llm-reply-internal-' . time() . '.log', $initialLLMReplyText);
            }

            file_put_contents($this->logDir . '/extract-categories.log', date('c') . ' ' . __LINE__ . " - Before extractAndCategorizeFromLLMReply\n", FILE_APPEND);

            $extractedData = $this->extractAndCategorizeFromLLMReply($initialLLMReplyText, $this->allowedCategories);
            $reply = $extractedData['text'];

            file_put_contents($this->logDir . '/llm-reply-filtered-' . time() . '.log', "[FILTERED_LLM_REPLY] " . print_r($reply, true) . "\n", FILE_APPEND);


            // --- Topic Change Logic ---
            $newCategory = $extractedData['category'];
            $currentTopic = $conversation['topic'];

            if ($currentTopic !== 'Uncategorized' && $newCategory !== 'Uncategorized' && $newCategory !== $currentTopic) {
                // Topic has changed. Start a new conversation for this new topic.
                error_log("WAHA Message Processor: Topic changed from '{$currentTopic}' to '{$newCategory}' for chatId: {$chatId}. Starting new conversation.");
                $conversation = $this->conversationService->startNewConversation($newCategory);
                // Add the user's message that triggered the new topic to the new conversation.
                $this->conversationService->appendMessage($conversation, 'user', $message);
            }

            // Add the assistant's reply to the history.
            $this->conversationService->appendMessage($conversation, 'assistant', $reply);

            // Update the conversation's topic if a new one was identified.
            if ($newCategory !== 'Uncategorized') {
                $conversation['topic'] = $newCategory;
            }

            // Save the updated conversation back to its file.
            $this->conversationService->saveConversation($chatId, $conversation);

            // ==============================
            // === CONVERSATION LOGIC END ===
            // ==============================

            // ================================
            // === FIREBASE TICKETING LOGIC ===
            // ================================
            $activeTicketId = null;
            $ticketData = null;
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $phoneNumber = $this->formatter->formatFromChatId($chatId);

            // Find all tickets for this chatId, and specifically the active one if any.
            $allTicketsForChatId = [];
            $existingTickets = $this->firebase->listTickets();
            foreach ($existingTickets as $t) {
                if (($t['phoneNumber'] ?? null) === $phoneNumber) {
                    $allTicketsForChatId[] = $t;
                    // We check against the file-based conversation timeout now to identify the 'active' ticket.
                    $lastMessageTime = new DateTimeImmutable($t['timestamp']);
                    $diffSeconds = (new DateTimeImmutable())->getTimestamp() - $lastMessageTime->getTimestamp();
                    if ($diffSeconds < $this->conversationService->timeoutSeconds) {
                        $activeTicketId = $t['ticketId'];
                        $ticketData = $t;
                    }
                }
            }

            $isFAQ = ($extractedData['category'] === 'FAQ');

            if ($isFAQ) {
                // If the current message is an FAQ, we need to delete ALL tickets for this chatId,
                // regardless of their active status (timed out or not).
                foreach ($allTicketsForChatId as $t) {
                    $this->firebase->deleteTicket($t['ticketId']);
                    error_log("WAHA Message Processor: FAQ detected for {$chatId}, deleting ticket {$t['ticketId']}.");
                }
                // Ensure no ticket ID is associated with an FAQ response in the return value
                $activeTicketId = null;
            } else {
                // It's a real issue, so create or update the Firebase ticket.
                if (!$activeTicketId) {
                    $activeTicketId = 'TICKET-' . strtoupper(substr(md5($chatId . microtime()), 0, 8));
                    $ticketData = [
                        'ticketId'     => $activeTicketId,
                        'residentName' => $payload['_data']['notifyName'] ?? 'Unknown',
                        'phoneNumber'  => $phoneNumber,
                        'status'       => 'New',
                        'vendor'       => 'VENDOR 1',
                        'location'     => 'Unspecified',
                        'status_history' => [],
                    ];
                    error_log("WAHA Message Processor: Created new ticket {$activeTicketId} for {$chatId}.");
                } else {
                    error_log("WAHA Message Processor: Updating existing ticket {$activeTicketId} for {$chatId}.");
                }

                // Update ticket data with the latest info from the LLM
                $ticketData['category'] = $extractedData['category'];
                $ticketData['priority'] = $extractedData['severity'];
                if (!empty($extractedData['subject'])) $ticketData['summary'] = $extractedData['subject'];
                if (!empty($extractedData['residentName'])) $ticketData['residentName'] = $extractedData['residentName'];
                if (!empty($extractedData['actionTaken'])) $ticketData['lastActionTaken'] = $extractedData['actionTaken'];
                $ticketData['timestamp'] = $timestamp;

                $this->firebase->saveTicket($ticketData);

                file_put_contents($this->logDir . '/extract-categories.log', date('c') . ' ' . __LINE__ . " - Adding user msg to Firebase ticket convo\n", FILE_APPEND);
                $this->firebase->addConversationMessage($activeTicketId, 'user', $message);

                file_put_contents($this->logDir . '/extract-categories.log', date('c') . ' ' . __LINE__ . " - Adding assistant reply to Firebase ticket convo\n", FILE_APPEND);
                $this->firebase->addConversationMessage($activeTicketId, 'assistant', $reply);
            }

            file_put_contents($this->logDir . '/extract-categories.log', date('c') . ' ' . ($extractedData['category'] ?? 'N/A') . " - Category assigned after extraction.\n", FILE_APPEND);

            $this->wa->sendText($chatId, $reply, $messageId);
            error_log("WAHA Message Processor: Sent reply to {$chatId}. (Simulate: {$this->simulateMode})");

            // Calculate response time for metrics
            $processingEndTime = microtime(true);
            $responseTimeMs = round(($processingEndTime - $processingStartTime) * 1000);

            // Track conversation analytics
            $this->analytics->trackConversation(
                $chatId,
                $conversation,
                $extractedData,
                $responseTimeMs
            );

            return [
                'status'             => 'ok',
                'ticketId'           => $isFAQ ? null : $activeTicketId,
                'reply_sent'         => true,
                'category'           => $extractedData['category'],
                'reply_text'         => $reply,
                'chatId'             => $chatId,
                'messageId'          => $messageId,
                'user_message'       => $message,
                'conversation_topic' => $conversation['topic'],
                'extractedData'      => $extractedData,
                'response_time_ms'   => $responseTimeMs
            ];

        } catch (DatabaseException $e) {
            error_log('Firebase Database Error in WhatsAppMessageProcessor: ' . $e->getMessage());
            file_put_contents(
                $this->logDir . '/firebase-error.log',
                '[' . date('c') . '] ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            throw new RuntimeException("Firebase operation failed for chatId {$chatId}: " . $e->getMessage(), 0, $e);
        } catch (Exception $e) {
            error_log('Error in WhatsAppMessageProcessor: ' . $e->getMessage());
            // This causes endless loops.
            //if (!$this->simulateMode) {
            //    try {
            //        $this->wa->sendText($chatId, "I'm sorry, I encountered a server error while processing your request. Please try again later.", $messageId);
            //    } catch (Exception $e2) {
            //        error_log('Failed to send error message back to WhatsApp after processor error: ' . $e2->getMessage());
            //    }
            //}
            throw new \RuntimeException("Processing failed for chatId {$chatId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts metadata from LLM reply and removes command lines from the text.
     */
    private function extractAndCategorizeFromLLMReply(string $llmReplyText, array $allowedCategories): array
    {
        $lines = preg_split('/\R/u', $llmReplyText);
        if ($lines === false) {
            $lines = [$llmReplyText];
        }

        $filteredLines = [];
        $extractedData = [
            'text'         => '',
            'category'     => 'Uncategorized',
            'severity'     => 'Normal',
            'subject'      => '',
            'residentName' => '',
            'actionTaken'  => '',
        ];

        // This pattern matches specific commands like +++ CATEGORY: Plumbing
        $commandPattern = '/^\+\+\+\s*(CATEGORY|SEVERITY|SUBJECT|RESIDENT_ID|ACTION_TAKEN)\s*:\s*(.*)$/iu';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Filter out internal notes (starting with ###)
            if (str_starts_with($trimmedLine, '### ')) {
                continue;
            }

            // Process and filter out command/note lines (starting with +++)
            if (str_starts_with($trimmedLine, '+++ ')) {
                // Check if it's a structured command we can parse for metadata
                if (preg_match($commandPattern, $trimmedLine, $matches)) {
                    $key = strtoupper($matches[1]);
                    $value = trim($matches[2]);

                    switch ($key) {
                        case 'CATEGORY':
                            if (in_array($value, $allowedCategories, true)) {
                                $extractedData['category'] = $value;
                            }
                            break;
                        case 'SEVERITY':
                            $extractedData['severity'] = $value;
                            break;
                        case 'SUBJECT':
                            $extractedData['subject'] = $value;
                            break;
                        case 'RESIDENT_ID':
                            $extractedData['residentName'] = $value;
                            break;
                        case 'ACTION_TAKEN':
                            $extractedData['actionTaken'] = $value;
                            break;
                    }
                }
                // Always skip adding +++ lines to the final reply, regardless of whether
                // they were a parsable command or just an internal comment.
                continue;
            }

            $filteredLines[] = $line;
        }

        $filteredReply = trim(implode("\n", $filteredLines));
        $extractedData['text'] = $filteredReply;

        return $extractedData;
    }
}
