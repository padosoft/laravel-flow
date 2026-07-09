<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;

/**
 * Portable JSON export/import for stored flow definitions, built on top of
 * {@see GraphSerializer} and {@see DefinitionRepository}.
 *
 * {@see self::export()} emits the serializer envelope plus a top-level
 * `definition` block carrying the source `name`/`version`/`status`/
 * `checksum` for provenance; that block is not part of the graph itself
 * and is stripped back out on import.
 *
 * {@see self::importDraft()} always creates a NEW draft version under the
 * caller-supplied name — there is no in-place update. Unlike
 * {@see DefinitionRepository::createDraft()} itself, which allows a
 * semantically incomplete graph (Studio saves work-in-progress), an
 * imported graph is assumed to already be a complete definition from
 * another source, so it is validated with {@see GraphValidator} before
 * being persisted.
 *
 * @api
 */
final class GraphTransfer
{
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly GraphValidator $validator,
        private readonly GraphSerializer $serializer = new GraphSerializer,
    ) {}

    /**
     * @throws JsonException
     */
    public function export(StoredDefinition $definition): string
    {
        $envelope = $definition->graph;
        $envelope['definition'] = [
            'checksum' => $definition->checksum,
            'name' => $definition->name,
            'status' => $definition->status,
            'version' => $definition->version,
        ];

        return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidGraphException when the JSON is malformed or the
     *                               graph fails structural or semantic validation
     * @throws DefinitionSignatureException propagated from {@see DefinitionRepository::createDraft()}
     */
    public function importDraft(string $json, string $name): StoredDefinition
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (is_array($decoded) && array_key_exists('definition', $decoded)) {
            // Strip the provenance block emitted by export(): it describes
            // the SOURCE definition, not the graph being imported, and is
            // not part of the GraphSerializer envelope. A malformed $json
            // here decodes to null and falls straight through to
            // fromJson() below for its own precise error reporting.
            unset($decoded['definition']);
            $json = json_encode($decoded, JSON_THROW_ON_ERROR);
        }

        $graph = $this->serializer->fromJson($json);
        $this->validator->validate($graph);

        return $this->definitions->createDraft($name, $graph);
    }
}
