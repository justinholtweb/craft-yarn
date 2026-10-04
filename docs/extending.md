---
title: Extending Yarn
slug: extending
order: 50
summary: Contributing your own relations, adjusting the graph and adding findings from another plugin.
---

# Extending Yarn

Three events, and one interface.

## Contributing your own relations

The case this exists for: a plugin that stores references in **its own table** — a link field, a
bespoke picker, a menu builder — whose dependencies are therefore invisible to Craft’s `relations`
table and to Yarn alike.

Implement `EdgeSourceInterface` and register it:

```php
use craft\base\Event;
use justinholtweb\yarn\events\RegisterEdgeSourcesEvent;
use justinholtweb\yarn\models\BuildContext;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\services\Sources;
use justinholtweb\yarn\sources\BaseEdgeSource;

class MenuSource extends BaseEdgeSource
{
    public static function id(): string
    {
        return 'myPluginMenus';
    }

    public function displayName(): string
    {
        return 'Menu items';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function collect(): iterable
    {
        $rows = (new Query())
            ->select(['menuId', 'elementId'])
            ->from('{{%myplugin_menuitems}}')
            ->all();

        foreach ($rows as $row) {
            // Roll the source end up the same way everything else does, so a menu item held
            // inside a nested element is attributed to whatever owns it.
            [$from] = $this->context->rollUp((int)$row['menuId']);

            yield new Edge(
                from: $from,
                to: (int)$row['elementId'],
                kind: Edge::KIND_FIELD,
                label: 'Menu item',
            );
        }
    }
}

Event::on(Sources::class, Sources::EVENT_REGISTER_EDGE_SOURCES, function(RegisterEdgeSourcesEvent $e) {
    $e->sources[] = new MenuSource($e->context);
});
```

Things worth knowing:

- **`collect()` returns a generator.** The content sources walk every field value on the site;
  materialising them all before the graph can absorb them doubles peak memory for nothing.
- **Both ends have to be real content.** Yarn drops any edge whose ends are not nodes, so a
  relation to a draft or a revision quietly disappears — but filter them in your query anyway, or
  you will read a great many rows to no purpose.
- **Roll up the source end, never the target.** A relation targets whatever it targets.
- **`isOptional()`** defaults to true, which means Yarn will list your source under “not scanned”
  when it is switched off. Return false if it has no user-facing switch.

## Changing the assembled graph

`BuildGraphEvent` fires once the graph is complete and **before it is cached**, so what you do
here is baked into the cached copy.

```php
use justinholtweb\yarn\events\BuildGraphEvent;
use justinholtweb\yarn\services\Graph;

Event::on(Graph::class, Graph::EVENT_AFTER_BUILD_GRAPH, function(BuildGraphEvent $e) {
    // e.g. prune a section this site does not consider content
});
```

## Adding or removing findings

```php
use justinholtweb\yarn\events\DefineFindingsEvent;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\services\Findings;

Event::on(Findings::class, Findings::EVENT_DEFINE_FINDINGS, function(DefineFindingsEvent $e) {
    // Drop the ones this site does not care about
    $e->findings = array_values(array_filter(
        $e->findings,
        fn(Finding $f) => $f->check !== Findings::CHECK_UNUSED_ASSETS,
    ));

    // …or add your own
    foreach ($e->graph->hubs(5) as $node) {
        $e->findings[] = new Finding(
            check: 'myPlugin:hubs',
            severity: Finding::SEVERITY_NOTICE,
            title: "$node->label is referenced $node->inCount times",
            detail: 'Worth a look before restructuring.',
            subject: $node,
        );
    }
});
```

Findings are sorted by severity afterwards, so order does not matter.

## The graph itself

`Plugin::getInstance()->graph->get($siteId)` hands back a `Graph` model. It is a plain object with
no database behind it:

```php
$graph->node(1234);                       // a Node, or null
$graph->outgoing(1234);                   // Edge[] — what it points at
$graph->incoming(1234);                   // Edge[] — what points at it
$graph->neighbours(1234, 'both');         // distinct ids
$graph->ego(1234, 2);                     // id => hops, within two
$graph->dependents(1234, 3);              // everything that reaches it
$graph->path(1234, 5678);                 // Edge[] — the shortest chain
$graph->cycles(20);                       // int[][]
$graph->orphans(['asset']);               // Node[]
$graph->hubs(10);                         // Node[], most-referenced first
$graph->components();                     // int[][], largest first
$graph->stats();
```

Adjacency is stored as lists of **edge indexes**, not node ids, so two elements joined by several
distinct relations keep all of them. That is what makes “used four times” answerable.
