<?php

namespace justinholtweb\yarn\tests\unit;

use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph;
use justinholtweb\yarn\models\Node;
use PHPUnit\Framework\TestCase;

/**
 * The traversal, which is where a relations tool is either right or quietly useless.
 */
class GraphTest extends TestCase
{
    private function node(int $id, string $kind = Node::KIND_ENTRY, string $group = 'News'): Node
    {
        return new Node(
            id: $id,
            type: 'craft\\elements\\Entry',
            kind: $kind,
            label: "Node $id",
            group: $group,
            groupKey: 'section:1',
        );
    }

    /**
     * @param array<int, array{0: int, 1: int}> $edges
     * @param int[] $ids
     */
    private function graph(array $ids, array $edges, string $kind = Edge::KIND_FIELD): Graph
    {
        $graph = new Graph(1);

        foreach ($ids as $id) {
            $graph->addNode($this->node($id));
        }

        foreach ($edges as $edge) {
            $graph->addEdge(new Edge(from: $edge[0], to: $edge[1], kind: $kind, label: 'Related'));
        }

        $graph->tally();

        return $graph;
    }

    public function testTallyCountsBothDirections(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2], [3, 2]]);

        self::assertSame(1, $graph->node(1)->outCount);
        self::assertSame(0, $graph->node(1)->inCount);
        self::assertSame(2, $graph->node(2)->inCount);
        self::assertSame(0, $graph->node(2)->outCount);
    }

    public function testParallelEdgesAreBothKept(): void
    {
        // The same asset in a hero field and in a Matrix block is used twice, and a map that
        // stores adjacency as node ids can only ever say "once".
        $graph = new Graph(1);
        $graph->addNode($this->node(1));
        $graph->addNode($this->node(2));
        $graph->addEdge(new Edge(from: 1, to: 2, label: 'Hero', fieldId: 10));
        $graph->addEdge(new Edge(from: 1, to: 2, label: 'Gallery', fieldId: 11));
        $graph->tally();

        self::assertCount(2, $graph->outgoing(1));
        self::assertSame(2, $graph->node(2)->inCount);
        self::assertSame([2], $graph->neighbours(1));
    }

    public function testEgoReportsShortestDistance(): void
    {
        //   1 → 2 → 3 → 4
        //   1 ────────→ 4
        $graph = $this->graph([1, 2, 3, 4], [[1, 2], [2, 3], [3, 4], [1, 4]]);

        $ego = $graph->ego(1, 3, Graph::DIRECTION_OUT);

        self::assertSame(0, $ego[1]);
        self::assertSame(1, $ego[2]);
        self::assertSame(2, $ego[3]);
        self::assertSame(1, $ego[4], 'the one-hop route to 4 should win over the three-hop one');
    }

    public function testEgoRespectsDepth(): void
    {
        $graph = $this->graph([1, 2, 3, 4], [[1, 2], [2, 3], [3, 4]]);

        self::assertSame([1, 2], array_keys($graph->ego(1, 1, Graph::DIRECTION_OUT)));
        self::assertCount(3, $graph->ego(1, 2, Graph::DIRECTION_OUT));
    }

    public function testPathFollowsDirection(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2], [2, 3]]);

        self::assertCount(2, $graph->path(1, 3));
        self::assertSame([], $graph->path(3, 1), 'relations point one way');
        self::assertCount(2, $graph->path(3, 1, Graph::DIRECTION_BOTH));
    }

    public function testPathIsShortest(): void
    {
        $graph = $this->graph([1, 2, 3, 4, 5], [[1, 2], [2, 3], [3, 4], [1, 5], [5, 4]]);

        $path = $graph->path(1, 4);

        self::assertCount(2, $path);
        self::assertSame(1, $path[0]->from);
        self::assertSame(5, $path[0]->to);
        self::assertSame(4, $path[1]->to);
    }

    public function testPathToUnknownElementIsEmpty(): void
    {
        $graph = $this->graph([1, 2], [[1, 2]]);

        self::assertSame([], $graph->path(1, 99));
        self::assertSame([], $graph->path(1, 1));
    }

    public function testCyclesAreFound(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2], [2, 3], [3, 1]]);

        $cycles = $graph->cycles();

        self::assertCount(1, $cycles);
        self::assertSame($cycles[0][0], end($cycles[0]), 'a cycle starts and ends on the same id');
        self::assertCount(4, $cycles[0]);
    }

    public function testTheSameCycleIsReportedOnce(): void
    {
        // Two entry points into one loop. Rotations of a cycle are the same cycle.
        $graph = $this->graph([1, 2, 3, 4], [[4, 1], [1, 2], [2, 3], [3, 1]]);

        self::assertCount(1, $graph->cycles());
    }

    public function testCycleLimitIsHonoured(): void
    {
        $edges = [];
        $ids = [];

        for ($i = 1; $i <= 20; $i++) {
            $a = $i * 2;
            $b = $a + 1;
            $ids[] = $a;
            $ids[] = $b;
            $edges[] = [$a, $b];
            $edges[] = [$b, $a];
        }

        $graph = $this->graph($ids, $edges);

        self::assertCount(5, $graph->cycles(5));
        self::assertCount(20, $graph->cycles(50));
    }

    public function testADeepChainDoesNotExhaustTheStack(): void
    {
        // The reason cycle detection is iterative. A recursive walk gives out somewhere around
        // ten thousand frames; a structure section that deep is unusual but entirely legal.
        $ids = range(1, 20000);
        $edges = [];

        for ($i = 1; $i < 20000; $i++) {
            $edges[] = [$i, $i + 1];
        }

        $graph = $this->graph($ids, $edges);

        self::assertSame([], $graph->cycles());
    }

    public function testOrphansAreThingsNothingPointsAt(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2]]);

        $orphans = $graph->orphans();
        $ids = array_map(fn(Node $n) => $n->id, $orphans);

        sort($ids);

        self::assertSame([1, 3], $ids, 'node 1 points at something but nothing points at it');
    }

    public function testOrphansCanBeRestrictedByKind(): void
    {
        $graph = new Graph(1);
        $graph->addNode($this->node(1, Node::KIND_ENTRY));
        $graph->addNode($this->node(2, Node::KIND_ASSET));
        $graph->tally();

        self::assertCount(1, $graph->orphans([Node::KIND_ASSET]));
        self::assertSame(2, $graph->orphans([Node::KIND_ASSET])[0]->id);
    }

    public function testStatsSeparatesIsolatedFromUnreferenced(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2]]);
        $stats = $graph->stats();

        self::assertSame(3, $stats['nodes']);
        self::assertSame(1, $stats['edges']);
        self::assertSame(2, $stats['connected']);
        self::assertSame(1, $stats['isolated'], 'only node 3 has no relations at all');
        self::assertSame(2, $stats['unreferenced'], 'nodes 1 and 3 have nothing pointing at them');
    }

    public function testComponentsAreOrderedLargestFirst(): void
    {
        $graph = $this->graph([1, 2, 3, 4, 5], [[1, 2], [2, 3], [4, 5]]);

        $components = $graph->components();

        self::assertCount(2, $components);
        self::assertCount(3, $components[0]);
        self::assertCount(2, $components[1]);
    }

    public function testHubsAreSortedAndExcludeTheUnreferenced(): void
    {
        $graph = $this->graph([1, 2, 3, 4], [[1, 4], [2, 4], [3, 4], [1, 2]]);

        $hubs = $graph->hubs(10);

        self::assertSame(4, $hubs[0]->id);
        self::assertSame(2, $hubs[1]->id);
        self::assertCount(2, $hubs, 'nodes nothing points at are not hubs');
    }

    public function testDependentsExcludeTheSubjectItself(): void
    {
        $graph = $this->graph([1, 2, 3], [[1, 2], [2, 3]]);

        $dependents = $graph->dependents(3, 3);

        self::assertArrayNotHasKey(3, $dependents);
        self::assertSame(1, $dependents[2]);
        self::assertSame(2, $dependents[1]);
    }

    public function testGroupsCountNodes(): void
    {
        $graph = new Graph(1);
        $graph->addNode(new Node(id: 1, type: 'E', kind: Node::KIND_ENTRY, label: 'a', group: 'News', groupKey: 'section:1'));
        $graph->addNode(new Node(id: 2, type: 'E', kind: Node::KIND_ENTRY, label: 'b', group: 'News', groupKey: 'section:1'));
        $graph->addNode(new Node(id: 3, type: 'A', kind: Node::KIND_ASSET, label: 'c', group: 'Images', groupKey: 'volume:1'));
        $graph->tally();

        $groups = $graph->groups();

        self::assertSame(2, $groups['section:1']['count']);
        self::assertSame(1, $groups['volume:1']['count']);
    }
}
