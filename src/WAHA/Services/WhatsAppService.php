<?php
// ==== ./src/WAHA/Services/WhatsAppService.php ====
namespace Autonomo\API\WAHA\Services;

use PHPExperts\RESTSpeaker\RESTSpeaker;
use Throwable;

class WhatsAppService
{
    private RESTSpeaker $api;

    public function __construct()
    {
        // NOTE: Make sure WAHA_API_URL and WAHA_API_KEY are correctly set in your environment.
        // The URL should be the base URL of your WAHA container, e.g., http://localhost:3000/api
        $this->api = new RESTSpeaker(rtrim($_ENV['WAHA_API_URL'], '/') . '/', [
            'Authorization' => 'Bearer ' . $_ENV['WAHA_API_KEY'],
        ]);
    }

    public function sendText(string $chatId, string $text, ?string $replyTo = null): void
    {
        $payload = [
            'chatId' => $chatId,
            'text'   => $text,
        ];

        // Allow replying to a specific message ID.
        if ($replyTo) {
            $payload['replyTo'] = $replyTo;
        }

        try {
            // Updated endpoint to /send/text based on common WAHA API structure.
            // Please verify the exact endpoint from your WAHA swagger docs.
            // Assuming /sendText is correct as per your original code.
            $this->api->post('sendText', $payload);
        } catch (Throwable $e) {
            error_log("WAHA sendText failed: " . $e->getMessage());
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
