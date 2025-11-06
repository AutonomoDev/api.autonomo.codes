<?php declare(strict_types=1);
// ==== src/WAHA/Services/WhatsAppService.php ====

namespace Autonomo\API\WAHA\Services;

use Exception;
use RuntimeException;

class WhatsAppService
{
    private string $wahaURL;
    private string $apiKey;
    private bool $simulateApiCalls;

    public function __construct(bool $simulateApiCalls = false)
    {
        $this->simulateApiCalls = $simulateApiCalls;

        // Load .env variables (assuming dotenv is configured in your project's entry point)
        // If not, ensure these are defined via web server config or another method.
        $this->wahaURL = rtrim(env('WAHA_API_URL'), '/');
        $this->apiKey = env('WAHA_API_KEY');

        if (!$this->wahaURL || !$this->apiKey) {
            // For simulation mode, we don't necessarily need API keys, but good to warn.
            if (!$this->simulateApiCalls) {
                throw new RuntimeException("WAHA_API_URL and WAHA_API_KEY environment variables are required.");
            } else {
                error_log("WAHA Service in simulation mode: WAHA_API_URL or WAHA_API_KEY not set, but not critical.");
            }
        }
    }

    private function executeCurlCommand(string $endpoint, array $data): array
    {
        if ($this->simulateApiCalls) {
            error_log("SIMULATING WAHA API CALL: {$endpoint} with data: " . json_encode($data));
            // Simulate success for development purposes
            return ['status' => 'success', 'message' => 'Simulated API call successful.'];
        }

        $url = rtrim($this->wahaURL, '/') . '/' . ltrim($endpoint, '/');
        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->apiKey,
        ];
        $jsonData = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 second timeout for API calls

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            error_log("WAHA API cURL Error ($endpoint): " . $error);
            throw new RuntimeException("WAHA API call failed for endpoint '{$endpoint}': " . $error);
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 400 || $httpCode < 200) {
            $errorMessage = $responseData['error'] ?? $responseData['message'] ?? 'Unknown API error';
            error_log("WAHA API HTTP Error ($endpoint) - Code: {$httpCode}, Response: {$response}");
            throw new RuntimeException("WAHA API call to '{$endpoint}' returned HTTP {$httpCode}: {$errorMessage}");
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("WAHA API JSON Decode Error ($endpoint): " . json_last_error_msg() . " - Raw response: " . $response);
            throw new RuntimeException("Failed to decode JSON response from WAHA API for '{$endpoint}'.");
        }

        return $responseData;
    }

    public function sendText(string $chatId, string $text, ?string $replyTo = null): void
    {
        $data = ['chatId' => $chatId, 'text' => $text];
        if ($replyTo) {
            $data['options'] = ['quotedMessageId' => $replyTo];
        }
        $this->executeCurlCommand('api/sendText', $data);
    }

    public function sendSeen(string $chatId, string $messageId): void
    {
        $this->executeCurlCommand('api/sendSeen', ['chatId' => $chatId, 'messageId' => $messageId]);
    }

    public function startTyping(string $chatId): void
    {
        $this->executeCurlCommand('api/startTyping', ['chatId' => $chatId]);
    }

    public function stopTyping(string $chatId): void
    {
        $this->executeCurlCommand('api/stopTyping', ['chatId' => $chatId]);
    }

    public function sendReaction(string $messageId, string $reaction): void
    {
        // This endpoint might be different depending on WAHA version/implementation
        // Assuming it's 'api/sendReaction' with messageId and reaction.
        // If it fails silently in simulation, that's fine for dev.
        try {
            $this->executeCurlCommand('api/sendReaction', ['messageId' => $messageId, 'reaction' => $reaction]);
        } catch (Exception $e) {
            error_log("Failed to send reaction: " . $e->getMessage());
            // Reactions are non-critical, so we just log and continue.
        }
    }

    /**
     * Retrieve phone number from LID
     *
     * @param string $lid The LID to look up (e.g., "1111111@lid")
     * @param string $session The session name (default: 'default')
     * @return string The phone number without @c.us suffix (e.g., "3333333")
     * @throws \RuntimeException If the request fails or response is invalid
     */
    public function getPhoneNumberFromLid(string $lid, string $session = 'default'): string
    {
        $escapedApiKey = escapeshellarg($this->apiKey);
        $url = sprintf('%s/api/%s/lids/%s', $this->wahaURL, $session, urlencode($lid));

        $command = sprintf(
            "curl --silent -X 'GET' \\
              '%s' \\
              -H 'accept: application/json' \\
              -H 'X-Api-Key: %s'",
            $url,
            $escapedApiKey
        );

        $output = shell_exec($command);

        if ($output === null) {
            throw new \RuntimeException("Failed to execute curl command for LID lookup");
        }

        $response = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("WAHA LID lookup received invalid JSON response: " . $output);
        }

        if (!isset($response['pn'])) {
            throw new \RuntimeException("WAHA LID lookup response missing 'pn' field: " . json_encode($response));
        }

        $phoneNumber = str_replace('@c.us', '', $response['pn']);

        return $phoneNumber;
    }
}
