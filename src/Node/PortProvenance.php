<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * How much authority the data on an OUTPUT port carries.
 *
 * The premise, stated once so the rest of the machinery reads as a
 * consequence of it: a language model, an inbound email, a scraped page and
 * a webhook body are all *someone else's text*. They are not a user to
 * authenticate and not a library to trust — they are input, and input is
 * never an authorization. An LLM node's `text` output is untrusted for the
 * same reason a `$_GET` parameter is: an attacker can choose what it says.
 *
 * Three states, and the middle one is the default because it is the safe
 * one:
 *
 * - {@see self::Untrusted} — a taint SOURCE. This port always emits
 *   untrusted data regardless of what fed the node. Model output, fetched
 *   HTML, an ingested mail body.
 * - {@see self::Derived} — the default. Untrusted in, untrusted out: the
 *   port is untrusted exactly when any of its node's inputs were. A node
 *   that reformats, filters or splits attacker text is still handing you
 *   attacker text.
 * - {@see self::Trusted} — a SANITIZER. The port emits trusted data even
 *   when its node consumed untrusted input. This is the one value that is
 *   a *claim*: declaring it says "I validated this against a closed set,
 *   and I am accountable for that". It is deliberately verbose to write.
 *
 * Note what `Trusted` does NOT mean: it is not "I escaped the string" or
 * "I ran a regex". Sanitizing untrusted text means reducing it to a value
 * from a set YOU control — an enum case, an ID that exists in your
 * database, one of five allow-listed hostnames. Anything that returns
 * attacker-chosen bytes in a different shape is {@see self::Derived}.
 *
 * @api
 */
enum PortProvenance: string
{
    /** Always untrusted: a taint source. */
    case Untrusted = 'untrusted';

    /** Untrusted iff any input to this node was untrusted. The default. */
    case Derived = 'derived';

    /** Always trusted: an explicit sanitization claim. */
    case Trusted = 'trusted';
}
