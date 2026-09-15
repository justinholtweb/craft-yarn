<?php

namespace justinholtweb\yarn\tests\unit;

use justinholtweb\yarn\sources\ContentSource;
use PHPUnit\Framework\TestCase;

/**
 * Yarn carries its own copy of Craft's reference tag grammar, because
 * `Elements::REF_TAG_PATTERN` only exists from 5.10 and Yarn supports 5.3.
 *
 * A copy that drifts is a bug, so these pin the shapes it has to keep matching.
 */
class RefTagTest extends TestCase
{
    /** @return array<int, array<string, string>> */
    private function refs(string $subject): array
    {
        preg_match_all(ContentSource::REF_TAG_PATTERN, $subject, $matches, PREG_SET_ORDER);

        return $matches;
    }

    public function testAPlainIdReference(): void
    {
        $matches = $this->refs('<a href="{entry:42:url}">read on</a>');

        self::assertCount(1, $matches);
        self::assertSame('entry', $matches[0]['elementType']);
        self::assertSame('42', $matches[0]['ref']);
        self::assertSame('url', $matches[0]['attr']);
    }

    public function testAReferenceWithNoAttribute(): void
    {
        $matches = $this->refs('{asset:7}');

        self::assertCount(1, $matches);
        self::assertSame('asset', $matches[0]['elementType']);
        self::assertSame('7', $matches[0]['ref']);
    }

    public function testASiteScopedReference(): void
    {
        $matches = $this->refs('{entry:42@french:title}');

        self::assertSame('42', $matches[0]['ref']);
        self::assertSame('french', $matches[0]['site']);
        self::assertSame('title', $matches[0]['attr']);
    }

    public function testAFallback(): void
    {
        $matches = $this->refs('{entry:42:title || Untitled}');

        self::assertSame('42', $matches[0]['ref']);
        self::assertSame('Untitled', $matches[0]['fallback']);
    }

    public function testAUidReference(): void
    {
        $uid = '0f2f4a8e-1c6d-4f0e-9d3b-2b8c1a5e7d40';
        $matches = $this->refs("{entry:$uid:url}");

        self::assertSame($uid, $matches[0]['ref']);
    }

    public function testAFullyQualifiedElementClass(): void
    {
        $matches = $this->refs('{craft\\elements\\Entry:42:url}');

        self::assertSame('craft\\elements\\Entry', $matches[0]['elementType']);
        self::assertSame('42', $matches[0]['ref']);
    }

    public function testSeveralInOneValue(): void
    {
        self::assertCount(3, $this->refs('{entry:1:url} {asset:2:url} {category:3:title}'));
    }

    public function testTwigLooksNothingLikeAReference(): void
    {
        // The thing that would otherwise fill the findings screen with noise.
        self::assertCount(0, $this->refs('{{ entry.title }}'));
        self::assertCount(0, $this->refs('{% if entry %}yes{% endif %}'));
        self::assertCount(0, $this->refs('style="grid-template-columns: repeat(2, 1fr)"'));
    }

    public function testUrlPatternFindsBothAttributes(): void
    {
        preg_match_all(
            ContentSource::URL_PATTERN,
            '<a href="/news/one">x</a><img src=\'/uploads/a.jpg\'><a href="https://elsewhere.test/x">y</a>',
            $matches,
            PREG_SET_ORDER,
        );

        self::assertCount(3, $matches);
        self::assertSame('/news/one', $matches[0]['url']);
        self::assertSame('/uploads/a.jpg', $matches[1]['url']);
        self::assertSame('https://elsewhere.test/x', $matches[2]['url']);
    }
}
