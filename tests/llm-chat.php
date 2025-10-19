#!/usr/bin/env php
<?php

// ==== Self-Executable PHP Script for LLMWhatsAppBridge ====

// 1. Composer Autoloader
//    Ensure this path is correct relative to where you run this script.
//    Assumes this script is in your project's root directory.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    fwrite(STDERR, "Error: Composer autoloader not found at " . __DIR__ . "/vendor/autoload.php\n");
    fwrite(STDERR, "Please run 'composer install' in your project's root directory, or adjust the path.\n");
    exit(1);
}

// 2. Load Environment Variables (e.g., for API keys)
//    If your project uses phpdotenv, uncomment the following lines
//    and ensure 'vlucas/phpdotenv' is installed via Composer.
/*
if (file_exists(__DIR__ . '/.env')) {
    try {
        (new Dotenv\Dotenv(__DIR__))->load();
    } catch (\Dotenv\Exception\InvalidPathException $e) {
        // Handle error if .env exists but is invalid
        fwrite(STDERR, "Warning: Could not load .env file. " . $e->getMessage() . "\n");
    }
} else {
    fwrite(STDERR, "Warning: .env file not found. Ensure API keys are set via other means or in environment.\n");
}
*/

// 3. Import the LLMWhatsAppBridge class
use Autonomo\API\WAHA\Services\LLMWhatsAppBridge;

try {
    echo "----------------------------------------\n";
    echo "Initializing LLMWhatsAppBridge...\n";

    // Instantiate the bridge. It will use AnthropicSpeaker by default
    // due to your constructor's default value.
    $bridge = new LLMWhatsAppBridge();

    // Define the prompt as an array.
    $promptText = 'Hi. My toilet will not flush. Please send a maintenance person..';
    $prompt = ['role' => 'user', 'content' => $promptText];

    echo "Sending prompt to LLM: '" . $prompt['content'] . "'\n";
    echo "----------------------------------------\n";

    // Call the chat method.
    // Note: The dd($response); in your LLMWhatsAppBridge::chat method
    // will output the response and then terminate this script.
    $response = $bridge->chat([$prompt]);
    dump($response);

    // If dd() wasn't present, you'd have an exit(0) here for success.
    // exit(0); // Success exit code

} catch (\Exception $e) {
    // Output error to stderr for shell scripts and also to stdout for user visibility
    fwrite(STDERR, "----------------------------------------\n");
    fwrite(STDERR, "An unexpected error occurred:\n");
    fwrite(STDERR, "Message: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . "\n");
    fwrite(STDERR, "Line: " . $e->getLine() . "\n");
    fwrite(STDERR, "----------------------------------------\n");
    exit(1); // Indicate failure
}
