<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;

/**
 * The graph, in formats other tools can read.
 *
 * Yarn draws a perfectly good picture, and it is the wrong picture about once a month — when the
 * question is "give the client a spreadsheet of every page this PDF appears on", or when the graph
 * wants laying out properly by Graphviz, or when it belongs in a README as a Mermaid block.
 */
class Export extends Component
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_DOT = 'dot';
    public const FORMAT_MERMAID = 'mermaid';

    public const FORMATS = [self::FORMAT_JSON, self::FORMAT_CSV, self::FORMAT_DOT, self::FORMAT_MERMAID];

    /** Mermaid renders about this many nodes before the browser gives up on it. */
    public const MERMAID_MAX_NODES = 300;

    public function extension(string $format): string
    {
        return match ($format) {
            self::FORMAT_MERMAID => 'mmd',
            self::FORMAT_DOT => 'dot',
            self::FORMAT_CSV => 'csv',
            default => 'json',
        };
    }

    public function mimeType(string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV => 'text/csv',
            self::FORMAT_JSON => 'application/json',
            default => 'text/plain',
        };
    }

    public function render(GraphModel $graph, string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV => $this->csv($graph),
            self::FORMAT_DOT => $this->dot($graph),
            self::FORMAT_MERMAID => $this->mermaid($graph),
            default => $this->json($graph),
        };
    }

    public function json(GraphModel $graph): string
    {
        return (string)json_encode([
            'site' => Craft::$app->getSites()->getSiteById($graph->siteId)?->handle,
            'builtAt' => $graph->builtAt,
            'stats' => $graph->stats(),
            'nodes' => array_map(fn(Node $n) => $n->toArray(), array_values($graph->nodes)),
            'edges' => array_map(fn(Edge $e) => $e->toArray(), $graph->edges),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * One row per edge, both ends spelled out.
     *
     * Denormalised on purpose: the point of the CSV is that somebody can sort it in a spreadsheet
     * without joining anything.
     */
    public function csv(GraphModel $graph): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'From ID', 'From', 'From type', 'From source', 'From status',
            'Relation', 'Field', 'Via',
            'To ID', 'To', 'To type', 'To source', 'To status',
        ]);

        foreach ($graph->edges as $edge) {
            $from = $graph->node($edge->from);
            $to = $graph->node($edge->to);

            if ($from === null || $to === null) {
                continue;
            }

            fputcsv($handle, [
                $from->id, $from->label, $from->kind, $from->group, $from->health(),
                $edge->kind, $edge->label, $edge->via ?? '',
                $to->id, $to->label, $to->kind, $to->group, $to->health(),
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function dot(GraphModel $graph): string
    {
        $lines = ['digraph yarn {', '  rankdir=LR;', '  node [shape=box style=rounded fontname="Helvetica"];'];

        foreach ($graph->nodes as $node) {
            $lines[] = sprintf(
                '  n%d [label=%s tooltip=%s];',
                $node->id,
                $this->quote($node->label),
                $this->quote($node->group !== '' ? $node->group : $node->kind),
            );
        }

        foreach ($graph->edges as $edge) {
            $lines[] = sprintf(
                '  n%d -> n%d [label=%s];',
                $edge->from,
                $edge->to,
                $this->quote($edge->label),
            );
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    public function mermaid(GraphModel $graph): string
    {
        $lines = ['graph LR'];
        $nodes = array_slice($graph->nodes, 0, self::MERMAID_MAX_NODES, true);

        foreach ($nodes as $node) {
            $lines[] = sprintf('  n%d["%s"]', $node->id, $this->mermaidLabel($node->label));
        }

        foreach ($graph->edges as $edge) {
            if (!isset($nodes[$edge->from], $nodes[$edge->to])) {
                continue;
            }

            $label = $this->mermaidLabel($edge->label);
            $lines[] = $label !== ''
                ? sprintf('  n%d -->|%s| n%d', $edge->from, $label, $edge->to)
                : sprintf('  n%d --> n%d', $edge->from, $edge->to);
        }

        if (count($graph->nodes) > self::MERMAID_MAX_NODES) {
            $lines[] = sprintf(
                '  %%%% truncated: %d of %d nodes',
                self::MERMAID_MAX_NODES,
                count($graph->nodes),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $value) . '"';
    }

    /**
     * Mermaid has no escape syntax inside a label — a quote or a bracket ends it and the rest of
     * the diagram fails to parse. Substitution is the only option.
     */
    private function mermaidLabel(string $value): string
    {
        return trim(str_replace(['"', '[', ']', '(', ')', '{', '}', '|', '<', '>', "\n"], ['\'', '', '', '', '', '', '', '/', '', '', ' '], $value));
    }
}
