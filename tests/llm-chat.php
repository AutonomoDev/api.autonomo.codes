#!/usr/bin/env php
<?php
// ==== ./tests/llm-chat.php ====

// 1. Composer Autoloader and Environment setup
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    fwrite(STDERR, "Error: Composer autoloader not found. Please run 'composer install'.\n");
    exit(1);
}

if (file_exists(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();
} else {
    fwrite(STDERR, "Warning: .env file not found. Ensure environment variables are set.\n");
}

// 2. Import the service class
use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;

// --- Main Execution Logic ---
$command = $argv[1] ?? 'help';

try {
    $bridge = new LLMWhatsAppBridge();

    switch ($command) {
        case 'register-webhook':
            echo "----------------------------------------\n";
            echo "Attempting to register webhook...\n";

            // --- PHP cURL implementation for the POST request ---

            // Define constants/variables for the request
            $wahaApiBaseUrl = 'http://localhost:3000';
            $wahaSessionStartEndpoint = '/api/sessions/start';
            $wahaApiUrl = $wahaApiBaseUrl . $wahaSessionStartEndpoint;
            $wahaApiKey = env('WAHA_API_KEY'); // Get API key from environment
            $webhookUrl = 'http://localhost/webhook/whatsapp'; // Define the webhook URL for the request body

            // Validate API Key
            if (empty($wahaApiKey)) {
                throw new \Exception("WAHA_API_KEY environment variable is not set. Please check your ../.env file.");
            }

            // Request body (as a PHP array, then JSON encoded)
            $requestBodyData = [
                "name" => "hope",
                "config" => [
                    "webhooks" => [
                        [
                            "url" => $webhookUrl,
                            "events" => [
                                "message",
                                "message.ack",
                                "state.change"
                            ]
                        ]
                    ]
                ]
            ];
            $requestBody = json_encode($requestBodyData);

            // Initialize cURL
            $ch = curl_init($wahaApiUrl);

            // Set cURL options
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); // Equivalent to -X POST
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody); // Equivalent to -d
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
            curl_setopt($ch, CURLOPT_HTTPHEADER, [ // Equivalent to -H headers
                'accept: application/json',
                'Content-Type: application/json',
                "X-Api-Key: $wahaApiKey", // Use the fetched API key
                'Content-Length: ' . strlen($requestBody) // Good practice to include Content-Length
            ]);

            // Execute cURL request
            $rawResponse = curl_exec($ch);

            // Check for cURL errors
            if (curl_errno($ch)) {
                $errorMsg = curl_error($ch);
                curl_close($ch);
                throw new \Exception("cURL Error while registering webhook: " . $errorMsg);
            }

            // Get HTTP status code
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Close cURL session
            curl_close($ch);

            // Decode the response (assuming JSON)
            $response = json_decode($rawResponse, true); // Renamed to $response for clarity as per script's 'dump($response)'

            // Check for non-2xx status codes or API errors
            // (Adjust the success condition based on the actual WAHA API response for success)
            if ($httpCode >= 400 || !isset($response['session'])) {
                $errorDetails = $response['error'] ?? ($response['message'] ?? 'Unknown API Error');
                throw new \Exception(
                    "API Error registering webhook: HTTP Status " . $httpCode .
                    ", Details: " . (is_array($errorDetails) ? json_encode($errorDetails) : $errorDetails) .
                    ". Raw Response: " . $rawResponse
                );
            }

            echo "--- SUCCESS! ---\n";
            echo "WAHA Response: \n";
            dump($response); // dump the decoded array
            echo "Webhook for '$webhookUrl' registered successfully.\n";
            echo "Open the WAHA dashboard and scan the QR code for the 'default' session if needed.\n";
            echo "----------------------------------------\n";
            break;

        case 'chat-test-curl':
            echo "----------------------------------------\n";
            echo "Running chat test using cURL method...\n";
            $prompt = ['role' => 'user', 'content' => 'Test message via cURL.'];
            $bridge->chat($prompt);
            break;

        case 'chat-test-restspeaker':
            echo "----------------------------------------\n";
            echo "Running chat test using RESTSpeaker method...\n";
            $prompt = ['role' => 'user', 'content' => 'Test message via RESTSpeaker.'];
            $bridge->chat_RestSpeaker($prompt);
            break;

        case 'help':
        default:
            echo "WAHA Management & Test Script\n";
            echo "---------------------------------\n";
            echo "Usage: php tests/llm-chat.php [command]\n\n";
            echo "Commands:\n";
            echo "  register-webhook         Registers the webhook by starting/configuring the 'default' session.\n";
            echo "  chat-test-curl           Runs the test to send a message using the raw cURL method.\n";
            echo "  chat-test-restspeaker    Runs the test to send a message using the RESTSpeaker method.\n";
            echo "  help                     Shows this help message.\n";
            break;
    }
    exit(0); // Success
} catch (\Exception $e) {
    fwrite(STDERR, "--- SCRIPT ERROR ---\n");
    fwrite(STDERR, "Message: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . "\n");
    fwrite(STDERR, "Line: " . $e->getLine() . "\n");
    fwrite(STDERR, "----------------------------------------\n");
    exit(1); // Failure
}

