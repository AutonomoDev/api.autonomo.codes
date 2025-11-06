<?php declare(strict_types=1);
// ==== public/ajax.php ====
require __DIR__ . '/../vendor/autoload.php';

use Autonomo\API\WAHA\Services\ConversationService;
use Autonomo\API\WAHA\Services\WhatsAppMessageProcessor;

header('Content-Type: application/json');

// IP whitelist check
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedPrefixes = ['127.0.0.', '172.17.0.'];
$isAllowed = false;

foreach ($allowedPrefixes as $prefix) {
    if (strpos($remoteIp, $prefix) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access forbidden: Invalid IP address.']);
    exit;
}

$projectRoot = __DIR__ . '/..';

$logDir = $projectRoot . '/storage';
$queueFilePath = $logDir . '/webhook_queue.json';
$conversationStoragePath = $logDir . '/conversations';
$firebaseServiceAccountPath = $projectRoot . '/firebase-ai-concierge-94fe8-adminsdk-fbsvc-f03a2644a3.json';
$firebaseDatabaseUrl = 'https://ai-concierge-94fe8-default-rtdb.firebaseio.com/';
$conversationTimeoutSeconds = 900;


$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'simulate_message':
            $chatId = $_POST['chat_id'] ?? null;
            $userMessage = $_POST['user_message'] ?? null;

            if (!$chatId || !$userMessage) {
                throw new Exception("Missing chat_id or user_message for simulation.");
            }

            // For interactive simulation, simulateMode is TRUE (no real WhatsApp interaction)
            $processor = new WhatsAppMessageProcessor(
                $conversationStoragePath,
                $conversationTimeoutSeconds,
                $firebaseServiceAccountPath,
                $firebaseDatabaseUrl,
                true // simulateMode = TRUE for interactive messages
            );

            $simulationResult = $processor->simulateIncomingMessage($chatId, $userMessage);
            echo json_encode(['status' => 'success', 'message' => 'Message simulated successfully.', 'result' => $simulationResult]);
            break;

        case 'get_conversation_history':
            $chatId = $_GET['chat_id'] ?? null;
            if (!$chatId) {
                throw new Exception("Missing chat_id for conversation history.");
            }

            // Need a separate instance of ConversationService to fetch history
            $conversationService = new ConversationService(
                $conversationStoragePath,
                $conversationTimeoutSeconds
            );
            $conversation = $conversationService->getActiveConversation($chatId);

            // Return only messages, or an empty array if no conversation
            echo json_encode(['status' => 'success', 'history' => $conversation['messages'] ?? []]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
            break;
    }
} catch (Exception $e) {
    error_log('Error in WAHA AJAX backend: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
