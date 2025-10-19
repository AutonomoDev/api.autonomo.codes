<?php

namespace Autonomo\API\WAHA\Services;

class LLMService
{
    public function getReply(string $prompt): string
    {
        $payload = json_encode(['prompt' => $prompt]);
        $ch = curl_init($_ENV['LLM_API_URL']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($_ENV['LLM_API_KEY'] ?? 'none'),
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            return '[Error contacting LLM API]';
        }
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['reply'] ?? '[No response from LLM]';
    }
}
