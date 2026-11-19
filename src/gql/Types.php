<?php

namespace justinholtweb\yarn\gql;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Yarn's GraphQL types — one per shape {@see \justinholtweb\yarn\services\Api} returns.
 *
 * Every field reads its array key with GraphQL's default resolver, so the API service is the
 * only place the shapes are defined; this file only names them. Fields are closures so types can
 * refer to each other in any order.
 */
class Types
{
    public static function node(): ObjectType
    {
        return self::type('YarnNode', 'An element as Yarn sees it. Elements the schema cannot read appear as “Restricted element #id”, with their relations still counted.', fn() => [
            'id' => Type::nonNull(Type::int()),
            'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'The element class.'],
            'kind' => ['type' => Type::nonNull(Type::string()), 'description' => 'entry, asset, category, tag, user, global or other.'],
            'label' => Type::nonNull(Type::string()),
            'group' => ['type' => Type::string(), 'description' => 'Section, volume or group name.'],
            'groupKey' => Type::string(),
            'uri' => Type::string(),
            'health' => ['type' => Type::nonNull(Type::string()), 'description' => 'ok, disabled, deleted, absent, or an entry status such as pending.'],
            'inCount' => ['type' => Type::nonNull(Type::int()), 'description' => 'How many relations point at it.'],
            'outCount' => ['type' => Type::nonNull(Type::int()), 'description' => 'How many relations it holds.'],
            'editUrl' => ['type' => Type::string(), 'description' => 'Its edit screen in the control panel.'],
        ]);
    }

    public static function linked(): ObjectType
    {
        return self::type('YarnLinkedElement', 'One element at the other end of a relation.', fn() => [
            'node' => Type::nonNull(self::node()),
            'via' => ['type' => Type::string(), 'description' => 'The nested block the relation physically lives in, when it was rolled up to its page.'],
        ]);
    }

    public static function group(): ObjectType
    {
        return self::type('YarnRelationGroup', 'Relations through one field, or of one kind.', fn() => [
            'field' => Type::nonNull(Type::string()),
            'kind' => ['type' => Type::nonNull(Type::string()), 'description' => 'field, nested, ref, url or structure.'],
            'kindLabel' => Type::nonNull(Type::string()),
            'elements' => Type::nonNull(Type::listOf(Type::nonNull(self::linked()))),
        ]);
    }

    public static function elementRelations(): ObjectType
    {
        return self::type('YarnElementRelations', 'Both directions for one element, and what deleting it would break.', fn() => [
            'site' => Type::nonNull(Type::string()),
            'element' => Type::nonNull(self::node()),
            'pointedAtBy' => Type::nonNull(Type::listOf(Type::nonNull(self::group()))),
            'pointsAt' => Type::nonNull(Type::listOf(Type::nonNull(self::group()))),
            'pointedAtByCount' => Type::nonNull(Type::int()),
            'pointsAtCount' => Type::nonNull(Type::int()),
            'deleteImpact' => Type::nonNull(self::type('YarnDeleteImpact', 'What would be left pointing at nothing.', fn() => [
                'direct' => Type::nonNull(Type::listOf(Type::nonNull(self::node()))),
                'indirect' => Type::nonNull(Type::listOf(Type::nonNull(self::type('YarnIndirectImpact', 'An element affected through others.', fn() => [
                    'hops' => Type::nonNull(Type::int()),
                    'node' => Type::nonNull(self::node()),
                ])))),
            ])),
            'caveat' => Type::nonNull(Type::string()),
        ]);
    }

    public static function findings(): ObjectType
    {
        return self::type('YarnFindings', 'Problems in the site’s relations, worst first.', fn() => [
            'site' => Type::nonNull(Type::string()),
            'total' => Type::nonNull(Type::int()),
            'tally' => Type::nonNull(Type::listOf(Type::nonNull(self::type('YarnFindingTally', 'How many findings one check produced.', fn() => [
                'check' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'count' => Type::nonNull(Type::int()),
            ])))),
            'findings' => Type::nonNull(Type::listOf(Type::nonNull(self::type('YarnFinding', 'One problem, with what to do about it.', fn() => [
                'check' => Type::nonNull(Type::string()),
                'severity' => ['type' => Type::nonNull(Type::string()), 'description' => 'error, warning or notice.'],
                'title' => Type::nonNull(Type::string()),
                'detail' => ['type' => Type::nonNull(Type::string()), 'description' => 'What to do about it.'],
                'subject' => self::node(),
                'object' => self::node(),
                'context' => [
                    'type' => Type::string(),
                    'description' => 'Check-specific details, as JSON.',
                    'resolve' => fn(array $finding) => $finding['context'] === [] ? null : json_encode($finding['context']),
                ],
            ])))),
            'truncated' => Type::nonNull(Type::boolean()),
            'caveat' => Type::nonNull(Type::string()),
        ]);
    }

    public static function unusedAssets(): ObjectType
    {
        return self::type('YarnUnusedAssets', 'Assets nothing points at.', fn() => [
            'site' => Type::nonNull(Type::string()),
            'total' => Type::nonNull(Type::int()),
            'assets' => Type::nonNull(Type::listOf(Type::nonNull(self::node()))),
            'truncated' => Type::nonNull(Type::boolean()),
            'caveat' => Type::nonNull(Type::string()),
        ]);
    }

    public static function path(): ObjectType
    {
        return self::type('YarnPath', 'The shortest chain of relations between two elements.', fn() => [
            'site' => Type::nonNull(Type::string()),
            'found' => Type::nonNull(Type::boolean()),
            'hops' => Type::nonNull(Type::int()),
            'steps' => Type::nonNull(Type::listOf(Type::nonNull(self::type('YarnEdge', 'One relation.', fn() => [
                'from' => Type::nonNull(Type::int()),
                'to' => Type::nonNull(Type::int()),
                'kind' => Type::nonNull(Type::string()),
                'kindLabel' => Type::nonNull(Type::string()),
                'label' => Type::string(),
                'via' => Type::string(),
                'fromLabel' => Type::string(),
                'toLabel' => Type::string(),
            ])))),
        ]);
    }

    private static function type(string $name, string $description, callable $fields): ObjectType
    {
        $existing = GqlEntityRegistry::getEntity($name);

        if ($existing instanceof ObjectType) {
            return $existing;
        }

        return GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'description' => $description,
            'fields' => $fields,
        ]));
    }
}
