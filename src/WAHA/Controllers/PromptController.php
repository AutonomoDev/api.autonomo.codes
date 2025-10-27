<?php

namespace Autonomo\API\WAHA\Controllers;

use Exception;

/**
 * Manages the prompt file, providing an API endpoint for updates
 * and a web interface for manual editing.
 *
 * This class requires a global `env()` function to be available for retrieving
 * environment variables like AUTONOMO_API_KEY and ADMIN_PASSWORD.
 */
class PromptController
{
    private string $storageDir;
    private string $targetFile;
    private string $apiKeyEnvVar = 'AUTONOMO_API_KEY';
    private string $adminPasswordEnvVar = 'ADMIN_PASSWORD';

    public function __construct()
    {
        $this->storageDir = __DIR__ . '/../../../storage';
        $this->targetFile = $this->storageDir . '/prompt.md';
        $this->ensureStorageDirectoryExists();
    }

    /**
     * Handles POST requests to save a prompt from API calls or web forms.
     */
    public function handle(): void
    {
        if (!$this->isApiRequestAuthenticated()) {
            $this->sendJsonResponse(401, 'error', 'Authentication failed. Missing or invalid API key.');
            return;
        }

        $promptContent = $this->getPromptContentFromRequest();

        if ($promptContent === false || empty(trim($promptContent))) {
            $this->sendJsonResponse(400, 'error', 'Request body or prompt content field cannot be empty.');
            return;
        }

        try {
            $backupFilePath = $this->backupExistingPrompt();
            $this->writeNewPrompt($promptContent);
            $this->sendSuccessResponse($backupFilePath);
        } catch (Exception $e) {
            error_log("Error in PromptController::handle: " . $e->getMessage());
            $this->sendJsonResponse(500, 'error', 'An internal server error occurred while saving the prompt.');
        }
    }

    /**
     * Displays a web interface for editing the prompt, protected by an admin password.
     */
    public function edit(): void
    {
        $errorMessage = '';
        if ($this->verifyAdminAccess($errorMessage)) {
            $currentContent = $this->getCurrentPromptContent();
            $this->renderPromptEditForm($currentContent);
        } else {
            $this->renderAdminLoginForm($errorMessage);
        }
    }

    /**
     * Verifies if the current request has a valid API key.
     */
    private function isApiRequestAuthenticated(): bool
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY']
            ?? getallheaders()['X-API-Key']
            ?? getallheaders()['x-api-key']
            ?? $_POST['api_key']
            ?? null;

        $expectedApiKey = env($this->apiKeyEnvVar);

        if (!$apiKey || !$expectedApiKey) {
            return false;
        }

        return hash_equals($expectedApiKey, $apiKey);
    }

    /**
     * Retrieves prompt content from either a raw request body or a form post field.
     * @return string|false The content string or false on failure.
     */
    private function getPromptContentFromRequest(): string|false
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data')) {
            return $_POST['prompt_content'] ?? '';
        }
        return file_get_contents('php://input');
    }

    /**
     * If the prompt file exists, renames it to a versioned backup file.
     * @return string|null The full path to the backup file, or null if no file was backed up.
     * @throws Exception if renaming fails.
     */
    private function backupExistingPrompt(): ?string
    {
        if (!file_exists($this->targetFile)) {
            return null;
        }

        $modificationTime = filemtime($this->targetFile);
        if ($modificationTime === false) {
            throw new Exception("Could not retrieve modification time for existing prompt file.");
        }

        $dateFormatter = date('Ymd_His', $modificationTime);
        $backupFileName = "prompt.{$dateFormatter}.md";
        $backupFilePath = $this->storageDir . '/' . $backupFileName;

        $counter = 1;
        while (file_exists($backupFilePath)) {
            $backupFileName = "prompt.{$dateFormatter}_{$counter}.md";
            $backupFilePath = $this->storageDir . '/' . $backupFileName;
            $counter++;
        }

        if (!rename($this->targetFile, $backupFilePath)) {
            throw new Exception("Could not rename existing prompt file for backup.");
        }

        error_log("Moved existing prompt file to: " . $backupFilePath);
        return $backupFilePath;
    }

    /**
     * Writes new content to the target prompt file.
     * @throws Exception if writing fails.
     */
    private function writeNewPrompt(string $content): void
    {
        if (file_put_contents($this->targetFile, $content, LOCK_EX) === false) {
            throw new Exception("Could not write the new prompt content to file.");
        }
    }

    /**
     * Sends the final success response, either as a file download or a JSON message.
     */
    private function sendSuccessResponse(?string $backupFilePath): void
    {
        if ($backupFilePath && file_exists($backupFilePath)) {
            $this->sendFileDownload($backupFilePath);
        } else {
            $this->sendJsonResponse(200, 'ok', 'Prompt saved successfully. No prior prompt existed to download.');
        }
    }

    /**
     * Sets headers and streams a file to the client for download.
     */
    private function sendFileDownload(string $filePath): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit;
    }

    /**
     * Sends a standardized JSON response and terminates the script.
     */
    private function sendJsonResponse(int $statusCode, string $status, string $message): void
    {
        if ($status === 'error') {
            error_log("API Error Response: [$statusCode] $message");
        }
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode(['status' => $status, 'message' => $message]);
    }

    /**
     * Checks for a valid admin password from a POST request.
     * @param string $errorMessage Passed by reference to update with an error message.
     */
    private function verifyAdminAccess(string &$errorMessage): bool
    {
        $adminPasswordRequired = env($this->adminPasswordEnvVar);

        if (!$adminPasswordRequired) {
            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
            if (hash_equals($adminPasswordRequired, $_POST['admin_password'])) {
                return true;
            }
            $errorMessage = 'Incorrect admin password.';
        }

        return false;
    }

    /**
     * Reads the content of the current prompt file.
     */
    private function getCurrentPromptContent(): string
    {
        if (!file_exists($this->targetFile)) {
            return "No prompt.md file exists yet. Start typing your new prompt!";
        }

        $content = file_get_contents($this->targetFile);
        if ($content === false) {
            error_log("Failed to read current prompt file for editing: " . $this->targetFile);
            return "Error: Could not read prompt file.";
        }

        return $content;
    }

    /**
     * Renders the admin password login form and terminates the script.
     */
    private function renderAdminLoginForm(string $errorMessage): void
    {
        http_response_code(401);
        $errorMessageHtml = $errorMessage ? "<p class='error-message'>{$errorMessage}</p>" : '';
        echo <<<HTML
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Admin Login - Edit Prompt</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; margin: 2em; background-color: #f8f9fa; color: #343a40; display: flex; justify-content: center; align-items: center; min-height: 90vh; }
        .login-container { background-color: #ffffff; padding: 2.5em; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 400px; width: 100%; text-align: center; }
        h1 { color: #007bff; border-bottom: 2px solid #007bff; padding-bottom: 0.5em; margin-bottom: 1.5em; font-size: 1.8em; }
        label { display: block; margin-bottom: 0.8em; font-weight: 600; color: #495057; text-align: left; }
        input[type="password"] { width: calc(100% - 2em); padding: 0.8em 1em; margin-bottom: 1.5em; border: 1px solid #ced4da; border-radius: 6px; font-size: 1em; }
        button { background-color: #007bff; color: white; padding: 0.9em 1.8em; border: none; border-radius: 6px; cursor: pointer; font-size: 1.1em; transition: background-color 0.2s; }
        button:hover { background-color: #0056b3; }
        .error-message { color: #dc3545; font-weight: bold; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 1em; border-radius: 5px; margin-top: 1.5em; }
    </style>
</head>
<body>
    <div class="login-container"><h1>Admin Access Required</h1>{$errorMessageHtml}
        <form action="" method="POST">
            <label for="admin_password">Admin Password:</label><input type="password" id="admin_password" name="admin_password" required autofocus><button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Renders the main prompt editing form and terminates the script.
     */
    private function renderPromptEditForm(string $currentContent): void
    {
        $apiKeyForForm = env($this->apiKeyEnvVar);
        $apiKeyHtml = $apiKeyForForm ? "<input type='hidden' name='api_key' value='" . htmlspecialchars($apiKeyForForm) . "'>" : "<p class='error-message'>Warning: AUTONOMO_API_KEY is not set. Saving will fail!</p>";
        $formAction = '/concierge/prompt';
        $currentPromptHtml = htmlspecialchars($currentContent);
        $errorMessageForPromptContent = str_starts_with($currentContent, 'Error:') ? "<p class='error-message'>Could not read prompt file.</p>" : "";

        echo <<<HTML
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Edit Prompt</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; margin: 2em; background-color: #f8f9fa; color: #343a40; line-height: 1.6; }
        h1 { color: #007bff; border-bottom: 2px solid #007bff; padding-bottom: 0.5em; margin-bottom: 1em; }
        form { background-color: #ffffff; padding: 2em; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); max-width: 800px; margin: 0 auto; }
        textarea { width: 100%; min-height: 400px; padding: 1em; margin-bottom: 1.5em; border: 1px solid #ced4da; border-radius: 6px; box-sizing: border-box; font-size: 1em; }
        label { display: block; margin-bottom: 0.8em; font-weight: 600; color: #495057; }
        button { background-color: #28a745; color: white; padding: 0.9em 1.8em; border: none; border-radius: 6px; cursor: pointer; font-size: 1.1em; transition: background-color 0.2s; }
        button:hover { background-color: #218838; }
        .api-key-note { font-size: 0.85em; color: #6c757d; margin-top: 1.5em; border-top: 1px dashed #e9ecef; padding-top: 1em; }
        .error-message { color: #dc3545; font-weight: bold; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 1em; border-radius: 5px; margin-bottom: 1.5em; }
    </style>
</head>
<body>
    <h1>Edit Current Prompt</h1>{$errorMessageForPromptContent}
    <form action='{$formAction}' method='POST'>
        <label for='prompt_content'>Prompt Content:</label><textarea id='prompt_content' name='prompt_content' placeholder='Enter your prompt content here...'>{$currentPromptHtml}</textarea>
        {$apiKeyHtml}<button type='submit'>Save Prompt</button>
        <p class='api-key-note'>The API key is embedded from server configuration to authenticate this save request.</p>
    </form>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Ensures the storage directory exists, creating it if necessary.
     */
    private function ensureStorageDirectoryExists(): void
    {
        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
                error_log("Failed to create storage directory: " . $this->storageDir);
            }
        }
    }
}
