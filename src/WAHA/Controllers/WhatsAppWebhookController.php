<?php declare(strict_types=1);
// ==== src/WAHA/Controllers/WhatsAppWebhookController.php ====

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;
use Autonomo\API\WAHA\Services\WhatsAppService;
use Autonomo\API\WAHA\Services\FirebaseTicketService;
use Autonomo\API\WAHA\Services\PhoneNumberFormatter;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Kreait\Firebase\Exception\DatabaseException;

class WhatsAppWebhookController
{
    private FirebaseTicketService $firebase;

    public function __construct()
    {
        $this->firebase = new FirebaseTicketService(
            __DIR__ . '/../../../firebase-ai-concierge-94fe8-adminsdk-fbsvc-f03a2644a3.json',
            'https://ai-concierge-94fe8-default-rtdb.firebaseio.com/'
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

        // Debugging logs
        file_put_contents('/srv/http/waha/payload-' . time() . '.json', $raw);
        file_put_contents('/srv/http/waha/asdf.net', print_r([
            'payload'   => $payload,
            'isFromMe'  => $isFromMe,
            'chatId'    => $chatId,
            'messageId' => $messageId,
            'message'   => $message], true));
        file_put_contents('/srv/http/waha/msg-' . time(), $message);

        // Ignore invalid, empty, or self-sent messages.
        if (!$chatId || !$messageId || $isFromMe || $message === '') {
            $logMessage = '';
            if ($chatId === null) {
                $logMessage = "No chatId";
            }
            if (!$messageId) {
                $logMessage = "No messageId";
            }
            if ($isFromMe) {
                $logMessage = "is from me";
            }
            if ($message === '') {
                $logMessage = "No message";
            }
            $logMessage .= "\n" . print_r($payload, true);
            file_put_contents('/srv/http/waha/bugged-' . time(), $logMessage);
            file_put_contents($logDir . '/ignored-' . time(), print_r($payload, true));
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();
        $AI = new LLMWhatsAppBridge();
        $formatter = new PhoneNumberFormatter();

        // Define allowed categories for validation and ticket creation
        $allowedCategories = ['HVAC', 'Plumbing', 'Electrical', 'Noise Complaint', 'FAQ'];

        $wa->sendSeen($chatId, $messageId);
        usleep(mt_rand(15000, 2500000));
        $wa->startTyping($chatId);

        try {
            $activeTicketId = null;
            $ticketData = null;
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            // Get latest ticket for this chatId if it exists
            $existingTickets = $this->firebase->listTickets();

            foreach ($existingTickets as $t) {
                if (($t['phoneNumber'] ?? null) === $chatId) {
                    $lastMessageTime = new DateTimeImmutable($t['timestamp']);
                    $diffSeconds = (new DateTimeImmutable())->getTimestamp() - $lastMessageTime->getTimestamp();
                    if ($diffSeconds < 1800) { // 30 minutes
                        $activeTicketId = $t['ticketId'];
                        $ticketData = $t; // Load existing ticket data
                        break;
                    }
                }
            }

            // If no active ticket, create a new one with initial defaults
            if (!$activeTicketId) {
                $activeTicketId = 'TICKET-' . strtoupper(substr(md5($chatId . microtime()), 0, 8));
                $phoneNumber = $formatter->formatFromChatId($chatId);

                $ticketData = [
                    'ticketId'     => $activeTicketId,
                    'category'     => 'Uncategorized',
                    'residentId'   => 'UNKNOWN',
                    'residentName' => $payload['_data']['notifyName'] ?? 'Unknown',
                    'phoneNumber'  => $phoneNumber,
                    'location'     => 'Unspecified',
                    'vendor'       => 'VENDOR 1',
                    'status'       => 'New',
                    'status_history' => [],
                    'priority'     => 'Normal',
                    'summary'      => 'AI Summary: pending analysis.',
                    'timestamp'    => $timestamp,
                    'conversation' => [
                        'role'    => 'user',
                        'content' => $message,
                        'timestamp' => $timestamp,
                    ],
                ];
            }

            file_put_contents('/srv/http/waha/whatsapp.log', print_r([$chatId, $message], true) . "\n", FILE_APPEND);

            // Get the AI reply from our WhatsApp LLM Bridge.
            $replyResponse = $AI->chat([['role' => 'user', 'content' => $message]], $chatId);
            $wa->stopTyping($chatId);

            file_put_contents('/srv/http/waha/whatsapp.log', print_r($replyResponse, true) . "\n", FILE_APPEND);

            $initialLLMReplyText = $replyResponse['content'][0]['text'] ?? '';

            // Filter every $llmReplyText that starts with "### " into /srv/http/waha/llm-reply-internal-' . time() . '.log
            // This logs the entire raw LLM response if its first line starts with "### "
            if (str_starts_with($initialLLMReplyText, '### ')) {
                file_put_contents('/srv/http/waha/llm-reply-internal-' . time() . '.log', $initialLLMReplyText);
            }

            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__);

            // Extract metadata from LLM reply and filter the text for the user
            $extractedData = $this->extractAndCategorizeFromLLMReply(
                $initialLLMReplyText,
                $allowedCategories
            );
            file_put_contents('/srv/http/waha/llm-reply-filtered-' . time() . '.log', print_r($extractedData, true) . "\n", FILE_APPEND);

            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . $extractedData['category'] . "\n", FILE_APPEND);
            $reply = $extractedData['text'];

            // Check if category is FAQ - if so, delete ticket and don't save
            $isFAQ = ($extractedData['category'] === 'FAQ');
            
            if ($isFAQ) {
                // If there's an active ticket, delete it
                if ($activeTicketId) {
                    try {
                        $this->firebase->deleteTicket($activeTicketId);
                        file_put_contents(
                            '/srv/http/waha/faq-ticket-deleted.log',
                            '[' . date('c') . '] Deleted FAQ ticket: ' . $activeTicketId . ' for chat: ' . $chatId . "\n",
                            FILE_APPEND
                        );
                    } catch (Exception $e) {
                        file_put_contents(
                            '/srv/http/waha/firebase-error.log',
                            '[' . date('c') . '] Error deleting FAQ ticket: ' . $e->getMessage() . "\n",
                            FILE_APPEND
                        );
                    }
                }
            } else {
                // Only update and save ticket if it's NOT an FAQ
                if ($ticketData) {
                    $ticketData['category'] = $extractedData['category'];
                    $ticketData['priority'] = $extractedData['severity'];
                    if (!empty($extractedData['subject'])) {
                        $ticketData['summary'] = $extractedData['subject'];
                    }
                    if (!empty($extractedData['residentName'])) {
                        $ticketData['residentName'] = $extractedData['residentName'];
                    }
                    if (!empty($extractedData['actionTaken'])) {
                        $ticketData['lastActionTaken'] = $extractedData['actionTaken'];
                    }

                    $ticketData['timestamp'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                    $this->firebase->saveTicket($ticketData);
                }
            }

            // Send the reply using the WhatsAppService
            $wa->sendText($chatId, $reply, $messageId);

            // Only add assistant reply to Firebase if NOT an FAQ
            if (!$isFAQ) {
                try {
                    file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
                    $this->firebase->addConversationMessage($activeTicketId, 'assistant', $reply);
                    file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
                } catch (DatabaseException $e) {
                    file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
                    file_put_contents(
                        '/srv/http/waha/firebase-error.log',
                        '[' . date('c') . '] ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"  . str_repeat('-', 80) . "\n",
                        FILE_APPEND
                    );
                }
            }
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'ok', 
                'ticketId' => $isFAQ ? null : $activeTicketId,
                'reply_sent' => true,
                'category' => $extractedData['category']
            ]);

            file_put_contents($logDir . '/log.txt', date('c') . " - OK\n", FILE_APPEND);

        } catch (Exception $e) {
            error_log('Error in WhatsAppWebhookController: ' . $e->getMessage());

            // Notify the user of an error.
            $wa->sendText($chatId, "I'm sorry, I encountered a server error. Please try again later.", $messageId);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Extracts metadata from LLM reply and removes command lines from the text.
     * Processes the reply line by line for better reliability in detecting and filtering command lines.
     *
     * @param string $llmReplyText The raw text response from the LLM.
     * @param array $allowedCategories An array of valid categories to validate against.
     * @return array An associative array containing the filtered text and extracted metadata.
     *               e.g., ['text' => '...', 'category' => '...', 'severity' => '...', etc.]
     */
    private function extractAndCategorizeFromLLMReply(string $llmReplyText, array $allowedCategories): array
    {
        // Use a more robust method to split lines, handling \n, \r, and \r\n line endings
        $lines = preg_split('/\R/u', $llmReplyText);
        if ($lines === false) {
            // In case of a preg_split error, treat the input as a single line
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

        // Pattern for internal notes that should be filtered out
        $internalNotePattern = '/^###\s*(.*)$/';

        // Pattern for metadata commands
        $commandPattern = '/^\+\+\+\s*(CATEGORY|SEVERITY|SUBJECT|RESIDENT_ID|ACTION_TAKEN)\s*:\s*(.*)$/iu';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Check if the line is an internal note (###) and filter it out
            if (preg_match($internalNotePattern, $trimmedLine)) {
                continue;
            }

            // Check if the line is a metadata command (+++)
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

                // This line is a command, so skip adding it to the filtered output
                continue;
            }

            // If the line is not an internal note or a special command, keep it for the user reply
            $filteredLines[] = $line;
        }

        // Join the remaining lines back together and trim any leading/trailing whitespace
        $filteredReply = trim(implode("\n", $filteredLines));
        $extractedData['text'] = $filteredReply;

        return $extractedData;
    }
}
