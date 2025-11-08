<?php declare(strict_types=1);
// ==== src/WAHA/Controllers/WhatsAppWebhookController.php ====

namespace Autonomo\API\WAHA\Controllers;

use Autonomo\API\WAHA\Services\WhatsAppMessageProcessor;
use Exception;

class WhatsAppWebhookController
{
    private WhatsAppMessageProcessor $messageProcessor;

    public function __construct(
        // Inject paths and config needed for WhatsAppMessageProcessor.
        // For a real application, consider a dependency injection container.
        string $conversationStoragePath = __DIR__ . '/../../../storage/conversations',
        int $conversationTimeoutSeconds = 1800, // 30 minutes timeout
        string $firebaseServiceAccountPath = __DIR__ . '/../../../firebase-ai-concierge-94fe8-adminsdk-fbsvc-f03a2644a3.json',
        string $firebaseDatabaseUrl = 'https://ai-concierge-94fe8-default-rtdb.firebaseio.com/'
    ) {
        // Initialize the message processor. For the actual webhook, simulateMode should be false.
        $this->messageProcessor = new WhatsAppMessageProcessor(
            $conversationStoragePath,
            $conversationTimeoutSeconds,
            $firebaseServiceAccountPath,
            $firebaseDatabaseUrl,
            false
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
            error_log("WAHA Webhook received (DEBUG MODE): " . $raw);
        }
        file_put_contents($logDir . '/input.log', $raw . "\n", FILE_APPEND);

        try {
            // Delegate all core message processing to WhatsAppMessageProcessor.
            // This service encapsulates the conversation logic, LLM interaction,
            // and Firebase ticketing, ensuring proper conversation memory.
            $result = $this->messageProcessor->processPayload($raw);

            // The processor returns the structured response, which we can directly echo.
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($result);

        } catch (Exception $e) {
            error_log('Error in WhatsAppWebhookController: ' . $e->getMessage());
            file_put_contents($logDir . '/critical-exception-' . time() . '.log', $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

        }
    }
}
