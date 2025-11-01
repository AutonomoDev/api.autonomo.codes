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

        // Debugging logs from original file
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
            file_put_contents($logDir . '/ignored-' . time(), print_r($payload, true)); // from firebase version
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();
        $AI = new LLMWhatsAppBridge();
        $formatter = new PhoneNumberFormatter();

        // Define allowed categories for validation and ticket creation
        $allowedCategories = ['HVAC', 'Plumbing', 'Electrical', 'Noise Complaint', 'FAQ'];

        $wa->startTyping();
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
                    'category'     => 'Uncategorized', // Default, will be updated by LLM output
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

            // Debug log from original
            file_put_contents('/srv/http/waha/whatsapp.log', print_r([$chatId, $message], true) . "\n", FILE_APPEND);

            // Get the AI reply from our WhatsApp LLM Bridge.
            $replyResponse = $AI->chat([['role' => 'user', 'content' => $message]], $chatId);

            // Debug log from original
            file_put_contents('/srv/http/waha/whatsapp.log', print_r($replyResponse, true) . "\n", FILE_APPEND);

            $initialLLMReplyText = $replyResponse['content'][0]['text'] ?? '';
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__);

            // Extract category and severity from LLM reply and filter the text
            [$filteredReplyForUser, $extractedCategory, $extractedSeverity] = $this->extractAndCategorizeFromLLMReply(
                $initialLLMReplyText,
                $allowedCategories
            );
            file_put_contents('/srv/http/waha/llm-reply-filtered-' . time() . '.log', print_r($filteredReplyForUser, true) . "\n", FILE_APPEND);

            // Update ticket metadata with extracted information and latest timestamp
            if ($ticketData) { // $ticketData should always be set here, either new or existing
                $ticketData['category'] = $extractedCategory;
                $ticketData['priority'] = $extractedSeverity;
                $ticketData['timestamp'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                $this->firebase->saveTicket($ticketData);
            }

            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . $extractedCategory, FILE_APPEND);
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . $extractedCategory, FILE_APPEND);
            $reply = $filteredReplyForUser;

            // Send the reply using the WhatsAppService.
            $wa->sendText($chatId, $reply, $messageId);
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);

            // Add assistant reply to Firebase
            try {
                file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);
                $this->firebase->addConversationMessage($activeTicketId, 'assistant', $reply);
                file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);
            } catch (DatabaseException $e) {
                file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);
                file_put_contents(
                    '/srv/http/waha/firebase-error.log',
                    '[' . date('c') . '] ' . $e->getMessage(),
                    FILE_APPEND
                );
            }
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'ticketId' => $activeTicketId, 'reply_sent' => true]);

            // Final debug log from original (fixed syntax)
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
     * Extracts CATEGORY and SEVERITY from LLM reply and removes them from the text.
     * This version processes the reply line by line and has been improved for
     * better reliability in detecting and filtering command lines.
     *
     * @param string $llmReplyText The raw text response from the LLM.
     * @param array $allowedCategories An array of valid categories to validate against.
     * @return array An array containing [filteredReplyText, extractedCategory, extractedSeverity].
     */
    private function extractAndCategorizeFromLLMReply(string $llmReplyText, array $allowedCategories): array
    {
        // Use a more robust method to split lines, handling \n, \r, and \r\n line endings.
        $lines = preg_split('/\R/', $llmReplyText);
        if ($lines === false) {
            // In case of a preg_split error, treat the input as a single line.
            $lines = [$llmReplyText];
        }

        $filteredLines = [];
        $extractedCategory = 'Uncategorized'; // Default category
        $extractedSeverity = 'Normal';        // Default severity/priority

        // FIXED: This pattern is more flexible and matches the LLM's actual output format
        // (e.g., "+++ CATEGORY: HVAC") without requiring double square brackets.
        $commandPattern = '/^\+\+\+\s*(CATEGORY|SEVERITY)\s*:\s*(.*)$/i';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Check if the entire line is a command line.
            if (preg_match($commandPattern, $trimmedLine, $matches)) {
                // $matches[1] will be "CATEGORY" or "SEVERITY"
                // $matches[2] will be the value (e.g., "HVAC")
                $key = strtoupper($matches[1]);
                $value = trim($matches[2]);

                if ($key === 'CATEGORY') {
                    if (in_array($value, $allowedCategories, true)) {
                        $extractedCategory = $value;
                    }
                } elseif ($key === 'SEVERITY') {
                    $extractedSeverity = $value;
                }

                // This line is a command, so we skip adding it to the filtered output.
                continue;
            }

            // If the line is not a special command, keep it for the user reply.
            // We add the original, unmodified line to preserve original formatting and indentation.
            $filteredLines[] = $line;
        }

        // Join the remaining lines back together and trim any leading/trailing whitespace
        // or newlines that might result from the filtering process.
        $filteredReply = trim(implode("\n", $filteredLines));

        return [$filteredReply, $extractedCategory, $extractedSeverity];
    }
}
