<?php

namespace justinholtweb\yarn\sources;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\StringHelper;
use justinholtweb\yarn\models\Edge;
use justinholtweb\yarn\models\Settings;
use Throwable;

/**
 * Relations that live inside field *values* rather than in the relations table: reference tags,
 * and hard-coded links.
 *
 * This is the source that finds the dependencies nobody knew they had. A rich-text field with
 * `<a href="{entry:412:url}">` is every bit as broken by deleting entry 412 as an Entries field
 * would be, and Craft records it nowhere.
 *
 * Both matchers share one pass over `elements_sites`, because that pass is the entire cost —
 * running them separately reads every field value on the site twice.
 */
class ContentSource extends BaseEdgeSource
{
    /**
     * Craft's own reference tag grammar, copied rather than imported.
     *
     * `Elements::REF_TAG_PATTERN` only exists from Craft 5.10, and Yarn supports 5.3. A copy that
     * drifts is a bug; a copy that fails to exist is a fatal on install.
     */
    public const REF_TAG_PATTERN = '/
        \{
            (?P<elementType>[\w\\\\]+)
            \:(?P<ref>[^@\:\}\|]+)
            (?:@(?P<site>[^\:\}\|]+))?
            (?:\:(?P<attr>[^\}\| ]+))?
            (?:\ *\|\|\ *(?P<fallback>[^\}]+))?
        \}
    /x';

    /** `href="…"` and `src="…"`, single or double quoted. */
    public const URL_PATTERN = '/\b(?:href|src)\s*=\s*(["\'])(?P<url>[^"\']+)\1/i';

    /** Unresolved references are interesting, but not interesting enough to collect 40,000 of. */
    public const MAX_UNRESOLVED = 500;

    /**
     * References that pointed at nothing.
     *
     * @var array<int, array{elementId: int, reference: string, type: string}>
     */
    public array $unresolved = [];

    /** @var array<string, int>|null Site URI => element id. */
    private ?array $uriMap = null;

    /** @var array<string, array<int, string>>|null Lowercased filename => [asset id => volume path]. */
    private ?array $assetMap = null;

    /** @var string[]|null Every site's base URL host, for telling internal links from external. */
    private ?array $internalHosts = null;

    /** @var array<string, string|null> Ref handle => element class, memoized. */
    private array $refHandles = [];

    public static function id(): string
    {
        return 'content';
    }

    public function displayName(): string
    {
        return Craft::t('yarn', 'Content references');
    }

    public function isEnabled(): bool
    {
        return $this->wantsRefTags() || $this->wantsUrls();
    }

    private function wantsRefTags(): bool
    {
        return $this->context->settings->uses(Settings::SOURCE_REF_TAGS);
    }

    private function wantsUrls(): bool
    {
        return $this->context->settings->uses(Settings::SOURCE_URLS);
    }

    public function collect(): iterable
    {
        $batchSize = $this->context->settings->scanBatchSize;

        $query = (new Query())
            ->select(['es.elementId', 'es.content'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where([
                'es.siteId' => $this->context->siteId,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['es.content' => null]]);

        foreach ($query->batch($batchSize) as $rows) {
            yield from $this->scanBatch($rows);
        }
    }

    /**
     * One batch, resolved together.
     *
     * Reference tags may name an element by UID, and resolving those one at a time would be a
     * query per tag. Collecting a batch's worth first costs one query per 500 rows instead.
     *
     * @param array<int, array{elementId: mixed, content: mixed}> $rows
     * @return iterable<Edge>
     */
    private function scanBatch(array $rows): iterable
    {
        /** @var array<int, array{from: int, to: int|null, uid: string|null, kind: string, label: string, raw: string}> $pending */
        $pending = [];
        $uids = [];
        $ids = [];

        foreach ($rows as $row) {
            $elementId = (int)$row['elementId'];

            foreach ($this->strings($row['content']) as $value) {
                if ($this->wantsRefTags()) {
                    foreach ($this->matchRefTags($elementId, $value) as $hit) {
                        $pending[] = $hit;

                        if ($hit['uid'] !== null) {
                            $uids[$hit['uid']] = true;
                        } elseif ($hit['to'] !== null) {
                            $ids[$hit['to']] = true;
                        }
                    }
                }

                if ($this->wantsUrls()) {
                    foreach ($this->matchUrls($elementId, $value) as $hit) {
                        $pending[] = $hit;
                    }
                }
            }
        }

        $byUid = $uids !== [] ? $this->resolveUids(array_keys($uids)) : [];
        // `{entry:8842:url}` where 8842 no longer exists renders as the tag itself, braces and
        // all, in the middle of the page — so an id has to be checked, not trusted.
        $live = $ids !== [] ? $this->existingIds(array_keys($ids)) : [];

        foreach ($pending as $hit) {
            if ($hit['uid'] !== null) {
                $target = $byUid[$hit['uid']] ?? null;
            } elseif ($hit['kind'] === Edge::KIND_REF) {
                $target = isset($live[$hit['to']]) ? $hit['to'] : null;
            } else {
                // A URL hit was resolved against the site's own URI and asset maps, which are
                // built from live elements — there is nothing left to verify.
                $target = $hit['to'];
            }

            if ($target === null) {
                $this->noteUnresolved($hit['from'], $hit['raw'], $hit['kind']);
                continue;
            }

            [$from, $ownerField] = $this->context->rollUp($hit['from']);

            if ($from === $target) {
                continue;
            }

            yield new Edge(
                from: $from,
                to: $target,
                kind: $hit['kind'],
                label: $hit['label'],
                via: $from !== $hit['from'] ? $this->context->fieldName($ownerField) : null,
                viaId: $from !== $hit['from'] ? $hit['from'] : null,
            );
        }
    }

    /**
     * Every string inside a stored content value.
     *
     * The column is JSON keyed by field layout element UID, and a rich-text value can be nested
     * inside a structure a matcher has no business knowing about. Walking to the leaves and
     * matching there beats matching the raw JSON: `json_encode` escapes `/` as `\/`, so a regex
     * for `href="/news/x"` written against the raw column silently never matches anything.
     *
     * @return iterable<string>
     */
    private function strings(mixed $content): iterable
    {
        // Decoded up to twice. Once is the normal case; the second pass covers a column that has
        // been written through a query builder that encoded an already-encoded string, which
        // leaves a JSON *string* holding JSON. Read at one level, every `/` in it is still spelled
        // `\/` and no link matcher will ever fire.
        for ($pass = 0; $pass < 2 && is_string($content); $pass++) {
            $decoded = json_decode($content, true, 64);

            if (!is_string($decoded) && !is_array($decoded)) {
                break;
            }

            $content = $decoded;
        }

        if (is_string($content)) {
            yield $content;
            return;
        }

        if (!is_array($content)) {
            return;
        }

        $stack = [$content];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($current as $value) {
                if (is_string($value)) {
                    if ($value !== '') {
                        yield $value;
                    }
                } elseif (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    /**
     * @return array<int, array{from: int, to: int|null, uid: string|null, kind: string, label: string, raw: string}>
     */
    private function matchRefTags(int $elementId, string $value): array
    {
        if (!str_contains($value, '{')) {
            return [];
        }

        if (!preg_match_all(self::REF_TAG_PATTERN, $value, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $hits = [];

        foreach ($matches as $match) {
            if ($this->elementTypeForRefHandle($match['elementType']) === null) {
                // `{siteUrl}` and every other Twig-looking brace pair lands here. Not a broken
                // reference — not a reference.
                continue;
            }

            $ref = trim($match['ref']);
            $numeric = ctype_digit($ref);
            $uid = StringHelper::isUUID($ref);

            if (!$numeric && !$uid) {
                // Craft lets an element type resolve a reference by anything it likes — a slug, a
                // filename, a plugin's own handle for one of its rows. Yarn resolves ids and UIDs,
                // and *skips* the rest rather than reporting them as broken: a tag it simply did
                // not try to resolve is not a tag that failed. Calling those errors turns every
                // `{legs:pricing-table:render}` on the site into a red row.
                continue;
            }

            $hits[] = [
                'from' => $elementId,
                'to' => $numeric ? (int)$ref : null,
                'uid' => $uid ? $ref : null,
                'kind' => Edge::KIND_REF,
                'label' => Craft::t('yarn', 'Reference tag'),
                'raw' => $match[0],
            ];
        }

        return $hits;
    }

    /**
     * @return array<int, array{from: int, to: int|null, uid: string|null, kind: string, label: string, raw: string}>
     */
    private function matchUrls(int $elementId, string $value): array
    {
        if (!str_contains($value, '=')) {
            return [];
        }

        if (!preg_match_all(self::URL_PATTERN, $value, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $hits = [];

        foreach ($matches as $match) {
            $url = html_entity_decode($match['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = $this->internalPath($url);

            if ($path === null) {
                continue;
            }

            $target = $this->uriMap()[$path] ?? $this->assetFor($path);

            $hits[] = [
                'from' => $elementId,
                'to' => $target,
                'uid' => null,
                'kind' => Edge::KIND_URL,
                'label' => Craft::t('yarn', 'Link'),
                'raw' => $url,
            ];
        }

        return $hits;
    }

    /**
     * The site-relative path a URL points at, or null when it points somewhere Yarn has no
     * business having an opinion about.
     *
     * Absolute URLs on another host, `mailto:`, `tel:`, anchors and reference tags that have
     * already been matched as reference tags are all rejected here.
     */
    private function internalPath(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || $url[0] === '#' || str_contains($url, '{')) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            $host = parse_url($url, PHP_URL_HOST);

            if ($host === null || $host === false || !in_array(strtolower($host), $this->internalHosts(), true)) {
                return null;
            }

            $url = (string)parse_url($url, PHP_URL_PATH);
        } elseif (str_starts_with($url, '//')) {
            return null;
        }

        $path = (string)strtok($url, '?#');

        return trim($path, '/');
    }

    /** @return array<string, int> */
    private function uriMap(): array
    {
        if ($this->uriMap !== null) {
            return $this->uriMap;
        }

        $this->uriMap = [];

        $rows = (new Query())
            ->select(['es.elementId', 'es.uri'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where([
                'es.siteId' => $this->context->siteId,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['not', ['es.uri' => null]])
            ->all();

        foreach ($rows as $row) {
            $uri = (string)$row['uri'];
            // Craft stores the homepage as `__home__`; in a link it is the empty path.
            $this->uriMap[$uri === '__home__' ? '' : $uri] = (int)$row['elementId'];
        }

        return $this->uriMap;
    }

    /**
     * Finds the asset a URL path ends with.
     *
     * Matching on the tail rather than on a reconstructed base URL is deliberate: the same asset
     * is reachable through the volume's own URL, through a CDN, and through a transform directory,
     * and all three end in the same volume-relative path. Reconstructing the base URL matches one
     * of the three and misses the two that people actually paste into content.
     */
    private function assetFor(string $path): ?int
    {
        $filename = strtolower(basename($path));

        if ($filename === '') {
            return null;
        }

        $candidates = $this->assetMap()[$filename] ?? null;

        if ($candidates === null) {
            return null;
        }

        $haystack = '/' . strtolower($path);
        $best = null;
        $bestLength = -1;

        foreach ($candidates as $assetId => $assetPath) {
            $needle = '/' . strtolower($assetPath);

            if (str_ends_with($haystack, $needle) && strlen($needle) > $bestLength) {
                $best = $assetId;
                $bestLength = strlen($needle);
            }
        }

        // A bare filename with no folder still matches, but only when it is unambiguous. Two
        // volumes both holding `logo.png` would otherwise produce a confident wrong answer.
        if ($best === null && count($candidates) === 1) {
            $best = array_key_first($candidates);
        }

        return $best;
    }

    /** @return array<string, array<int, string>> */
    private function assetMap(): array
    {
        if ($this->assetMap !== null) {
            return $this->assetMap;
        }

        $this->assetMap = [];

        $rows = (new Query())
            ->select(['a.id', 'a.filename', 'f.path'])
            ->from(['a' => Table::ASSETS])
            ->innerJoin(['f' => Table::VOLUMEFOLDERS], '[[f.id]] = [[a.folderId]]')
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[a.id]]')
            ->where([
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.archived' => false,
                'e.dateDeleted' => null,
            ])
            ->all();

        foreach ($rows as $row) {
            $filename = (string)$row['filename'];
            $key = strtolower($filename);
            $this->assetMap[$key][(int)$row['id']] = trim((string)$row['path'], '/') !== ''
                ? trim((string)$row['path'], '/') . '/' . $filename
                : $filename;
        }

        return $this->assetMap;
    }

    /** @return string[] */
    private function internalHosts(): array
    {
        if ($this->internalHosts !== null) {
            return $this->internalHosts;
        }

        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url($site->getBaseUrl() ?? '', PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }
        }

        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest()) {
            $hosts[strtolower($request->getHostName() ?? '')] = true;
            unset($hosts['']);
        }

        return $this->internalHosts = array_keys($hosts);
    }

    /**
     * @param string[] $uids
     * @return array<string, int>
     */
    private function resolveUids(array $uids): array
    {
        $rows = (new Query())
            ->select(['id', 'uid'])
            ->from([Table::ELEMENTS])
            ->where(['uid' => $uids])
            ->all();

        $map = [];

        foreach ($rows as $row) {
            $map[(string)$row['uid']] = (int)$row['id'];
        }

        return $map;
    }

    /**
     * @param int[] $ids
     * @return array<int, true>
     */
    private function existingIds(array $ids): array
    {
        $found = (new Query())
            ->select(['id'])
            ->from([Table::ELEMENTS])
            ->where(['id' => $ids, 'dateDeleted' => null])
            ->column();

        return array_fill_keys(array_map('intval', $found), true);
    }

    private function noteUnresolved(int $elementId, string $reference, string $kind): void
    {
        if (count($this->unresolved) >= self::MAX_UNRESOLVED) {
            return;
        }

        $this->unresolved[] = [
            'elementId' => $elementId,
            'reference' => $reference,
            'type' => $kind,
        ];
    }

    private function elementTypeForRefHandle(string $handle): ?string
    {
        if (!array_key_exists($handle, $this->refHandles)) {
            try {
                $this->refHandles[$handle] = Craft::$app->getElements()->getElementTypeByRefHandle($handle);
            } catch (Throwable) {
                $this->refHandles[$handle] = null;
            }
        }

        return $this->refHandles[$handle];
    }
}
