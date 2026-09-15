<?php

namespace justinholtweb\yarn\models;

use Craft;

/**
 * One relation, in one direction: `from` points at `to`.
 *
 * Direction is the whole point and is never normalised away. "What does this entry use" and "what
 * uses this entry" are different questions with different answers, and the second one is the one
 * that stops you deleting something in use.
 */
class Edge
{
    /** A relational field: Entries, Assets, Categories, Tags, Users. */
    public const KIND_FIELD = 'field';

    /** A reference tag — `{entry:42:url}` — sitting in a text or rich-text field. */
    public const KIND_REF = 'ref';

    /** Nested ownership: a Matrix entry, or an entry embedded in CKEditor. */
    public const KIND_NESTED = 'nested';

    /** Structure hierarchy: parent to child. */
    public const KIND_STRUCTURE = 'structure';

    /** A hard-coded URL in content that resolves to an element. */
    public const KIND_URL = 'url';

    public const KINDS = [
        self::KIND_FIELD,
        self::KIND_REF,
        self::KIND_NESTED,
        self::KIND_STRUCTURE,
        self::KIND_URL,
    ];

    public function __construct(
        public int $from,
        public int $to,
        public string $kind = self::KIND_FIELD,
        /** Field name where one applies — 'Hero Image', 'Related Articles'. */
        public string $label = '',
        public ?int $fieldId = null,
        /**
         * Where the relation physically lives when it isn't on `from` itself: the nested element
         * it was rolled up from. Reads as 'via the Body block #1288'.
         */
        public ?string $via = null,
        /** The pre-roll-up owner of the relation, for linking straight at the block. */
        public ?int $viaId = null,
    ) {
    }

    /**
     * Identity for de-duplication.
     *
     * Two relation rows from the same field to the same target in different Matrix blocks are two
     * real edges and both survive, because `via` differs. Two rows that agree on everything are
     * one edge — which happens once nested elements are rolled up and a site relates the same
     * asset from the same field in two propagated sites.
     */
    public function key(): string
    {
        return implode('|', [$this->from, $this->to, $this->kind, $this->fieldId ?? '', $this->viaId ?? '']);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_FIELD => Craft::t('yarn', 'Field'),
            self::KIND_REF => Craft::t('yarn', 'Reference tag'),
            self::KIND_NESTED => Craft::t('yarn', 'Nested'),
            self::KIND_STRUCTURE => Craft::t('yarn', 'Structure'),
            self::KIND_URL => Craft::t('yarn', 'Hard-coded URL'),
            default => $this->kind,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'kind' => $this->kind,
            'label' => $this->label,
            'via' => $this->via,
        ];
    }
}
