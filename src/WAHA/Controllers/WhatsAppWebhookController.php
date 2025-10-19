<?php

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\WhatsAppService;
use Autonomo\API\WAHA\Services\LLMService;

class WhatsAppWebhookController
{
    public function handle(): void
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $msg = $data['message'] ?? null;

        if (!$msg || empty($msg['text']) || empty($msg['from'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            return;
        }

        $chatId = $msg['from'];
        $text = trim($msg['text']);

        $llm = new LLMService();
        $reply = $llm->getReply($text);

        $wa = new WhatsAppService();
        $wa->sendText($chatId, $reply);

        echo json_encode(['status' => 'ok']);
    }
}
