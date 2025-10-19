<?php declare(strict_types=1);
// ==== ./src/Middleware/ApiKeyMiddleware.php ====

namespace Autonomo\API\Middleware;

use Pecee\Http\Request;
use Pecee\Http\Response;

class ApiKeyMiddleware
{
    /**
     * The expected API key.
     * @var string
     */
    protected string $expectedApiKey;

    /**
     * @param string $expectedApiKey The API key to validate against.
     */
    public function __construct(string $expectedApiKey)
    {
        $this->expectedApiKey = $expectedApiKey;
    }

    /**
     * Handles the incoming request and validates the X-API-Key header.
     *
     * @param Request $request
     * @return Request|Response
     */
    public function handle(Request $request)
    {
        $apiKey = $request->getHeader('X-API-Key');

        if (!$apiKey || $apiKey !== $this->expectedApiKey) {
            response()->httpCode(401);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Invalid or missing API Key.'
            ]);
        }

        return $request;
    }
}
