<?php
// ==== ./src/WAHA/Auth/WAHAAuth.php ====
namespace Autonomo\API\WAHA\Auth;

use PHPExperts\RESTSpeaker\RESTAuth;

class WAHAAuth extends RESTAuth
{
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
}
