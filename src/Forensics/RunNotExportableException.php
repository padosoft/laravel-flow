<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

use RuntimeException;

/**
 * There is no persisted record for that run, so there is nothing to attest.
 *
 * Deliberately an exception rather than an empty bundle: an empty forensic
 * document is the most dangerous possible output — it reads as "nothing
 * happened" when it means "we did not keep the record".
 *
 * @api
 */
final class RunNotExportableException extends RuntimeException {}
