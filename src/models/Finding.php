<?php

namespace justinholtweb\yarn\models;

/**
 * One thing worth knowing about the graph.
 *
 * Findings carry a fix, not just a complaint. "Orphaned entry" on its own is a shrug; "nothing
 * links to this and it has no URL, so no visitor can ever reach it" is something to act on.
 */
class Finding
{
    /** Broken: the relation points somewhere that no longer renders. */
    public const SEVERITY_ERROR = 'error';

    /** Suspicious: legal, but usually not what was meant. */
    public const SEVERITY_WARNING = 'warning';

    /** Worth a look. Never a problem on its own. */
    public const SEVERITY_NOTICE = 'notice';

    public const SEVERITY_ORDER = [
        self::SEVERITY_ERROR => 0,
        self::SEVERITY_WARNING => 1,
        self::SEVERITY_NOTICE => 2,
    ];

    public function __construct(
        /** The check that produced it, e.g. `brokenTargets`. */
        public string $check,
        public string $severity,
        public string $title,
        /** One sentence saying what is wrong and what to do about it. */
        public string $detail = '',
        /** The element the reader should open. */
        public ?Node $subject = null,
        /** The other end, where the finding is about a pair. */
        public ?Node $object = null,
        /** Free-form extras the template may show — field name, hop count, the offending URL. */
        public array $context = [],
    ) {
    }

    public function severityWeight(): int
    {
        return self::SEVERITY_ORDER[$this->severity] ?? 99;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'subject' => $this->subject?->toArray(),
            'object' => $this->object?->toArray(),
            'context' => $this->context,
        ];
    }
}
