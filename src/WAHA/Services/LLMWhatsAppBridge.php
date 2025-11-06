<?php
// ==== ./src/WAHA/Services/LLMWhatsAppBridge.php ====
namespace Autonomo\API\WAHA\Services;

use Autonomo\AiSpeaker\LLMSpeaker;

class LLMWhatsAppBridge
{
    private LLMSpeaker $ai;
    private PhoneNumberFormatter $phoneNumberFormatter;

    private function loadDotEnv(): void
    {
        // Define the .env path
        $envPath = realpath(__DIR__ . '/../../../');
        if ($envPath === false) {
            throw new \RuntimeException('Invalid .env path: ' . __DIR__ . '/../../../');
        }

        // Load environment variables safely
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->load();
    }

    public function __construct(?LLMSpeaker $ai = null, ?PhoneNumberFormatter $phoneNumberFormatter = null)
    {
        $this->ai = $ai ?? new LLMSpeaker('Anthropic');
        $this->phoneNumberFormatter = $phoneNumberFormatter ?? new PhoneNumberFormatter();

        $this->loadDotEnv();
    }

    /**
     * @param array $prompt
     * @return array
     * @throws \Exception
     */
    public function chat(array $prompt, $chatId): array
    {
        file_put_contents('/srv/http/waha/whatsapp-2.log', print_r([$chatId, $prompt], true) . "\n", FILE_APPEND);

        // Prepare the request data
        $apiKey = env('WAHA_API_KEY');
        if (!$apiKey) {
            file_put_contents('/srv/http/waha/no-key.txt',  date('c') . ': No WAHA_API_KEY', FILE_APPEND);
            throw new \RuntimeException('WAHA_API_KEY is not set in your .env file.');
        }

        $url = 'http://localhost:3000/api/sendText';
        $url = env('WAHA_API_URL') . '/api/sendText';

        // Get the phone number from PhoneNumberFormatter::formatFromChatId($chatId)
        $phone = $this->phoneNumberFormatter->formatFromChatId($chatId);

        $storage = __DIR__ . '/../../../storage/';
        $systemPrompt = file_get_contents($storage . '/prompt.md');
        $answers = file_get_contents($storage . '/faq.tsv');
        $tenants = file_get_contents($storage . '/tenants.tsv');
        $vendors = file_get_contents($storage . '/vendors.tsv');

        $systemPrompt = str_replace(
            ['[[TENANTS]]', '[[VENDORS]]', '[[FAQ]]'],
            [$tenants, $vendors, $answers],
            $systemPrompt
        );

        file_put_contents('/srv/http/waha/system.txt', $systemPrompt);
        // 1. Initialize an empty string to build the conversation content
        $promptString = "";
        file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n");

        // 2. Loop through each message in the $prompt array
        foreach ($prompt as $message) {
            file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n====\n" .print_r($prompt, true), FILE_APPEND);
            $role = $message['role'];
            $content = $message['content'];

            // 3. Append the formatted role and content to $promptString
            //    We'll capitalize the role for better readability and add two newlines
            //    to create a blank line between messages.
            $promptString .= strtoupper($role) . ": " . $content . "\n\n";
        }
        file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);

        // 4. Use rtrim to remove any trailing newlines that might be left from the last message
        $promptString = rtrim($promptString);
        file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);

        $actualPrompt = "Incoming phone number ($phone) --- \n" . $promptString;
        file_put_contents('/srv/http/waha/extract-categories.log', date('c') . ' ' . __LINE__ . "\n", FILE_APPEND);
        file_put_contents('/srv/http/waha/debug.txt', 'SYSTEM: ' . $systemPrompt . "\n\n" . $actualPrompt);
        $response = $this->ai->chat([['role' => 'user', 'content' => $actualPrompt]], $systemPrompt);
        file_put_contents('/srv/http/waha/llm-reply-' . time() . '.log', print_r($response, true) . "\n", FILE_APPEND);

        return $response;
    }
}
