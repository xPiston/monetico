<?php

declare(strict_types=1);

namespace Piston\Monetico\Service;

use Piston\Monetico\Exception\ServiceFailed;

/**
 * The body both server-to-server services answer with: `key=value` lines
 * separated by a line feed.
 *
 * Shared by capture and refund because it is literally the same format, and
 * because the two things worth doing carefully - tolerating `\r\n`, and
 * keeping fields nobody has mapped yet - are worth doing once.
 *
 * Note what is *not* here: a seal. Neither reply is sealed, so the integrity
 * of this message rests on TLS alone. That is the platform's design and not
 * something a library can improve on; it is worth knowing because it means a
 * capture must never be considered "probably fine" after a transport error -
 * it has to be asked about again.
 */
final class ServiceReply
{
    /** @param array<string, string> $fields */
    private function __construct(private readonly array $fields) {}

    public static function parse(string $body): self
    {
        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $fields[trim($name)] = trim($value);
        }

        if (! isset($fields['cdr'])) {
            // Without `cdr` there is no result in here at all - an HTML error
            // page, a proxy notice, an empty body.
            throw ServiceFailed::unreadableReply($body);
        }

        return new self($fields);
    }

    public function returnCode(): int
    {
        return (int) ($this->fields['cdr'] ?? 0);
    }

    public function label(): string
    {
        return $this->fields['lib'] ?? '';
    }

    public function version(): string
    {
        return $this->fields['version'] ?? '';
    }

    public function get(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
