<?php
// ==== ./src/WAHA/Services/LlmService.php ====
namespace Autonomo\API\WAHA\Services;

// In the future, you would use your actual AI Speaker here.
// use Autonomo\AISpeaker\LLMSpeaker;

class LlmService
{
    // private LLMSpeaker $ai;

    public function __construct()
    {
        // When you're ready, you would initialize your AI client here.
        // $this->ai = new LLMSpeaker('Anthropic');
    }

    /**
     * Gets a reply from the LLM for the given text.
     *
     * @param string $inputText The user's message from WhatsApp.
     * @return string The AI-generated text response.
     */
    public function getReply(string $inputText): string
    {
        // In the future, you would make a real API call to the LLM here.
        // $prompt = ['role' => 'user', 'content' => $inputText];
        // $llmResponse = $this->ai->chat($prompt);

        // For now, we use the hardcoded response provided in LLMWhatsAppBridge
        // to simulate the AI's reply.
        $llmResponse = unserialize(<<<TXT
a:8:{s:5:"model";s:25:"claude-3-5-haiku-20241022";s:2:"id";s:28:"msg_01MPj19eusPavhfd5k5cMmVp";s:4:"type";s:7:"message";s:4:"role";s:9:"assistant";s:7:"content";a:1:{i:0;O:8:"stdClass":2:{s:4:"type";s:4:"text";s:4:"text";s:430:"Good day, Mr. Eltawil. I'm sorry to hear about the issue with your toilet in apartment 4502. I'll log a repair request right away. Our maintenance technician, Rajesh Sharma, will come to inspect and repair the toilet. He will be available tomorrow between 10:00 AM and 12:00 PM. His contact number is +971 50 623 8741. Please ensure someone is present in the apartment during this time. Is there anything else I can help you with?";}}s:11:"stop_reason";s:8:"end_turn";s:13:"stop_sequence";N;s:5:"usage";O:8:"stdClass":6:{s:12:"input_tokens";i:115;s:27:"cache_creation_input_tokens";i:0;s:23:"cache_read_input_tokens";i:0;s:14:"cache_creation";O:8:"stdClass":2:{s:25:"ephemeral_5m_input_tokens";i:0;s:25:"ephemeral_1h_input_tokens";i:0;}s:13:"output_tokens";i:116;s:12:"service_tier";s:8:"standard";}}
TXT
        );

        // Extract the text from the response structure.
        if (isset($llmResponse['content'][0]->text)) {
            return $llmResponse['content'][0]->text;
        }

        // Fallback response if the structure is unexpected.
        return "I'm sorry, I couldn't generate a response.";
    }
}
