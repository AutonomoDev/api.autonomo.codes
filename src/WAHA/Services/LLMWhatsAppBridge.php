<?php
// ==== ./src/WAHA/Services/LLMWhatsAppBridge.php ====
namespace Autonomo\API\WAHA\Services;

use Autonomo\AiSpeaker\LLMSpeaker;
use Autonomo\API\WAHA\Auth\WAHAAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;


class LLMWhatsAppBridge
{
    private LLMSpeaker $ai;

    private function loadDotEnv(): void
    {
        // Define the .env path
        $envPath = realpath(__DIR__ . '/../../../');
        if ($envPath === false) {
            throw new \RuntimeException('Invalid .env path: ' . __DIR__ . '/../../../');
        }

        // Load environment variables safely
        $dotenv = \Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad(); // use load() for strict mode
    }

    public function __construct(?LLMSpeaker $ai = null)
    {
        $this->ai = $ai ?? new LLMSpeaker('Anthropic');

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

        // 1. Get the LLM response text (using your hardcoded data)
//        $llmResponse = unserialize(<<<TXT
//a:8:{s:5:"model";s:25:"claude-3-5-haiku-20241022";s:2:"id";s:28:"msg_01MPj19eusPavhfd5k5cMmVp";s:4:"type";s:7:"message";s:4:"role";s:9:"assistant";s:7:"content";a:1:{i:0;O:8:"stdClass":2:{s:4:"type";s:4:"text";s:4:"text";s:430:"Good day, Mr. Eltawil. I'm sorry to hear about the issue with your toilet in apartment 4502. I'll log a repair request right away. Our maintenance technician, Rajesh Sharma, will come to inspect and repair the toilet. He will be available tomorrow between 10:00 AM and 12:00 PM. His contact number is +971 50 623 8741. Please ensure someone is present in the apartment during this time. Is there anything else I can help you with?";}}s:11:"stop_reason";s:8:"end_turn";s:13:"stop_sequence";N;s:5:"usage";O:8:"stdClass":6:{s:12:"input_tokens";i:115;s:27:"cache_creation_input_tokens";i:0;s:23:"cache_read_input_tokens";i:0;s:14:"cache_creation";O:8:"stdClass":2:{s:25:"ephemeral_5m_input_tokens";i:0;s:25:"ephemeral_1h_input_tokens";i:0;}s:13:"output_tokens";i:116;s:12:"service_tier";s:8:"standard";}}
//TXT
//        );
//        $textToSend = $llmResponse['content'][0]->text;

        // 2. Prepare the request data
        $apiKey = env('WAHA_API_KEY');
        if (!$apiKey) {
            file_put_contents('/srv/http/waha/no-key.txt',  date('c') . ': No WAHA_API_KEY', FILE_APPEND);
            throw new \RuntimeException('WAHA_API_KEY is not set in your .env file.');
        }

        $url = 'http://localhost:3000/api/sendText';
        $url = env('WAHA_API_URL') . '/api/sendText';

        $phone = substr($chatId, 0, strpos($chatId, '@'));

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
