<?php
// ==== ./src/WAHA/Services/WhatsAppService.php ====
namespace Autonomo\API\WAHA\Services;

use Autonomo\API\WAHA\Auth\WAHAAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;
use Throwable;

class WhatsAppService
{
    private RESTSpeaker $api;

    public function __construct()
    {
        // NOTE: Make sure WAHA_API_URL and WAHA_API_KEY are correctly set in your environment.
        // The URL should be the base URL of your WAHA container, e.g., http://localhost:3000/api
        // We'll keep the RESTSpeaker initialized but won't use it for sendText as per new requirements.
//        $auth = new WAHAAuth(env('WAHA_API_KEY'));
//        $this->api = new RESTSpeaker($auth, rtrim(env('WAHA_API_URL'), '/') . '/');
    }

    public function sendText(string $chatId, string $text, ?string $replyTo = null): void
    {
        $wahaApiUrl = env('WAHA_API_URL');
        $apiKey = env('WAHA_API_KEY');

        $payload = [
            'chatId' => $chatId,
            'text'   => $text,
            'session' => 'default',
        ];
//        file_put_contents('/srv/http/waha/')print_r(json_encode($payload, JSON_PRETTY_PRINT));

        // Allow replying to a specific message ID.
        if ($replyTo) {
            $payload['replyTo'] = $replyTo;
        }

        $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES);
//        print_r($jsonData);exit;

        // Escape the JSON data for safe inclusion in the shell command
        $escapedJsonData = escapeshellarg($jsonData);
//        $escapedApiKey = escapeshellarg($apiKey);
        $wahaApiUrl = rtrim($wahaApiUrl, '/');

        // Construct the curl command directly from the shell script provided.
        // We're replacing the dynamic parts with PHP variables.

        $command = sprintf(
            "curl -X 'POST' \\
              '%s/api/sendText' \\
              -H 'accept: application/json' \\
              -H 'Content-Type: application/json' \\
              -H 'X-Api-Key: %s' \\
              -d %s",
                $wahaApiUrl,
            $apiKey,
            $escapedJsonData
        );
        file_put_contents('/srv/http/waha/curl-' . time() . '.txt', $command);

        try {
            // Execute the command and capture its output (including stderr)
            $output = shell_exec($command . ' 2>&1');

            // Basic check for execution failure (e.g., curl command not found, or immediate shell error)
            if ($output === null) {
                throw new \RuntimeException("Failed to execute curl command. Check if 'curl' is installed and accessible. Command: {$command}");
            }

            // Attempt to decode the JSON response to check for API-level errors
            $response = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // If it's not valid JSON, it might be a general error message from curl or the server.
                error_log("WAHA sendText failed (non-JSON response or parse error). Command: {$command}. Output: {$output}");
                throw new \RuntimeException("WAHA sendText received invalid response: " . $output);
            }

            // WAHA API typically returns 'success: true' or an 'error' field on failure
            if (isset($response['success']) && $response['success'] === false) {
                $errorMessage = $response['message'] ?? 'Unknown error';
                error_log("WAHA sendText failed (API error). Command: {$command}. Message: {$errorMessage}. Output: {$output}");
                throw new \RuntimeException("WAHA API returned error: " . $errorMessage);
            } elseif (isset($response['error'])) { // Some APIs might just have an 'error' field
                error_log("WAHA sendText failed (API error). Command: {$command}. Error details: " . json_encode($response['error']) . ". Output: {$output}");
                throw new \RuntimeException("WAHA API returned error: " . json_encode($response['error']));
            }

            // Log success if no errors detected
            // error_log("WAHA sendText successful. Output: {$output}");

        } catch (Throwable $e) {
            error_log("WAHA sendText failed (via shell_exec): " . $e->getMessage());
        }
    }

    /**
     * NEW: Method to send a reaction to a message.
     * This improves user experience by showing the message is being processed.
     *
     * @param string $messageId The ID of the message to react to.
     * @param string $reaction  The emoji to use for the reaction. Use an empty string to remove.
     */
    public function sendReaction(string $messageId, string $reaction): void
    {
        $payload = [
            'messageId' => $messageId,
            'reaction'  => $reaction,
        ];

        try {
            // NOTE: The endpoint for reactions is typically /sendReaction.
            // Please confirm this with your specific WAHA engine's documentation.
            $this->api->post('sendReaction', $payload);
        } catch (Throwable $e) {
            // It's okay if this fails, so we just log it.
            error_log("WAHA sendReaction failed: " . $e->getMessage());
        }
    }
}
