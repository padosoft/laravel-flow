<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed output port on a public handler property.
 *
 * `$provenance` declares how much authority this port's data carries; it
 * defaults to {@see PortProvenance::Derived} (untrusted in, untrusted out),
 * which is the safe answer for a node that transforms whatever it is given.
 * Set {@see PortProvenance::Untrusted} on a port that emits someone else's
 * text (a model completion, a fetched page, an ingested mail body). Set
 * {@see PortProvenance::Trusted} only to make an explicit sanitization
 * claim — see the enum for what that word has to mean.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Output
{
    public function __construct(
        public readonly PortType $type,
        public readonly ?string $label = null,
        public readonly ?string $key = null,
        public readonly PortProvenance $provenance = PortProvenance::Derived,
    ) {}
}
