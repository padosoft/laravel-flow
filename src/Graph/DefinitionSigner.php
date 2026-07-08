<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\WebhookDeliveryClient;

/**
 * Optional HMAC-SHA256 signing for stored {@see GraphDefinition} checksums.
 * Mirrors {@see WebhookDeliveryClient}'s secret
 * handling: there is no separate enabled flag — a null/blank secret
 * disables signing entirely, and a non-blank secret enables it.
 *
 * Design decision: {@see self::verify()} is tolerant in one direction only.
 * While disabled (no secret configured) it always returns true, even for a
 * record that already carries a signature from before signing was turned
 * off — so previously signed rows keep loading normally after signing is
 * disabled. Once enabled, verification is strict: a missing or mismatching
 * signature always fails, including for rows written before signing was
 * turned on.
 *
 * @api
 */
final class DefinitionSigner
{
    public function __construct(private readonly ?string $secret = null) {}

    /**
     * Lets callers skip signature work entirely (e.g. recomputing a
     * checksum from a large stored graph) when no secret is configured.
     */
    public function isEnabled(): bool
    {
        return ($this->secret ?? '') !== '';
    }

    /**
     * @return string|null the hex-encoded HMAC, or null when signing is disabled
     */
    public function sign(string $checksum): ?string
    {
        if (($this->secret ?? '') === '') {
            return null;
        }

        return hash_hmac('sha256', $checksum, $this->secret);
    }

    /**
     * No-op (always true) while signing is disabled, regardless of
     * $signature. While enabled, true only when $signature is non-null and
     * matches the HMAC of $checksum.
     */
    public function verify(string $checksum, ?string $signature): bool
    {
        $expected = $this->sign($checksum);

        if ($expected === null) {
            return true;
        }

        return $signature !== null && hash_equals($expected, $signature);
    }
}
