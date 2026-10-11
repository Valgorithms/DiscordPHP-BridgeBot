<?php

declare(strict_types=1);

namespace BridgeBot\Integration;

use Bridge\Bot;
use Bridge\Message\Incoming;
use Bridge\Modules\Module;

/** An opt-in, bounded export of the owner's messages in approved public rooms. */
final class NewsletterFeed implements Module
{
    private array $messages = [];
    private readonly \Closure $save;
    private readonly \Closure $clock;
    private string $coverageStart;

    /**
     * @param array<string, list<string>> $authors Stable platform account IDs, never display names.
     * @param array<string, list<string>> $rooms   Operator-approved public room IDs.
     * @param callable(array): mixed     $save    Injected persistence, no chat or network effects.
     * @param list<array>                $saved   Previous messages, re-filtered against current policy.
     */
    public function __construct(
        private readonly array $authors,
        private readonly array $rooms,
        callable $save,
        array $saved = [],
        ?callable $clock = null,
        private readonly int $capacity = 500,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Newsletter feed capacity must be positive.');
        }
        $this->save = \Closure::fromCallable($save);
        $this->clock = $clock === null ? static fn (): \DateTimeImmutable => new \DateTimeImmutable() : \Closure::fromCallable($clock);
        $this->coverageStart = ($this->clock)()->format(DATE_ATOM);
        foreach ($saved as $message) {
            if (is_array($message) && $this->allowed($message['platform'] ?? '', $message['room'] ?? '', $message['author_id'] ?? '')
                && is_string($message['id'] ?? null) && is_string($message['text'] ?? null)
                && is_string($message['created_at'] ?? null) && is_string($message['updated_at'] ?? null)) {
                $this->messages[$this->key($message['platform'], $message['room'], $message['id'])] = $message;
            }
        }
    }

    public function name(): string
    {
        return 'newsletter-feed';
    }

    public function boot(Bot $bot): void
    {
        foreach ($bot->connectors() as $platform => $connector) {
            $connector->onIncoming(fn (Incoming $message) => $this->record($platform, $message));
        }
        $bot->getLoop()->addPeriodicTimer(300, fn () => $this->persist());
        $this->persist();
    }

    /** Records original text only: never quotes, attachments, profiles, or command replies. */
    public function record(string $platform, Incoming $message): void
    {
        if ($message->own || ! in_array($message->target, $this->rooms[$platform] ?? [], true)) {
            return;
        }
        if ($message->deleted) {
            if ($message->id !== null) {
                unset($this->messages[$this->key($platform, $message->target, $message->id)]);
            } elseif ($message->authorId !== null) {
                $this->messages = array_filter($this->messages, static fn (array $m): bool => ! ($m['platform'] === $platform && $m['room'] === $message->target && $m['author_id'] === $message->authorId));
            }
            $this->persist();

            return;
        }
        if (! $this->allowed($platform, $message->target, $message->authorId ?? '') || $message->id === null) {
            return;
        }
        $key = $this->key($platform, $message->target, $message->id);
        // Do not archive commands or empty edits; an edit removes the old excerpt too.
        $text = trim((string) preg_replace('/\s+/u', ' ', $message->text ?? ''));
        if ($text === '' || preg_match('/^[!\/]/u', $text)) {
            unset($this->messages[$key]);
            $this->persist();

            return;
        }
        $text = mb_substr($text, 0, 600);
        if (($this->messages[$key]['text'] ?? null) === $text) {
            return; // reconnect duplicates and unchanged edits are not new activity
        }
        $now = ($this->clock)()->format(DATE_ATOM);
        $created = $this->messages[$key]['created_at'] ?? $now;
        $revised = $message->edited || isset($this->messages[$key]);
        unset($this->messages[$key]);
        $this->messages[$key] = ['platform' => $platform, 'room' => $message->target, 'author_id' => $message->authorId,
            'id' => $message->id, 'text' => $text, 'created_at' => $created, 'updated_at' => $now, 'revised' => $revised];
        $this->persist();
    }

    public function persist(): void
    {
        $now = ($this->clock)();
        foreach ($this->messages as $key => $message) {
            try {
                $old = new \DateTimeImmutable($message['updated_at']);
            } catch (\Exception) {
                unset($this->messages[$key]);

                continue;
            }
            if ($old < $now->modify('-14 days')) {
                unset($this->messages[$key]);
            }
        }
        while (count($this->messages) > $this->capacity) {
            $removed = array_shift($this->messages);
            $this->coverageStart = max($this->coverageStart, $removed['updated_at']);
        }
        ($this->save)(['version' => 1, 'coverage_start' => $this->coverageStart, 'updated_at' => $now->format(DATE_ATOM), 'messages' => array_values($this->messages)]);
    }

    private function allowed(mixed $platform, mixed $room, mixed $author): bool
    {
        return is_string($platform) && is_string($room) && is_string($author) && $author !== ''
            && in_array($author, $this->authors[$platform] ?? [], true) && in_array($room, $this->rooms[$platform] ?? [], true);
    }

    private function key(string $platform, string $room, string $id): string
    {
        return json_encode([$platform, $room, $id], JSON_THROW_ON_ERROR);
    }

    /** Validates environment policy; all IDs must be strings to avoid numeric coercion. */
    public static function policy(string $json): array
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Newsletter policy must be an object keyed by platform.');
        }
        foreach ($data as $platform => $ids) {
            if (! is_string($platform) || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $platform)
                || ! is_array($ids) || ! array_is_list($ids)) {
                throw new \InvalidArgumentException('Newsletter policy contains an invalid platform or ID list.');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || trim($id) === '') {
                    throw new \InvalidArgumentException('Newsletter policy IDs must be nonempty strings.');
                }
            }
        }

        return $data;
    }
}
