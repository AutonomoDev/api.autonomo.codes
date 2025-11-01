<?php declare(strict_types=1);
// ==== src/WAHA/Services/WhatsAppConciergeService.php ====

namespace Autonomo\API\WAHA\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Kreait\Firebase\Exception\DatabaseException;

class WhatsAppConciergeService
{
    private FirebaseTicketService $firebase;
    private LLMWhatsAppBridge $AI;
    private PhoneNumberFormatter $formatter;
    private array $allowedCategories;
    private string $logDir; // Directory for concierge-specific logs (e.g., /srv/http/waha/)

    public function __construct(
        string $firebaseCredentialsPath,
        string $firebaseDatabaseUri,
        array $allowedCategories,
        string $logDir
    ) {
        $this->firebase = new FirebaseTicketService($firebaseCredentialsPath, $firebaseDatabaseUri);
        $this->AI = new LLMWhatsAppBridge();
        $this->formatter = new PhoneNumberFormatter();
        $this->allowedCategories = $allowedCategories;
        $this->logDir = $logDir;
    }

    /**
     * Processes an incoming WhatsApp message, interacts with LLM, manages tickets,
     * and returns a user-facing reply.
     *
     * @param string $chatId The ID of the chat (phone number).
     * @param string $message The content of the incoming message.
     * @param string $messageId The ID of the incoming message.
     * @param array $payload The full WhatsApp payload, used for residentName.
     * @return array An array containing 'reply' (string) and 'ticketId' (string).
     * @throws Exception If an error occurs during processing.
     */
    public function processIncomingMessage(string $chatId, string $message, string $messageId, array $payload): array
    {
        $activeTicketId = null;
        $ticketData = null;
        $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        // Get latest ticket for this chatId if it exists and is still active (within 30 minutes)
        $existingTickets = $this->firebase->listTickets();

        foreach ($existingTickets as $t) {
            if (($t['phoneNumber'] ?? null) === $chatId) {
                try {
                    $lastMessageTime = new DateTimeImmutable($t['timestamp']);
                    $diffSeconds = (new DateTimeImmutable())->getTimestamp() - $lastMessageTime->getTimestamp();
                    if ($diffSeconds < 1800) { // 30 minutes
                        $activeTicketId = $t['ticketId'];
                        $ticketData = $t; // Load existing ticket data
                        break;
                    }
                } catch (Exception $e) {
                    // Log invalid timestamp and continue, or treat as expired
                    error_log("Invalid timestamp for ticket {$t['ticketId']}: {$e->getMessage()}");
                }
            }
        }

        // Record the user's message in the conversation history
        // This needs to happen before calling the LLM to provide context
        if (!$activeTicketId) {
            // New ticket creation:
            $activeTicketId = 'TICKET-' . strtoupper(substr(md5($chatId . microtime()), 0, 8));
            $phoneNumber = $this->formatter->formatFromChatId($chatId);

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
                'conversation' => [], // Will be populated by addConversationMessage
            ];
            $this->firebase->saveTicket($ticketData); // Save initial ticket structure
            $this->firebase->addConversationMessage($activeTicketId, 'user', $message); // Add user message
        } else {
            // Existing ticket:
            $this->firebase->addConversationMessage($activeTicketId, 'user', $message);
        }

        // Debug log from original
        file_put_contents($this->logDir . 'whatsapp.log', print_r([$chatId, $message], true) . "\n", FILE_APPEND);

        // Get the AI reply from our WhatsApp LLM Bridge.
        // It's crucial for the LLM to have the full conversation context.
        // Assuming LLMWhatsAppBridge::chat handles fetching context or takes it as an argument
        $replyResponse = $this->AI->chat([['role' => 'user', 'content' => $message]], $chatId);

        // Debug log from original
        file_put_contents($this->logDir . 'whatsapp.log', print_r($replyResponse, true) . "\n", FILE_APPEND);

        $initialLLMReplyText = $replyResponse['content'][0]['text'] ?? '';

        // Filter every $llmReplyText that starts with "### " into concierge-specific log
        if (str_starts_with($initialLLMReplyText, '### ')) {
            file_put_contents($this->logDir . 'llm-reply-internal-' . time() . '.log', $initialLLMReplyText);
        }

        file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . __LINE__);

        // Extract category, severity, and subject from LLM reply and filter the text
        [$filteredReplyForUser, $category, $severity, $subject] = $this->extractAndCategorizeFromLLMReply(
            $initialLLMReplyText,
            $this->allowedCategories
        );
        file_put_contents($this->logDir . 'llm-reply-filtered-' . time() . '.log', print_r($filteredReplyForUser, true) . "\n", FILE_APPEND);

        // Update ticket metadata with extracted information and latest timestamp
        if ($ticketData) { // $ticketData should always be set here (new or existing)
            $ticketData['category'] = $category;
            $ticketData['priority'] = $severity;
            if (!empty($subject)) {
                $ticketData['summary'] = $subject;
            }
            $ticketData['timestamp'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $this->firebase->saveTicket($ticketData);
        }

        file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . $category . "\n", FILE_APPEND);
        $reply = $filteredReplyForUser;

        // Add assistant reply to Firebase
        try {
            file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
            $this->firebase->addConversationMessage($activeTicketId, 'assistant', $reply);
            file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
        } catch (DatabaseException $e) {
            file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
            file_put_contents(
                $this->logDir . 'firebase-error.log',
                '[' . date('c') . '] ' . $e->getMessage(),
                FILE_APPEND
            );
        }
        file_put_contents($this->logDir . 'extract-categories.log', date('c') . ' ' . __LINE__, FILE_APPEND);

        return ['reply' => $reply, 'ticketId' => $activeTicketId];
    }

    /**
     * Extracts CATEGORY, SEVERITY, and SUBJECT from LLM reply and removes them from the text.
     * This version processes the reply line by line and has been improved for
     * better reliability in detecting and filtering command lines.
     *
     * @param string $llmReplyText The raw text response from the LLM.
     * @param array $allowedCategories An array of valid categories to validate against.
     * @return array An array containing [filteredReplyText, extractedCategory, extractedSeverity, extractedSubject].
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
        $extractedSubject = '';               // Default subject

        // Pattern for internal notes that should be filtered out
        $internalNotePattern = '/^###\s*(.*)$/';

        // Pattern for metadata commands
        $commandPattern = '/^\+\+\+\s*(CATEGORY|SEVERITY|SUBJECT)\s*:\s*(.*)$/i';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // FIRST: Check if the line is an internal note (###) and filter it out
            if (preg_match($internalNotePattern, $trimmedLine)) {
                // This line is an internal note, skip it from the filtered output.
                continue;
            }

            // SECOND: Check if the line is a metadata command (+++)
            if (preg_match($commandPattern, $trimmedLine, $matches)) {
                // $matches[1] will be "CATEGORY", "SEVERITY", or "SUBJECT"
                // $matches[2] will be the value (e.g., "HVAC")
                $key = strtoupper($matches[1]);
                $value = trim($matches[2]);

                if ($key === 'CATEGORY') {
                    if (in_array($value, $allowedCategories, true)) {
                        $extractedCategory = $value;
                    }
                } elseif ($key === 'SEVERITY') {
                    $extractedSeverity = $value;
                } elseif ($key === 'SUBJECT') {
                    $extractedSubject = $value;
                }

                // This line is a command, so we skip adding it to the filtered output.
                continue;
            }

            // If the line is not an internal note or a special command, keep it for the user reply.
            // We add the original, unmodified line to preserve original formatting and indentation.
            $filteredLines[] = $line;
        }

        // Join the remaining lines back together and trim any leading/trailing whitespace
        // or newlines that might result from the filtering process.
        $filteredReply = trim(implode("\n", $filteredLines));

        return [$filteredReply, $extractedCategory, $extractedSeverity, $extractedSubject];
    }
}
