<?php declare(strict_types=1);
// ==== src/WAHA/Controllers/WhatsAppWebhookController.php ====

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\ConversationService;
use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;
use Autonomo\API\WAHA\Services\WhatsAppService;
use Autonomo\API\WAHA\Services\FirebaseTicketService;
use Autonomo\API\WAHA\Services\PhoneNumberFormatter;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;

class WhatsAppWebhookController
{
    private FirebaseTicketService $firebase;
    private ConversationService $conversationService;

    public function __construct()
    {
        $this->firebase = new FirebaseTicketService(
            __DIR__ . '/../../../firebase-ai-concierge-94fe8-adminsdk-fbsvc-f03a2644a3.json',
            'https://ai-concierge-94fe8-default-rtdb.firebaseio.com/'
        );

        $this->conversationService = new ConversationService(
            __DIR__ . '/../../../storage/conversations',
            900 // 15 minutes timeout
        );
    }

    public function handle(): void
    {
        $logDir = __DIR__ . '/../../../storage';

        $raw = null;
        if (empty($_GET['debug'])) {
            $raw = file_get_contents('php://input');
            error_log("WAHA Webhook received: " . $raw);
        } else {
            $raw = $_GET['debug'];
        }
        file_put_contents($logDir . '/input.log', $raw . "\n", FILE_APPEND);


        $data = json_decode($raw, true);
        $payload   = $data['payload'] ?? [];
        $chatId    = $payload['from'] ?? null;
        $messageId = $payload['id'] ?? null;
        $message   = trim($payload['body'] ?? '');
        $isFromMe  = $payload['fromMe'] ?? false;
        
        // Ignore invalid, empty, or self-sent messages.
        if (!$chatId || !$messageId || $isFromMe || $message === '') {
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();
        $AI = new LLMWhatsAppBridge();
        $formatter = new PhoneNumberFormatter();
        $allowedCategories = ['HVAC', 'Plumbing', 'Electrical', 'Noise Complaint', 'FAQ'];

        $wa->sendSeen($chatId, $messageId);
        usleep(mt_rand(15000, 2500000));
        $wa->startTyping($chatId);

        try {
            // ================================
            // === CONVERSATION LOGIC START ===
            // ================================
            
            // Get active conversation from file storage, or start a new one.
            $conversation = $this->conversationService->getActiveConversation($chatId);
            if ($conversation === null) {
                $conversation = $this->conversationService->startNewConversation();
            }

            // Add the user's new message to the history.
            $this->conversationService->appendMessage($conversation, 'user', $message);

            // Get the AI reply from our WhatsApp LLM Bridge, passing the full conversation history for context.
            $replyResponse = $AI->chat($conversation['messages'], $chatId);
            $wa->stopTyping($chatId);

            $initialLLMReplyText = $replyResponse['content'][0]['text'] ?? 'Sorry, I could not process that.';

            // Extract metadata from LLM reply and filter the text for the user.
            $extractedData = $this->extractAndCategorizeFromLLMReply($initialLLMReplyText, $allowedCategories);
            $reply = $extractedData['text'];
            
            // --- Topic Change Logic ---
            // If the LLM assigned a new, specific category to a message in an already-categorized conversation,
            // it's a new topic. We start a fresh conversation.
            $newCategory = $extractedData['category'];
            $currentTopic = $conversation['topic'];
            
            if ($currentTopic !== 'Uncategorized' && $newCategory !== 'Uncategorized' && $newCategory !== $currentTopic) {
                // Topic has changed. Start a new conversation for this new topic.
                $conversation = $this->conversationService->startNewConversation($newCategory);
                // Add the user's message that triggered the new topic.
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

            // Find the latest active ticket in Firebase for this chatId, if one exists
            $existingTickets = $this->firebase->listTickets();
            foreach ($existingTickets as $t) {
                if (($t['phoneNumber'] ?? null) === $chatId) {
                     // We check against the file-based conversation timeout now.
                    $lastMessageTime = new DateTimeImmutable($t['timestamp']);
                    $diffSeconds = (new DateTimeImmutable())->getTimestamp() - $lastMessageTime->getTimestamp();
                    if ($diffSeconds < $this->conversationService->timeoutSeconds) { 
                        $activeTicketId = $t['ticketId'];
                        $ticketData = $t;
                        break;
                    }
                }
            }
            
            $isFAQ = ($extractedData['category'] === 'FAQ');
            
            if ($isFAQ) {
                // If the current message is an FAQ, we don't care about the ticket.
                // If an old ticket existed, delete it from Firebase.
                if ($activeTicketId) {
                    $this->firebase->deleteTicket($activeTicketId);
                }
            } else {
                // It's a real issue, so create or update the Firebase ticket.
                if (!$activeTicketId) {
                    $activeTicketId = 'TICKET-' . strtoupper(substr(md5($chatId . microtime()), 0, 8));
                    $phoneNumber = $formatter->formatFromChatId($chatId);
                    $ticketData = [
                        'ticketId'     => $activeTicketId,
                        'residentName' => $payload['_data']['notifyName'] ?? 'Unknown',
                        'phoneNumber'  => $phoneNumber,
                        'status'       => 'New',
                        'vendor'       => 'VENDOR 1',
                        'location'     => 'Unspecified',
                        'status_history' => [],
                    ];
                }

                // Update ticket data with the latest info from the LLM
                $ticketData['category'] = $extractedData['category'];
                $ticketData['priority'] = $extractedData['severity'];
                if (!empty($extractedData['subject'])) $ticketData['summary'] = $extractedData['subject'];
                if (!empty($extractedData['residentName'])) $ticketData['residentName'] = $extractedData['residentName'];
                if (!empty($extractedData['actionTaken'])) $ticketData['lastActionTaken'] = $extractedData['actionTaken'];
                $ticketData['timestamp'] = $timestamp;

                // Save to Firebase
                $this->firebase->saveTicket($ticketData);
                // Also add the user and assistant messages to the Firebase ticket's own log
                $this->firebase->addConversationMessage($activeTicketId, 'user', $message);
                $this->firebase->addConversationMessage($activeTicketId, 'assistant', $reply);
            }

            // Send the reply via WhatsApp
            $wa->sendText($chatId, $reply, $messageId);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'ok', 
                'ticketId' => $isFAQ ? null : $activeTicketId,
                'reply_sent' => true,
                'category' => $extractedData['category']
            ]);

        } catch (Exception $e) {
            error_log('Error in WhatsAppWebhookController: ' . $e->getMessage());
            $wa->sendText($chatId, "I'm sorry, I encountered a server error. Please try again later.", $messageId);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Extracts metadata from LLM reply and removes command lines from the text.
     * (This function remains unchanged)
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

        $internalNotePattern = '/^###\s*(.*)$/';
        $commandPattern = '/^\+\+\+\s*(CATEGORY|SEVERITY|SUBJECT|RESIDENT_ID|ACTION_TAKEN)\s*:\s*(.*)$/iu';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (preg_match($internalNotePattern, $trimmedLine)) {
                continue;
            }

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
                continue;
            }

            $filteredLines[] = $line;
        }

        $filteredReply = trim(implode("\n", $filteredLines));
        $extractedData['text'] = $filteredReply;

        return $extractedData;
    }
}
