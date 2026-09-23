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

namespace BridgeBot\Tests;

use Bridge\Actions\BridgeActions;
use Bridge\Actions\CoreActions;
use Bridge\Bot;
use Bridge\Capability\ProvidesActions;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Surface;
use Bridge\Config;
use Bridge\Connector;
use Bridge\Environment;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Telegram\TelegramConfig;
use Bridge\Telegram\TelegramConnector;
use Bridge\Twitch\TwitchConfig;
use Bridge\Twitch\TwitchConnector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;

/**
 * This is the only place both connectors exist at once, so it is the only place
 * their disagreements can be caught.
 *
 * Each package's own suite proves its own tree fits Discord's limits and that
 * its own names do not collide. Neither can prove the pair of them do, and that
 * is exactly what installing a second connector puts at risk.
 */
final class WiringTest extends TestCase
{
    /** Discord's cap on options, sub-commands and groups alike. */
    private const MAX_OPTIONS = 25;

    public function testBothConnectorsFitInOneCatalogue(): void
    {
        // The registry throws on a collision rather than letting the last
        // registration win, so building it at all is the assertion.
        $this->assertGreaterThan(40, $this->registry()->count());
    }

    public function testEachCommandFitsInsideDiscordsCaps(): void
    {
        // Discord rejects the *whole command* when either cap is exceeded, so
        // one command too many loses a connector's entire tree.
        foreach ($this->tree() as $qualifier => $groups) {
            $flat = count($groups[''] ?? []);
            $topLevel = $flat + count(array_diff(array_keys($groups), ['']));

            $this->assertLessThanOrEqual(self::MAX_OPTIONS, $topLevel, "/{$qualifier} has too many options");

            foreach ($groups as $group => $names) {
                if ($group !== '') {
                    $this->assertLessThanOrEqual(self::MAX_OPTIONS, count($names), "/{$qualifier} {$group}");
                }
            }
        }
    }

    public function testBothConnectorsBringACommandCalledBan(): void
    {
        // And both keep it. This is the case the qualifier exists for: without
        // one, installing the second package would shadow the first's.
        $registry = $this->registry();

        $this->assertTrue($registry->has('twitch ban'));
        $this->assertTrue($registry->has('telegram ban'));
        $this->assertNotSame($registry->get('twitch ban'), $registry->get('telegram ban'));
    }

    public function testNothingAtAllIsReachableUnqualified(): void
    {
        $registry = $this->registry();

        foreach (['ban', 'link', 'list', 'send', 'title', 'help', 'status'] as $bare) {
            $this->assertNull($registry->resolve([$bare])[0], "{$bare} resolves without a qualifier");
        }
    }

    public function testEachConnectorsCommandsReachEveryChat(): void
    {
        // The point of one bot rather than two: `!twitch title` typed in a
        // Telegram group sets the stream title.
        $registry = $this->registry();

        foreach ($this->connectors() as $connector) {
            $available = $registry->forSurface($connector->surface());

            $this->assertArrayHasKey('twitch title', $available, $connector->name() . ' cannot reach Twitch');
            $this->assertArrayHasKey('telegram send', $available, $connector->name() . ' cannot reach Telegram');
        }
    }

    public function testConfiguringABridgeIsDiscordOnly(): void
    {
        // Whoever runs `link` decides which Discord channel gets copied into a
        // public chat somewhere else. That decision is not made from the far
        // end of the bridge.
        $registry = $this->registry();

        foreach ($this->connectors() as $connector) {
            foreach (['link', 'unlink', 'reset'] as $verb) {
                $action = $registry->get($connector->name() . ' ' . $verb);

                $this->assertNotNull($action);
                $this->assertFalse(
                    $action->availableOn($connector->surface()),
                    $action->qualified() . ' is reachable from the network it configures',
                );
            }
        }
    }

    public function testTheConnectorsDeclareDifferentSurfaces(): void
    {
        [$twitch, $telegram] = array_values($this->connectors());

        $this->assertSame(500, $twitch->surface()->limit);
        $this->assertFalse($twitch->surface()->lines, 'IRC cannot carry a newline');

        $this->assertSame(4096, $telegram->surface()->limit);
        $this->assertTrue($telegram->surface()->lines);
    }

    public function testAFileFromTheOldSinglePlatformBotStillWorks(): void
    {
        // The shape DiscordPHP-TwitchBot wrote. Nothing should have to be
        // re-run for these bridges to survive the upgrade.
        $dir = sys_get_temp_dir() . '/bridgebot-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o777, true);
        $path = $dir . '/bridges.json';

        file_put_contents($path, (string) json_encode([
            'links' => ['1547425733851353088' => ['1549845016887951550' => 'coffeescrafts']],
        ]));

        $store = new Store($path, Filesystem::blocking());

        $this->assertTrue($store->migrated());
        $this->assertSame(['twitch'], $store->connectors());
        $this->assertSame('coffeescrafts', $store->links('twitch')->targetFor('1549845016887951550'));
        $this->assertSame([], $store->warnings());

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testTheBotAssemblesTheWayBotPhpBuildsIt(): void
    {
        // Every connector's `boot()` runs here — each builds its own client
        // from the environment — so a client option one of them rejects fails
        // this rather than the first real start. Nothing connects: the loop is
        // never run.
        $dir = sys_get_temp_dir() . '/bridgebot-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o777, true);

        $environment = Environment::fromArray([
            'DISCORD_TOKEN' => 'test.token.here',
            'TWITCH_CLIENT_ID' => 'cid',
            'TWITCH_NICK' => 'bot',
            'TELEGRAM_TOKEN' => '123:abc',
            'TELEGRAM_PREFIX' => '?',
        ], $dir . '/.env');

        $config = Config::fromEnvironment($environment, $dir . '/bridges.json');
        $bot = new Bot($config, new Store($config->storePath, Filesystem::blocking()), [
            'logger' => new NullLogger(),
            'loop' => new StreamSelectLoop(),
        ]);

        $bot->addConnector(new TwitchConnector(TwitchConfig::fromEnvironment($environment)));
        $bot->addConnector(new TelegramConnector(TelegramConfig::fromEnvironment($environment)));

        $this->assertSame(['twitch', 'telegram'], array_keys($bot->connectors()));
        $this->assertSame($this->registry()->count(), $bot->getActions()->count());
        $this->assertSame('?', $bot->connector('telegram')?->surface()->prefix);
        $this->assertSame('!', $bot->connector('twitch')?->surface()->prefix);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    /** @return array<string, Connector> */
    private function connectors(): array
    {
        $environment = Environment::fromArray([
            'TWITCH_CLIENT_ID' => 'cid',
            'TWITCH_NICK' => 'bot',
            'TELEGRAM_TOKEN' => '123:abc',
        ]);

        return [
            'twitch' => new TwitchConnector(TwitchConfig::fromEnvironment($environment)),
            'telegram' => new TelegramConnector(TelegramConfig::fromEnvironment($environment)),
        ];
    }

    /** Everything the bot would register, assembled exactly as {@see \Bridge\Bot} does. */
    private function registry(): ActionRegistry
    {
        $registry = new ActionRegistry();
        $registry->addAll(new CoreActions());

        foreach ($this->connectors() as $connector) {
            $registry->addAll(new BridgeActions($connector));

            if ($connector instanceof ProvidesActions) {
                $registry->addFrom($connector, $connector->name());
            }
        }

        return $registry;
    }

    /**
     * The catalogue as Discord will see it: command, then group, then name.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function tree(): array
    {
        $tree = [];

        foreach ($this->registry()->forSurface(Surface::discord()) as $action) {
            if ($action->slash === null) {
                continue;
            }

            $tree[$action->qualifier][$action->group ?? ''][] = $action->name;
        }

        return $tree;
    }
}
