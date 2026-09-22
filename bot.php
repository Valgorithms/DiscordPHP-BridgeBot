<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-BridgeBot project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

use Bridge\Bot;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Support\GatewayDiagnostics;
use Bridge\Telegram\TelegramConfig;
use Bridge\Telegram\TelegramConnector;
use Bridge\Twitch\TwitchConfig;
use Bridge\Twitch\TwitchConnector;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

// Walk up for the autoloader so a PHPacker binary, which runs from a different
// directory than the sources, still finds it.
$baseDir = __DIR__;
while (! is_file($baseDir . '/vendor/autoload.php')) {
    $parent = \dirname($baseDir);

    if ($parent === $baseDir) {
        fwrite(STDERR, "Could not find vendor/autoload.php — run `composer install`.\n");

        exit(1);
    }

    $baseDir = $parent;
}

require $baseDir . '/vendor/autoload.php';

// One environment for the whole process. Every connector reads its own settings
// out of it, so the file is parsed once and nothing disagrees about precedence.
$environment = Environment::load($baseDir . '/.env');

try {
    $config = Config::fromEnvironment($environment, $baseDir . '/storage/bridges.json');
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

$logger = new Logger('bridge');
$handler = new StreamHandler('php://stdout', Level::fromName($config->logLevel));

// `%context%` matters more than it looks. DiscordPHP reports a fatal gateway
// close as the message "not reconnecting - critical op code" with the code
// itself in the context — so a format without it prints a line that says the
// bot stopped and nothing about why.
$handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'H:i:s', true, true));
$logger->pushHandler($handler);

// Watch for that close and explain it. There is no event to listen for; the
// library only ever reports the code by logging it, so this reads it back off
// the record and prints the fix.
$logger->pushProcessor(static function (LogRecord $record): LogRecord {
    $op = GatewayDiagnostics::fromLogContext($record->message, $record->context);

    if ($op !== null) {
        // Straight to stderr rather than through the logger, which would
        // re-enter this processor, and so that it is visible at any log level.
        fwrite(STDERR, GatewayDiagnostics::report($op, (string) ($record->context['reason'] ?? '')));
    }

    return $record;
});

// One filesystem for the process: asynchronous where the host has ext-uv or
// ext-eio, durable and blocking where it does not. Either way a save never
// makes the loop wait on the caller's side.
$store = new Store($config->storePath, Filesystem::create());

// One Discord connection, and therefore one Discord\Http — which is where the
// rate-limit buckets live. Nothing else in the process may hold one.
$bot = new Bot($config, $store, ['logger' => $logger]);

// A connector is installed only when the environment carries its credentials,
// so a bot with only a Telegram token does not try to start a Twitch client and
// then report that it failed.
if (TwitchConfig::isConfigured($environment)) {
    $bot->addConnector(new TwitchConnector(TwitchConfig::fromEnvironment($environment)));
} else {
    $logger->info('[bridge] no Twitch credentials — skipping that connector');
}

if (TelegramConfig::isConfigured($environment)) {
    $bot->addConnector(new TelegramConnector(TelegramConfig::fromEnvironment($environment)));
} else {
    $logger->info('[bridge] no Telegram token — skipping that connector');
}

if ($bot->connectors() === []) {
    fwrite(STDERR, "No connectors are configured — there is nothing to bridge. See env.example.\n");

    exit(1);
}

if (! $config->hasOwner()) {
    $logger->warning(
        'no DISCORD_OWNER_ID set — operator-gated commands are unreachable and nothing will be '
        . 'DM\'d when a bridge breaks. That is the safe default; set one to change it.',
    );
}

$logger->info(sprintf(
    '[bridge] starting with %d connector(s): %s',
    count($bot->connectors()),
    implode(', ', array_keys($bot->connectors())),
));

// Ctrl-C has to put a queued write on the disk and hang up on every network:
// once the loop stops, a queued write would never run and the change somebody
// just made would be the one lost.
foreach ([\defined('SIGINT') ? SIGINT : null, \defined('SIGTERM') ? SIGTERM : null] as $signal) {
    if ($signal !== null && function_exists('pcntl_signal')) {
        $bot->getLoop()->addSignal($signal, static function () use ($bot): void {
            $bot->shutdown();
        });
    }
}

$bot->run();
