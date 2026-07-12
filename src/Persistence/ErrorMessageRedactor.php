<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * Shared exception-message sanitizer for BOTH the v1 linear engine and the
 * graph executor — so an error message persisted from either path (or
 * surfaced in an audit payload) goes through the same redaction, not a
 * carve-out. Two layers compose: (1) the resolved {@see PayloadRedactor} is
 * offered the message under `error_message`/`message` keys, honoring a host
 * app's custom redactor binding; (2) built-in heuristics scan the raw TEXT
 * for `key=value` / `key: value` / quoted-JSON patterns matching the
 * configured redaction keys, plus any `Bearer <token>` regardless of key
 * configuration — an exception message is free-form prose, not a structured
 * payload, so key-based dict redaction alone cannot catch a secret embedded
 * in a sentence.
 *
 * @internal
 */
final class ErrorMessageRedactor
{
    /**
     * @param  array<string, mixed>  $redaction  the `laravel-flow.persistence.redaction` config sub-array (`enabled`, `keys`, `replacement`)
     */
    public function __construct(
        private readonly array $redaction,
    ) {}

    public function redact(string $message, PayloadRedactor $redactor): string
    {
        $message = $this->redactWithPayloadRedactor($message, $redactor);

        if ((bool) ($this->redaction['enabled'] ?? true) === false) {
            return $message;
        }

        $replacement = (string) ($this->redaction['replacement'] ?? '[redacted]');
        $keys = array_values(array_filter((array) ($this->redaction['keys'] ?? []), 'is_string'));
        $message = $this->redactBearerTokens($message, $replacement);
        $message = $this->redactConfiguredKeyValues($message, $keys, $replacement);

        foreach ($keys as $key) {
            $keyPattern = $this->keyPattern($key);
            $message = preg_replace_callback(
                '/\b('.$keyPattern.')\b(\s*[:=]\s*)(?:Bearer\s+)?([^\s,;]+)/i',
                static fn (array $matches): string => $matches[1].$matches[2].$replacement,
                $message,
            ) ?? $message;
            $message = preg_replace_callback(
                '/(["\']'.$keyPattern.'["\']\s*:\s*["\'])([^"\']+)(["\'])/i',
                static fn (array $matches): string => $matches[1].$replacement.$matches[3],
                $message,
            ) ?? $message;
        }

        return $this->redactBearerTokens($message, $replacement);
    }

    private function redactWithPayloadRedactor(string $message, PayloadRedactor $redactor): string
    {
        $redactor = PayloadRedactorResolution::current($redactor);
        $redacted = $redactor->redact([
            'error_message' => $message,
            'message' => $message,
        ]);

        foreach (['error_message', 'message'] as $key) {
            if (isset($redacted[$key]) && is_string($redacted[$key]) && $redacted[$key] !== $message) {
                return $redacted[$key];
            }
        }

        return $message;
    }

    /**
     * @param  list<string>  $keys
     */
    private function redactConfiguredKeyValues(string $message, array $keys, string $replacement): string
    {
        $normalizedKeys = [];

        foreach ($keys as $key) {
            $normalizedKeys[$this->normalizeKey($key)] = true;
        }

        if ($normalizedKeys === []) {
            return $message;
        }

        return preg_replace_callback(
            '/\b([A-Za-z][A-Za-z0-9_-]*)\b(\s*[:=]\s*)(?:Bearer\s+)?([^\s,;]+)/i',
            function (array $matches) use ($normalizedKeys, $replacement): string {
                if (! isset($normalizedKeys[$this->normalizeKey((string) $matches[1])])) {
                    return (string) $matches[0];
                }

                return $matches[1].$matches[2].$replacement;
            },
            $message,
        ) ?? $message;
    }

    private function redactBearerTokens(string $message, string $replacement): string
    {
        return preg_replace_callback(
            '/\bBearer\s+([A-Za-z0-9._~+\/=-]+)/i',
            static fn (): string => 'Bearer '.$replacement,
            $message,
        ) ?? $message;
    }

    private function keyPattern(string $key): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $parts = preg_split('/[^A-Za-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || count($parts) <= 1) {
            $characters = preg_split('//', $key, -1, PREG_SPLIT_NO_EMPTY);

            if ($characters === false || $characters === []) {
                return '(?!)';
            }

            return implode('[_\-\s]*', array_map(
                static fn (string $character): string => preg_quote($character, '/'),
                $characters,
            ));
        }

        return implode('[_\-\s]*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $parts,
        ));
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }
}
