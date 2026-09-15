<?php

namespace justinholtweb\yarn\tests\unit;

use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\services\Export;
use PHPUnit\Framework\TestCase;

/**
 * Escaping, mostly. Every one of these formats has at least one character that ends a label
 * early, and a diagram that fails to parse is worse than no diagram.
 */
class ExportTest extends TestCase
{
    private function graph(): Graph
    {
        $graph = new Graph(1);
        $graph->addNode(new Node(
            id: 1,
            type: 'craft\\elements\\Entry',
            kind: Node::KIND_ENTRY,
            label: 'A "quoted" title, with a comma',
            group: 'News',
            groupKey: 'section:1',
        ));
        $graph->addNode(new Node(
            id: 2,
            type: 'craft\\elements\\Asset',
            kind: Node::KIND_ASSET,
            label: 'photo [final] (v2).jpg',
            group: 'Images',
            groupKey: 'volume:1',
        ));
        $graph->addEdge(new Edge(from: 1, to: 2, label: 'Hero image', fieldId: 9));
        $graph->tally();

        return $graph;
    }

    public function testCsvHasOneRowPerEdgePlusAHeader(): void
    {
        $csv = (new Export())->csv($this->graph());
        $rows = array_values(array_filter(explode("\n", trim($csv))));

        self::assertCount(2, $rows);
        self::assertStringContainsString('From ID', $rows[0]);
    }

    public function testCsvQuotesLabelsContainingCommas(): void
    {
        $csv = (new Export())->csv($this->graph());
        $parsed = str_getcsv(explode("\n", trim($csv))[1]);

        self::assertSame('1', $parsed[0]);
        self::assertSame('A "quoted" title, with a comma', $parsed[1]);
        self::assertSame('Hero image', $parsed[6]);
    }

    public function testDotEscapesQuotes(): void
    {
        $dot = (new Export())->dot($this->graph());

        self::assertStringContainsString('digraph yarn {', $dot);
        self::assertStringContainsString('\\"quoted\\"', $dot);
        self::assertStringContainsString('n1 -> n2', $dot);
    }

    public function testMermaidStripsCharactersThatWouldEndALabel(): void
    {
        $mermaid = (new Export())->mermaid($this->graph());

        self::assertStringContainsString('graph LR', $mermaid);
        self::assertStringNotContainsString('[final]', $mermaid);
        self::assertStringContainsString('photo final v2.jpg', $mermaid);
        // The quote in node 1's label must not survive, or the node declaration ends early.
        self::assertSame(2, substr_count(explode("\n", $mermaid)[1], '"'), 'only the two wrapping quotes; the one in the title became an apostrophe');
    }

    public function testMermaidTruncatesAndSaysSo(): void
    {
        $graph = new Graph(1);

        for ($id = 1; $id <= Export::MERMAID_MAX_NODES + 5; $id++) {
            $graph->addNode(new Node(id: $id, type: 'E', kind: Node::KIND_ENTRY, label: "n$id"));
        }

        $graph->tally();
        $mermaid = (new Export())->mermaid($graph);

        self::assertStringContainsString('%% truncated', $mermaid);
        self::assertStringNotContainsString('n' . (Export::MERMAID_MAX_NODES + 5) . '[', $mermaid);
    }

    public function testExtensionsAndMimeTypesMatchTheFormat(): void
    {
        $export = new Export();

        self::assertSame('csv', $export->extension(Export::FORMAT_CSV));
        self::assertSame('mmd', $export->extension(Export::FORMAT_MERMAID));
        self::assertSame('dot', $export->extension(Export::FORMAT_DOT));
        self::assertSame('json', $export->extension(Export::FORMAT_JSON));
        self::assertSame('text/csv', $export->mimeType(Export::FORMAT_CSV));
        self::assertSame('application/json', $export->mimeType(Export::FORMAT_JSON));
    }
}
