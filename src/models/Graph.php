<?php

namespace justinholtweb\yarn\models;

/**
 * The whole relation graph for one site: every node, every edge, and the adjacency to walk them.
 *
 * Adjacency is stored as lists of *edge indexes*, not of node ids. Two elements can be joined by
 * several distinct edges — an asset used by the same entry in both a hero field and three Matrix
 * blocks — and a map of `from => to[]` loses every one of them but the first. The cost is one
 * extra lookup per hop; the benefit is that "used 4 times" is answerable.
 */
class Graph
{
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IN = 'in';
    public const DIRECTION_BOTH = 'both';

    /** @var array<int, Node> Keyed by element id. */
    public array $nodes = [];

    /** @var Edge[] Positional; the index is the edge's identity everywhere else in here. */
    public array $edges = [];

    /** @var array<int, int[]> Element id => edge indexes leaving it. */
    private array $out = [];

    /** @var array<int, int[]> Element id => edge indexes arriving at it. */
    private array $in = [];

    public function __construct(
        public int $siteId,
        /** When the graph was assembled. Shown in the control panel, since it may be cached. */
        public ?int $builtAt = null,
        /** Seconds the build took. */
        public float $buildTime = 0.0,
        /** Edge counts by kind, before any filtering. */
        public array $kindCounts = [],
        /**
         * Kinds that were switched off when this graph was built. Without it the control panel
         * would report "no reference tags found" for a site that was never scanned for them.
         */
        public array $skippedKinds = [],
        /**
         * References in content that pointed at nothing — a `{entry:412:url}` whose entry is
         * gone, or a link to a URL no element answers.
         *
         * @var array<int, array{elementId: int, reference: string, type: string}>
         */
        public array $unresolved = [],
    ) {
    }

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        $index = count($this->edges);
        $this->edges[$index] = $edge;
        $this->out[$edge->from][] = $index;
        $this->in[$edge->to][] = $index;
    }

    /** Recomputes the per-node in/out tallies. Called once, after the last edge is added. */
    public function tally(): void
    {
        foreach ($this->nodes as $node) {
            $node->outCount = count($this->out[$node->id] ?? []);
            $node->inCount = count($this->in[$node->id] ?? []);
        }
    }

    public function node(int $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    public function has(int $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return Edge[] */
    public function outgoing(int $id): array
    {
        return array_map(fn(int $i) => $this->edges[$i], $this->out[$id] ?? []);
    }

    /** @return Edge[] */
    public function incoming(int $id): array
    {
        return array_map(fn(int $i) => $this->edges[$i], $this->in[$id] ?? []);
    }

    /** @return int[] Distinct neighbour ids in the given direction. */
    public function neighbours(int $id, string $direction = self::DIRECTION_BOTH): array
    {
        $ids = [];

        if ($direction !== self::DIRECTION_IN) {
            foreach ($this->out[$id] ?? [] as $i) {
                $ids[$this->edges[$i]->to] = true;
            }
        }

        if ($direction !== self::DIRECTION_OUT) {
            foreach ($this->in[$id] ?? [] as $i) {
                $ids[$this->edges[$i]->from] = true;
            }
        }

        unset($ids[$id]);

        return array_keys($ids);
    }

    /**
     * Every node within `$depth` hops of `$id`, with the distance it sits at.
     *
     * Breadth-first, so the distance recorded is the shortest one — the ego view of a hub
     * otherwise reports whichever long way round happened to be walked first.
     *
     * @return array<int, int> Element id => hops from the centre (0 for the centre itself).
     */
    public function ego(int $id, int $depth = 1, string $direction = self::DIRECTION_BOTH): array
    {
        if (!$this->has($id)) {
            return [];
        }

        $seen = [$id => 0];
        $frontier = [$id];

        for ($hop = 1; $hop <= $depth && $frontier !== []; $hop++) {
            $next = [];

            foreach ($frontier as $current) {
                foreach ($this->neighbours($current, $direction) as $neighbour) {
                    if (!isset($seen[$neighbour])) {
                        $seen[$neighbour] = $hop;
                        $next[] = $neighbour;
                    }
                }
            }

            $frontier = $next;
        }

        return $seen;
    }

    /**
     * Everything reachable from `$id` by following edges backwards — i.e. everything that would
     * be left pointing at nothing if `$id` were deleted.
     *
     * @return array<int, int> Element id => hops away.
     */
    public function dependents(int $id, int $depth = 3): array
    {
        $found = $this->ego($id, $depth, self::DIRECTION_IN);
        unset($found[$id]);

        return $found;
    }

    /**
     * The shortest chain of edges joining two elements, following direction.
     *
     * Answers the question people actually ask of a big site — "how on earth is this PDF reaching
     * the homepage?" — which no list of direct relations can.
     *
     * @return Edge[] Empty when there is no path.
     */
    public function path(int $from, int $to, string $direction = self::DIRECTION_OUT): array
    {
        if (!$this->has($from) || !$this->has($to)) {
            return [];
        }

        if ($from === $to) {
            return [];
        }

        /** @var array<int, int> $cameFrom node id => edge index that reached it */
        $cameFrom = [];
        $seen = [$from => true];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->stepsFrom($current, $direction) as $index) {
                $edge = $this->edges[$index];
                $next = $edge->from === $current ? $edge->to : $edge->from;

                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $cameFrom[$next] = $index;

                if ($next === $to) {
                    return $this->rebuild($cameFrom, $from, $to);
                }

                $queue[] = $next;
            }
        }

        return [];
    }

    /** @return int[] Edge indexes usable as a step out of `$id`. */
    private function stepsFrom(int $id, string $direction): array
    {
        return match ($direction) {
            self::DIRECTION_OUT => $this->out[$id] ?? [],
            self::DIRECTION_IN => $this->in[$id] ?? [],
            default => array_merge($this->out[$id] ?? [], $this->in[$id] ?? []),
        };
    }

    /**
     * @param array<int, int> $cameFrom
     * @return Edge[]
     */
    private function rebuild(array $cameFrom, int $from, int $to): array
    {
        $chain = [];
        $current = $to;

        while ($current !== $from && isset($cameFrom[$current])) {
            $index = $cameFrom[$current];
            array_unshift($chain, $this->edges[$index]);
            $edge = $this->edges[$index];
            $current = $edge->to === $current ? $edge->from : $edge->to;
        }

        return $chain;
    }

    /**
     * Cycles: A points at B points back at A, at any length.
     *
     * Iterative, with its own stack. A recursive depth-first search is the textbook way to write
     * this and it segfaults on a site whose structure section is 12,000 entries deep — PHP has no
     * tail calls and the default stack gives out long before the graph does.
     *
     * @param int $limit Stop after this many cycles; the twentieth one tells you nothing new.
     * @return int[][] Each cycle as a list of element ids, starting and ending on the same id.
     */
    public function cycles(int $limit = 20): array
    {
        $cycles = [];
        $closed = [];
        $found = [];

        foreach (array_keys($this->nodes) as $start) {
            if (isset($closed[$start])) {
                continue;
            }

            // One shared path, pushed and popped with the frame stack.
            //
            // The obvious version keeps a copy of the path in every frame, which is O(n²) memory:
            // a 20,000-deep structure section took five gigabytes and most of a minute before
            // this was rewritten. `$onPath` is the same set as the path, indexed, so "is this
            // node an ancestor of itself" is a hash lookup rather than a scan.
            $stack = [[$start, $this->neighbours($start, self::DIRECTION_OUT)]];
            $path = [$start];
            $onPath = [$start => 0];

            while ($stack !== []) {
                $top = count($stack) - 1;
                [$node, $pending] = $stack[$top];

                if ($pending === []) {
                    $closed[$node] = true;
                    array_pop($stack);
                    array_pop($path);
                    unset($onPath[$node]);
                    continue;
                }

                $next = array_shift($pending);
                $stack[$top][1] = $pending;

                if (isset($onPath[$next])) {
                    $cycle = array_slice($path, $onPath[$next]);
                    $cycle[] = $next;
                    $signature = $this->cycleSignature($cycle);

                    if (!isset($found[$signature])) {
                        $found[$signature] = true;
                        $cycles[] = $cycle;

                        if (count($cycles) >= $limit) {
                            return $cycles;
                        }
                    }

                    continue;
                }

                if (isset($closed[$next])) {
                    continue;
                }

                $stack[] = [$next, $this->neighbours($next, self::DIRECTION_OUT)];
                $path[] = $next;
                $onPath[$next] = count($path) - 1;
            }
        }

        return $cycles;
    }

    /**
     * A rotation-independent name for a cycle, so A→B→A and B→A→B are reported once.
     *
     * @param int[] $cycle
     */
    private function cycleSignature(array $cycle): string
    {
        $ids = array_slice($cycle, 0, -1);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Nodes nothing points at.
     *
     * @param string[] $kinds Restrict to these node kinds; empty for all.
     * @return Node[]
     */
    public function orphans(array $kinds = []): array
    {
        $orphans = [];

        foreach ($this->nodes as $node) {
            if ($node->inCount > 0) {
                continue;
            }

            if ($kinds !== [] && !in_array($node->kind, $kinds, true)) {
                continue;
            }

            $orphans[] = $node;
        }

        return $orphans;
    }

    /**
     * The most-pointed-at elements. Not a problem — a map of where the site's weight sits.
     *
     * @return Node[]
     */
    public function hubs(int $limit = 10): array
    {
        $nodes = array_values($this->nodes);

        usort($nodes, fn(Node $a, Node $b) => [$b->inCount, $b->outCount] <=> [$a->inCount, $a->outCount]);

        return array_slice(array_filter($nodes, fn(Node $n) => $n->inCount > 0), 0, $limit);
    }

    /**
     * Connected components, ignoring direction. A site that comes back as forty islands is
     * usually forty sections nobody cross-links — which is worth knowing.
     *
     * @return int[][] Each component as a list of element ids, largest first.
     */
    public function components(): array
    {
        $seen = [];
        $components = [];

        foreach (array_keys($this->nodes) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            $component = [];
            $queue = [$start];
            $seen[$start] = true;

            while ($queue !== []) {
                $current = array_pop($queue);
                $component[] = $current;

                foreach ($this->neighbours($current, self::DIRECTION_BOTH) as $neighbour) {
                    if (!isset($seen[$neighbour])) {
                        $seen[$neighbour] = true;
                        $queue[] = $neighbour;
                    }
                }
            }

            $components[] = $component;
        }

        usort($components, fn(array $a, array $b) => count($b) <=> count($a));

        return $components;
    }

    /** @return array<string, int> Node counts by kind. */
    public function kindTotals(): array
    {
        $totals = [];

        foreach ($this->nodes as $node) {
            $totals[$node->kind] = ($totals[$node->kind] ?? 0) + 1;
        }

        arsort($totals);

        return $totals;
    }

    /** @return array<string, array{key: string, label: string, kind: string, count: int}> */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->nodes as $node) {
            $key = $node->groupKey !== '' ? $node->groupKey : $node->kind;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $node->group !== '' ? $node->group : $node->kind,
                    'kind' => $node->kind,
                    'count' => 0,
                ];
            }

            $groups[$key]['count']++;
        }

        uasort($groups, fn(array $a, array $b) => [$a['kind'], -$a['count']] <=> [$b['kind'], -$b['count']]);

        return $groups;
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $connected = 0;

        foreach ($this->nodes as $node) {
            if ($node->inCount > 0 || $node->outCount > 0) {
                $connected++;
            }
        }

        $unreferenced = 0;

        foreach ($this->nodes as $node) {
            if ($node->inCount === 0) {
                $unreferenced++;
            }
        }

        return [
            'nodes' => count($this->nodes),
            'edges' => count($this->edges),
            'connected' => $connected,
            // Two different ideas, and conflating them is how a relations report ends up lying.
            // `isolated` is "no edges at all"; `unreferenced` is "nothing points at it", which a
            // busy hub page with fifty outgoing relations can still be.
            'isolated' => count($this->nodes) - $connected,
            'unreferenced' => $unreferenced,
            'kinds' => $this->kindTotals(),
            'edgeKinds' => $this->kindCounts,
            'builtAt' => $this->builtAt,
            'buildTime' => $this->buildTime,
        ];
    }
}
