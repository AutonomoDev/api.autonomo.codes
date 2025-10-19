#!/bin/env php
<?php declare(strict_types=1);

/**
 * This file is part of MiniApiBase, a PHP Experts, Inc., Project.
 *
 * Copyright © 2024-2025 PHP Experts, Inc.
 * Author: Theodore R. Smith <theodore@phpexperts.pro>
 *   GPG Fingerprint: 4BF8 2613 1C34 87AC D28F  2AD8 EB24 A91D D612 5690
 *   https://www.phpexperts.pro/
 *   https://github.com/PHPExpertsInc/MiniApiBase
 *
 * This file is licensed under the MIT License.
 */

use Minicli\Command\CommandCall;

if (php_sapi_name() !== 'cli') {
    exit;
}

require __DIR__ . '/vendor/autoload.php';

$frameworkPath = __DIR__ . '/.framework';

if (!is_dir($frameworkPath)) {
    /**
     * FRAMEWORK NOT FOUND
     * The application is running in a state where the user has likely just
     * `required` the package, and the framework files are not in the root.
     * We will only register a command to perform the installation.
     */
    $app = new Minicli\App(['theme' => 'unicorn']);
    $app->setSignature('Mini API Base Framework Installer');
    $app->getPrinter()->error('Framework not found. Run the install command to publish the necessary files.');

    $app->registerCommand('framework:install', function () use ($app) {
        $installerPath = __DIR__ . '/vendor/phpexperts/mini-api-base/bin/framework-install';
        if (!file_exists($installerPath)) {
            $app->getPrinter()->error("FATAL: Installer script not found at {$installerPath}");
            $app->getPrinter()->error("Please check your Composer installation.");
            return 1;
        }
        passthru(PHP_BINARY . ' ' . escapeshellarg($installerPath));
    }, '', 'Publishes the framework files to the project root.');

} else {
    /**
     * FRAMEWORK FOUND
     * This is the normal operational mode. We will proceed to boot the
     * framework and register the application's commands as intended.
     */
    require __DIR__ . '/.framework/boot.php';
    require __DIR__ . '/.framework/boot-cli.php';


    $app->registerCommand('greet', function (CommandCall $cli) use ($app, $p) {
        $args = $cli->getRawArgs();
        $name = $args[2] ?? 'World';
        echo $p->red("Hello, ")->bold()->lightCyan($name)->text("!\n");
    }, '<name>', 'Emits a salutation.');

    // Register new commands here...
    $app->registerCommand('new', function (CommandCall $cli) {
    }, '', 'new');
}

$app->runCommand($argv);
