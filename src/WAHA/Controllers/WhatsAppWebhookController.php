// ==== ./src/WAHA/Controllers/WhatsAppWebhookController.php ====
<?php

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\WhatsAppService;
// MODIFIED: We are now using our new, purpose-built LlmService
use Autonomo\API\WAHA\Services\LlmService;
use Exception;

class WhatsAppWebhookController
{
    public function handle(): void
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        error_log("WAHA Webhook received: " . $raw);

        // 1. Validate the event and payload structure.
        if (
            ($data['event'] ?? null) !== 'message' ||
            !isset($data['payload']) || !is_array($data['payload'])
        ) {
            http_response_code(200);
            echo json_encode(['status' => 'ignored_event']);
            return;
        }

        $payload = $data['payload'];
        $chatId = $payload['from'] ?? null;
        $messageId = $payload['id'] ?? null;
        $text = trim($payload['body'] ?? '');
        $isFromMe = $payload['fromMe'] ?? false;

        // 2. Ignore invalid, empty, or self-sent messages.
        if (!$chatId || !$messageId || $isFromMe || empty($text)) {
            http_response_code(200);
            echo json_encode(['status' => 'ignored_invalid_or_self_message']);
            return;
        }

        $wa = new WhatsAppService();

        try {
            // (UX) React to show the message is being processed.
            $wa->sendReaction($messageId, '⏳');

            // 3. Get the AI reply from our new LlmService.
            $llm = new LlmService();
            $reply = $llm->getReply($text);

            // 4. Send the reply using the WhatsAppService.
            $wa->sendText($chatId, $reply, $messageId);

            // (UX) Update reaction to show the task is complete.
            $wa->sendReaction($messageId, '✅');

            echo json_encode(['status' => 'ok', 'reply_sent' => true]);

        } catch (Exception $e) {
            error_log("Error in WhatsAppWebhookController: " . $e->getMessage());

            // Notify the user of an error.
            $wa->sendText($chatId, "I'm sorry, I encountered a server error. Please try again later.", $messageId);

            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
