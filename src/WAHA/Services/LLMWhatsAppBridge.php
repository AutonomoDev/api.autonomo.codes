<?php
namespace Autonomo\API\WAHA\Services;

use Autonomo\AISpeaker\LLMSpeaker;
use PHPExperts\RESTSpeaker\RESTAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;


class LLMWhatsAppBridge
{
    private LLMSpeaker $ai;

    private function loadDotEnv(): void
    {
        // Only load Dotenv if not already loaded
        if (!class_exists(\Dotenv\Dotenv::class)) {
            // Try to include Composer's autoloader
            $autoload = __DIR__ . '/../../../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new \RuntimeException("Composer autoload not found at: {$autoload}");
            }
        }

        // Verify vlucas/phpdotenv is installed
        if (!class_exists(\Dotenv\Dotenv::class)) {
            throw new \RuntimeException('vlucas/phpdotenv is not installed. Run: composer require vlucas/phpdotenv');
        }

        // Define the .env path
        $envPath = realpath(__DIR__ . '/../../../');
        if ($envPath === false) {
            throw new \RuntimeException('Invalid .env path: ' . __DIR__ . '/../../../');
        }

        // Load environment variables safely
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad(); // use load() for strict mode
    }

    public function __construct(LLMSpeaker $ai = null)
    {
        $this->ai = $ai ?? new LLMSpeaker('Anthropic');

        $this->loadDotEnv();
    }

    /**
     * @param array $prompt
     * @return array
     * @throws \Exception
     */
    public function chat(array $prompt): array
    {
        // 1. Get the LLM response text (using your hardcoded data)
        $llmResponse = unserialize(<<<TXT
a:8:{s:5:"model";s:25:"claude-3-5-haiku-20241022";s:2:"id";s:28:"msg_01MPj19eusPavhfd5k5cMmVp";s:4:"type";s:7:"message";s:4:"role";s:9:"assistant";s:7:"content";a:1:{i:0;O:8:"stdClass":2:{s:4:"type";s:4:"text";s:4:"text";s:430:"Good day, Mr. Eltawil. I'm sorry to hear about the issue with your toilet in apartment 4502. I'll log a repair request right away. Our maintenance technician, Rajesh Sharma, will come to inspect and repair the toilet. He will be available tomorrow between 10:00 AM and 12:00 PM. His contact number is +971 50 623 8741. Please ensure someone is present in the apartment during this time. Is there anything else I can help you with?";}}s:11:"stop_reason";s:8:"end_turn";s:13:"stop_sequence";N;s:5:"usage";O:8:"stdClass":6:{s:12:"input_tokens";i:115;s:27:"cache_creation_input_tokens";i:0;s:23:"cache_read_input_tokens";i:0;s:14:"cache_creation";O:8:"stdClass":2:{s:25:"ephemeral_5m_input_tokens";i:0;s:25:"ephemeral_1h_input_tokens";i:0;}s:13:"output_tokens";i:116;s:12:"service_tier";s:8:"standard";}}
TXT
        );
        $textToSend = $llmResponse['content'][0]->text;

        // 2. Prepare the request data
        $apiKey = env('WAHA_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('WAHA_API_KEY is not set in your .env file.');
        }

        $url = 'http://localhost:3000/api/sendText';

        $payload = [
            'chatId' => '971543998492@c.us',
            'text' => $textToSend,
            'session' => 'default',
        ];

        // 3. Set up the cURL request
        $ch = curl_init();
        $finalResponse = [];

        try {
            // Encode the payload to a JSON string
            $jsonPayload = json_encode($payload);

            // Prepare the headers array, just like in your shell script
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ];

            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true); // Set the request method to POST
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload); // Attach the JSON payload
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); // Set the headers
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string instead of printing it
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Connection timeout in seconds
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Total request timeout in seconds

            // 4. Execute the request and get the response
            echo "--- Sending cURL Request ---\n";
            echo "URL: $url\n";
            echo "Payload: $jsonPayload\n";
            echo "----------------------------\n";

            $responseBody = curl_exec($ch);

            // 5. Check for cURL errors (e.g., connection failed, empty reply)
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                $error_no = curl_errno($ch);
                // This will now give you a useful error message instead of just dying
                throw new \Exception("cURL Error ($error_no): $error_msg for $url");
            }

            // 6. Check the HTTP status code
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatusCode >= 400) {
                throw new \Exception("HTTP Error: Received status code $httpStatusCode. Response: $responseBody");
            }

            // 7. Decode the successful response
            $finalResponse = json_decode($responseBody, true);

            echo "--- SUCCESS: Received Response ---\n";
            dump($finalResponse);
            echo "--------------------------------\n";

        } finally {
            // ALWAYS close the cURL handle, even if errors occurred
            curl_close($ch);
        }

        return $finalResponse;
    }

    /**
     * @param string[] $prompt
     * @return array
     * @throws \Exception
     */
    public function chat_RestSpeaker(array $prompt): array
    {
        $response = unserialize(<<<TXT
a:8:{s:5:"model";s:25:"claude-3-5-haiku-20241022";s:2:"id";s:28:"msg_01MPj19eusPavhfd5k5cMmVp";s:4:"type";s:7:"message";s:4:"role";s:9:"assistant";s:7:"content";a:1:{i:0;O:8:"stdClass":2:{s:4:"type";s:4:"text";s:4:"text";s:430:"Good day, Mr. Eltawil. I'm sorry to hear about the issue with your toilet in apartment 4502. I'll log a repair request right away. Our maintenance technician, Rajesh Sharma, will come to inspect and repair the toilet. He will be available tomorrow between 10:00 AM and 12:00 PM. His contact number is +971 50 623 8741. Please ensure someone is present in the apartment during this time. Is there anything else I can help you with?";}}s:11:"stop_reason";s:8:"end_turn";s:13:"stop_sequence";N;s:5:"usage";O:8:"stdClass":6:{s:12:"input_tokens";i:115;s:27:"cache_creation_input_tokens";i:0;s:23:"cache_read_input_tokens";i:0;s:14:"cache_creation";O:8:"stdClass":2:{s:25:"ephemeral_5m_input_tokens";i:0;s:25:"ephemeral_1h_input_tokens";i:0;}s:13:"output_tokens";i:116;s:12:"service_tier";s:8:"standard";}}
TXT
);
//        $response = $this->ai->chat($prompt);
//        print serialize($response) . "\n"; exit;
        $conversation[0] = $prompt[0];
        $conversation[1] = $response['content'][0]->text;


        // Create a RESTSpeaker instance pointing to your local WAHA API
        $apiKey = env('WAHA_API_KEY');
        $auth = new class($apiKey) extends RESTAuth {
            public function __construct(private string $apiKey)
            {
                parent::__construct(self::AUTH_MODE_XAPI);
            }

            protected function generateXAPITokenOptions(): array
            {
                return [
                    'headers' => [
                        'X-Api-Key' => $this->apiKey,
                    ]
                ];
            }
        };

        $api = new RESTSpeaker($auth, 'http://localhost:3000');

        // Define your payload
        $payload = [
            'chatId' => '971543998492@c.us',
            'text' => $conversation[1],
            'session' => 'default',
        ];

        dump($payload);
        try {
            $api->http->enableCuzzle = true;
            // Send the request
            $response = $api->post(
                '/api/sendText',
                $payload, [
                    'headers' => [
                        'Accept' => 'application/json',
                    ]
                ]
        );
        } catch (\Exception $e) {
            dump($api->getLastResponse());
            dd(            $api->http->testHandler->getRecords());
        }
        dump($api->getLastResponse());
        dump($response);

        return [];
    }
}