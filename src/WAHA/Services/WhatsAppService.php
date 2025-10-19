<?php

namespace Autonomo\API\WAHA\Services;

use PHPExperts\RESTSpeaker\RESTSpeaker;

class WhatsAppService
{
    private RESTSpeaker $api;

    public function __construct()
    {
        $this->api = new RESTSpeaker($_ENV['WAHA_API_URL'], [
            'Authorization' => 'Bearer ' . $_ENV['WAHA_API_KEY'],
        ]);
    }

    public function sendText(string $chatId, string $text): void
    {
        $this->api->post('/sendText', [
            'chatId' => $chatId,
            'text'   => $text,
        ]);
    }
}
