<?php

declare(strict_types=1);

namespace BridgeBot\Tests;

use Bridge\Message\Incoming;
use BridgeBot\Integration\NewsletterFeed;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../integration/NewsletterFeed.php';

final class NewsletterFeedTest extends TestCase
{
    public function testOnlyApprovedAccountsAndRoomsReachTheFeed(): void
    {
        $snapshot = [];
        $feed = $this->feed($snapshot);
        $feed->record('telegram', new Incoming('public', 'Spoofed Owner', 'other', 'private conversation', '1'));
        $feed->record('telegram', new Incoming('private', 'Owner', 'owner', 'secret', '2'));
        $feed->record('telegram', new Incoming('public', 'Owner', null, 'anonymous', '3'));
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', '!api token', '4'));
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'bot echo', '5', own: true));
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'Working on the cache.', '6', quoted: 'someone else: secret', profileUrl: 'https://example.com/owner'));

        self::assertCount(1, $snapshot['messages']);
        self::assertSame('Working on the cache.', $snapshot['messages'][0]['text']);
        self::assertStringNotContainsString('secret', json_encode($snapshot));
        self::assertArrayNotHasKey('profileUrl', $snapshot['messages'][0]);
    }

    public function testEditsDeletesPurgesAndDuplicateDelivery(): void
    {
        $snapshot = [];
        $now = new \DateTimeImmutable('2026-10-11T10:00:00Z');
        $feed = new NewsletterFeed(['telegram' => ['owner']], ['telegram' => ['public']], static function (array $data) use (&$snapshot): void {
            $snapshot = $data;
        }, clock: static function () use (&$now): \DateTimeImmutable {
            return $now;
        });
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'original', '1'));
        $original = $snapshot['messages'][0];
        $now = $now->modify('+1 hour');
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'original', '1'));
        self::assertSame($original, $snapshot['messages'][0]);
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'expanded work', '1', edited: true));
        self::assertTrue($snapshot['messages'][0]['revised']);
        self::assertSame($original['created_at'], $snapshot['messages'][0]['created_at']);
        self::assertSame($now->format(DATE_ATOM), $snapshot['messages'][0]['updated_at']);
        $feed->record('telegram', Incoming::deletion('public', '1'));
        self::assertSame([], $snapshot['messages']);
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'another', '2'));
        $feed->record('telegram', Incoming::purge('public', 'owner'));
        self::assertSame([], $snapshot['messages']);
    }

    public function testRestartReappliesPolicyAndCapacityMarksPartialCoverage(): void
    {
        $snapshot = [];
        $feed = $this->feed($snapshot, 1);
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'first', '1'));
        $feed->record('telegram', new Incoming('public', 'Owner', 'owner', 'second', '2'));
        self::assertCount(1, $snapshot['messages']);
        self::assertSame('second', $snapshot['messages'][0]['text']);
        $next = new NewsletterFeed(['telegram' => ['different-owner']], ['telegram' => ['public']], static function (array $data) use (&$snapshot): void {
            $snapshot = $data;
        }, $snapshot['messages']);
        $next->persist();
        self::assertSame([], $snapshot['messages']);
    }

    public function testNumericPolicyValuesCannotBecomeAccountIdentities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NewsletterFeed::policy('{"telegram":[123]}');
    }

    private function feed(array &$snapshot, int $capacity = 500): NewsletterFeed
    {
        return new NewsletterFeed(['telegram' => ['owner']], ['telegram' => ['public']], static function (array $data) use (&$snapshot): void {
            $snapshot = $data;
        }, clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-11T10:00:00Z'), capacity: $capacity);
    }
}
