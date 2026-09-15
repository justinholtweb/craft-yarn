<?php

namespace justinholtweb\yarn\tests\unit;

use justinholtweb\yarn\models\Node;
use PHPUnit\Framework\TestCase;

/**
 * The health verdict, which decides whether a relation is reported as broken.
 */
class NodeTest extends TestCase
{
    private function node(array $overrides = []): Node
    {
        return new Node(
            id: $overrides['id'] ?? 1,
            type: 'craft\\elements\\Entry',
            kind: Node::KIND_ENTRY,
            label: 'Test',
            enabled: $overrides['enabled'] ?? true,
            enabledForSite: $overrides['enabledForSite'] ?? true,
            deleted: $overrides['deleted'] ?? false,
            inSite: $overrides['inSite'] ?? true,
            entryStatus: $overrides['entryStatus'] ?? 'live',
        );
    }

    public function testALiveElementIsOk(): void
    {
        self::assertSame('ok', $this->node()->health());
        self::assertTrue($this->node()->isLive());
    }

    public function testDeletionOutranksEverything(): void
    {
        $node = $this->node(['deleted' => true, 'enabled' => false, 'inSite' => false]);

        self::assertSame('deleted', $node->health());
    }

    public function testAnElementMissingFromTheSiteIsAbsent(): void
    {
        self::assertSame('absent', $this->node(['inSite' => false])->health());
    }

    public function testDisabledForOneSiteCounts(): void
    {
        // The trap this guards: an element enabled globally and switched off for this site
        // renders as nothing here, and reading only `enabled` calls it fine.
        self::assertSame('disabled', $this->node(['enabledForSite' => false])->health());
        self::assertSame('disabled', $this->node(['enabled' => false])->health());
    }

    public function testEntryStatusIsReportedVerbatim(): void
    {
        self::assertSame('pending', $this->node(['entryStatus' => 'pending'])->health());
        self::assertSame('expired', $this->node(['entryStatus' => 'expired'])->health());
    }

    public function testAKindWithoutStatusesIsNotPenalised(): void
    {
        self::assertSame('ok', $this->node(['entryStatus' => null])->health());
    }

    public function testKindsAreMappedFromElementClasses(): void
    {
        self::assertSame('entry', Node::KINDS_BY_TYPE['craft\\elements\\Entry']);
        self::assertSame('asset', Node::KINDS_BY_TYPE['craft\\elements\\Asset']);
        self::assertSame('global', Node::KINDS_BY_TYPE['craft\\elements\\GlobalSet']);
        self::assertArrayNotHasKey('craft\\elements\\Address', Node::KINDS_BY_TYPE);
    }
}
