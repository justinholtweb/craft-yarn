<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use justinholtweb\yarn\events\DefineFindingsEvent;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\Plugin;

/**
 * What the graph has to say about the site.
 *
 * Every check here answers a question someone has actually had to answer by hand: is it safe to
 * delete this, why is this image still here, what did that entry I deleted last week break.
 */
class Findings extends Component
{
    /** @see DefineFindingsEvent */
    public const EVENT_DEFINE_FINDINGS = 'defineFindings';

    public const CHECK_BROKEN = 'brokenTargets';
    public const CHECK_ABSENT = 'absentTargets';
    public const CHECK_DISABLED = 'disabledTargets';
    public const CHECK_UNRESOLVED = 'unresolvedReferences';
    public const CHECK_ORPHANS = 'orphans';
    public const CHECK_UNUSED_ASSETS = 'unusedAssets';
    public const CHECK_CYCLES = 'cycles';
    public const CHECK_SELF = 'selfReferences';

    public const CHECKS = [
        self::CHECK_BROKEN,
        self::CHECK_ABSENT,
        self::CHECK_DISABLED,
        self::CHECK_UNRESOLVED,
        self::CHECK_ORPHANS,
        self::CHECK_UNUSED_ASSETS,
        self::CHECK_CYCLES,
        self::CHECK_SELF,
    ];

    /** @return array<string, string> Check id => name, for filters and console output. */
    public function names(): array
    {
        return [
            self::CHECK_BROKEN => Craft::t('yarn', 'Relations into the trash'),
            self::CHECK_ABSENT => Craft::t('yarn', 'Relations to elements missing from this site'),
            self::CHECK_DISABLED => Craft::t('yarn', 'Live elements pointing at things that are not'),
            self::CHECK_UNRESOLVED => Craft::t('yarn', 'References that resolve to nothing'),
            self::CHECK_ORPHANS => Craft::t('yarn', 'Nothing points at these'),
            self::CHECK_UNUSED_ASSETS => Craft::t('yarn', 'Unused assets'),
            self::CHECK_CYCLES => Craft::t('yarn', 'Circular relations'),
            self::CHECK_SELF => Craft::t('yarn', 'Elements related to themselves'),
        ];
    }

    /**
     * @param string[] $only Restrict to these checks; empty for all of them.
     * @return Finding[] Most severe first.
     */
    public function run(GraphModel $graph, array $only = []): array
    {
        $wanted = fn(string $check) => $only === [] || in_array($check, $only, true);
        $findings = [];

        if ($wanted(self::CHECK_BROKEN) || $wanted(self::CHECK_ABSENT) || $wanted(self::CHECK_DISABLED)) {
            foreach ($this->targetChecks($graph) as $finding) {
                if ($wanted($finding->check)) {
                    $findings[] = $finding;
                }
            }
        }

        if ($wanted(self::CHECK_UNRESOLVED)) {
            array_push($findings, ...$this->unresolved($graph));
        }

        if ($wanted(self::CHECK_ORPHANS)) {
            array_push($findings, ...$this->orphans($graph));
        }

        if ($wanted(self::CHECK_UNUSED_ASSETS)) {
            array_push($findings, ...$this->unusedAssets($graph));
        }

        if ($wanted(self::CHECK_CYCLES)) {
            array_push($findings, ...$this->cycles($graph));
        }

        if ($wanted(self::CHECK_SELF)) {
            array_push($findings, ...$this->selfReferences($graph));
        }

        $event = new DefineFindingsEvent(['findings' => $findings, 'graph' => $graph]);
        $this->trigger(self::EVENT_DEFINE_FINDINGS, $event);
        $findings = $event->findings;

        usort($findings, fn(Finding $a, Finding $b) => [$a->severityWeight(), $a->check, $a->title]
            <=> [$b->severityWeight(), $b->check, $b->title]);

        return $findings;
    }

    /** @return array<string, int> Check id => how many findings it produced. */
    public function tally(array $findings): array
    {
        $counts = array_fill_keys(self::CHECKS, 0);

        foreach ($findings as $finding) {
            $counts[$finding->check] = ($counts[$finding->check] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, int> Severity => count. */
    public function severities(array $findings): array
    {
        $counts = [
            Finding::SEVERITY_ERROR => 0,
            Finding::SEVERITY_WARNING => 0,
            Finding::SEVERITY_NOTICE => 0,
        ];

        foreach ($findings as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        return $counts;
    }

    // ----------------------------------------------------------------------------- the checks

    /**
     * Walks the edges once and asks three questions of every target, because walking a million
     * edges three times to ask one question each is the same answer at three times the cost.
     *
     * @return Finding[]
     */
    private function targetChecks(GraphModel $graph): array
    {
        $findings = [];
        $seen = [];

        foreach ($graph->edges as $edge) {
            $target = $graph->node($edge->to);
            $source = $graph->node($edge->from);

            if ($target === null || $source === null || $edge->from === $edge->to) {
                continue;
            }

            $key = $edge->from . ':' . $edge->to;

            if (isset($seen[$key])) {
                continue;
            }

            $health = $target->health();

            if ($health === 'ok') {
                continue;
            }

            $seen[$key] = true;

            $findings[] = match ($health) {
                'deleted' => new Finding(
                    check: self::CHECK_BROKEN,
                    severity: Finding::SEVERITY_ERROR,
                    title: Craft::t('yarn', '{source} points at something in the trash', ['source' => $source->label]),
                    detail: Craft::t('yarn', '“{target}” has been deleted. The relation survives until the trash is emptied, and then it is gone — restore the element or clear the field.', [
                        'target' => $target->label,
                    ]),
                    subject: $source,
                    object: $target,
                    context: ['field' => $edge->label, 'via' => $edge->via],
                ),
                'absent' => new Finding(
                    check: self::CHECK_ABSENT,
                    severity: Finding::SEVERITY_WARNING,
                    title: Craft::t('yarn', '{source} points at an element that does not exist in this site', ['source' => $source->label]),
                    detail: Craft::t('yarn', '“{target}” has no content in this site, so the relation renders as nothing here. Usually a section or volume that was never propagated.', [
                        'target' => $target->label,
                    ]),
                    subject: $source,
                    object: $target,
                    context: ['field' => $edge->label, 'via' => $edge->via],
                ),
                default => new Finding(
                    check: self::CHECK_DISABLED,
                    severity: $source->isLive() ? Finding::SEVERITY_WARNING : Finding::SEVERITY_NOTICE,
                    title: Craft::t('yarn', '{source} points at “{target}”, which is {status}', [
                        'source' => $source->label,
                        'target' => $target->label,
                        'status' => $health,
                    ]),
                    detail: $source->isLive()
                        ? Craft::t('yarn', 'A live page relating to something that is not live. The front end will render a gap unless the template checks.')
                        : Craft::t('yarn', 'Both ends are off, so nothing is rendering wrongly — worth knowing when either one is switched back on.'),
                    subject: $source,
                    object: $target,
                    context: ['field' => $edge->label, 'status' => $health],
                ),
            };
        }

        return $findings;
    }

    /** @return Finding[] */
    private function unresolved(GraphModel $graph): array
    {
        $findings = [];

        foreach ($graph->unresolved as $row) {
            $node = $graph->node($row['elementId']);
            $isRefTag = $row['type'] === Edge::KIND_REF;

            $findings[] = new Finding(
                check: self::CHECK_UNRESOLVED,
                severity: $isRefTag ? Finding::SEVERITY_ERROR : Finding::SEVERITY_NOTICE,
                title: $isRefTag
                    ? Craft::t('yarn', 'Reference tag resolves to nothing: {ref}', ['ref' => $row['reference']])
                    : Craft::t('yarn', 'Link to no element: {ref}', ['ref' => $row['reference']]),
                detail: $isRefTag
                    ? Craft::t('yarn', 'Craft renders an unresolvable reference tag as the tag itself, braces and all, in the middle of the page.')
                    : Craft::t('yarn', 'An internal link Yarn could not tie to an element. Often fine — a routed template, a file outside the volumes — and sometimes a page that was deleted.'),
                subject: $node,
                context: ['reference' => $row['reference']],
            );
        }

        return $findings;
    }

    /** @return Finding[] */
    private function orphans(GraphModel $graph): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $kinds = array_values(array_diff($settings->orphanKinds, [Node::KIND_ASSET]));

        if ($kinds === []) {
            return [];
        }

        $findings = [];

        foreach ($graph->orphans($kinds) as $node) {
            if ($settings->orphansIgnoreRoutable && $node->uri !== null) {
                continue;
            }

            $findings[] = new Finding(
                check: self::CHECK_ORPHANS,
                severity: Finding::SEVERITY_NOTICE,
                title: Craft::t('yarn', 'Nothing points at {label}', ['label' => $node->label]),
                detail: $node->uri !== null
                    ? Craft::t('yarn', 'It has a URL of its own, so it is reachable — but no page on the site links to it.')
                    : Craft::t('yarn', 'It has no URL and nothing relates to it, so there is no route to it from the front end at all.'),
                subject: $node,
                context: ['group' => $node->group],
            );
        }

        return $findings;
    }

    /** @return Finding[] */
    private function unusedAssets(GraphModel $graph): array
    {
        if (!in_array(Node::KIND_ASSET, Plugin::getInstance()->getSettings()->orphanKinds, true)) {
            return [];
        }

        $findings = [];

        foreach ($graph->orphans([Node::KIND_ASSET]) as $node) {
            $findings[] = new Finding(
                check: self::CHECK_UNUSED_ASSETS,
                severity: Finding::SEVERITY_NOTICE,
                title: Craft::t('yarn', '{label} is not used anywhere', ['label' => $node->label]),
                detail: Craft::t('yarn', 'No field, reference tag or link reaches it. Check the sources Yarn is scanning before deleting — an asset referenced only from a template or a hard-coded URL is invisible to every one of them.'),
                subject: $node,
                context: ['volume' => $node->group],
            );
        }

        return $findings;
    }

    /** @return Finding[] */
    private function cycles(GraphModel $graph): array
    {
        $findings = [];
        $limit = Plugin::getInstance()->getSettings()->cycleLimit;

        foreach ($graph->cycles($limit) as $cycle) {
            $labels = [];

            foreach ($cycle as $id) {
                $node = $graph->node($id);
                $labels[] = $node?->label ?? "#$id";
            }

            $first = $graph->node($cycle[0]);

            $findings[] = new Finding(
                check: self::CHECK_CYCLES,
                severity: Finding::SEVERITY_WARNING,
                title: Craft::t('yarn', 'Circular relation through {count} elements', ['count' => count($cycle) - 1]),
                detail: implode(' → ', $labels),
                subject: $first,
                context: ['cycle' => $cycle],
            );
        }

        return $findings;
    }

    /** @return Finding[] */
    private function selfReferences(GraphModel $graph): array
    {
        $findings = [];
        $seen = [];

        foreach ($graph->edges as $edge) {
            if ($edge->from !== $edge->to || isset($seen[$edge->from])) {
                continue;
            }

            $node = $graph->node($edge->from);

            if ($node === null) {
                continue;
            }

            $seen[$edge->from] = true;

            $findings[] = new Finding(
                check: self::CHECK_SELF,
                severity: Finding::SEVERITY_WARNING,
                title: Craft::t('yarn', '{label} relates to itself', ['label' => $node->label]),
                detail: Craft::t('yarn', 'Through “{field}”. Harmless until a template follows relations recursively, at which point it is an infinite loop.', [
                    'field' => $edge->label !== '' ? $edge->label : $edge->kindLabel(),
                ]),
                subject: $node,
                context: ['field' => $edge->label],
            );
        }

        return $findings;
    }
}
