<?php

// phpcs:disable Generic.Files.LineLength

namespace CluebotNG\Tests;

/*
 * Copyright (C) 2015 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use CluebotNG\Action;
use CluebotNG\Api;

final class WarningTest extends TestCase
{
    private const USER = '~2026-18366-2';
    private const TALK = 'User talk:~2026-18366-2';

    private FakeWiki $wiki;

    protected function setUp(): void
    {
        $this->wiki = new FakeWiki();
        Api::$h = $this->wiki;
        Api::$a = $this->wiki;
        Api::$q = $this->wiki;
    }

    private function call(string $method, array $args)
    {
        return (new ReflectionMethod(Action::class, $method))->invokeArgs(null, $args);
    }

    private function change(string $namespacedTitle = 'Example.jpg'): array
    {
        return [
            'user' => self::USER,
            'title' => 'Example.jpg',
            'namespaced_title' => $namespacedTitle,
            'mysqlid' => 4488504,
        ];
    }

    /**
     * Mirrors doWarn (without the dry run / relay / metrics side effects).
     */
    private function warnOnce(string $namespacedTitle = 'Example.jpg'): int
    {
        $level = Action::getWarningLevel(self::USER, $content) + 1;
        if ($level < 4) {
            $this->call('warn', [$this->change($namespacedTitle), 'REPORT', $content, $level]);
        }
        return $level;
    }

    private function page(): ?string
    {
        return $this->wiki->pages[self::TALK] ?? null;
    }

    private function monthHeader(): string
    {
        return '== ' . gmdate('F Y', FakeWiki::now()) . ' ==';
    }

    private function oldSignedLine(string $template): string
    {
        return 'Thank you. <!-- Template:' . $template . ' --> [[User:Someone|Someone]] 05:43, 1 March 2020 (UTC)';
    }

    // splitSectionHeader

    public function testSplitHeaderFromLevel1(): void
    {
        [$header, $text] = $this->call('splitSectionHeader', [FakeWiki::fixture(1)]);
        $this->assertSame($this->monthHeader(), $header);
        $this->assertStringStartsWith('[[File:Information.svg|', $text);
        $this->assertStringEndsWith('<!-- Template:uw-vandalism1 -->', $text);
    }

    public function testNoHeaderFromLevel2And3(): void
    {
        foreach ([2, 3] as $level) {
            $this->assertSame([null, FakeWiki::fixture($level)], $this->call('splitSectionHeader', [FakeWiki::fixture($level)]));
        }
    }

    public function testSplitHeaderVariants(): void
    {
        $this->assertSame(['== March 2026 ==', "text"], $this->call('splitSectionHeader', ["== March 2026 ==\ntext"]));
        $this->assertSame(['== March 2026 ==', "text"], $this->call('splitSectionHeader', ["\n\n== March 2026 ==  \ntext"]));
        $this->assertSame(['==March 2026==', "a\nb"], $this->call('splitSectionHeader', ["==March 2026==\na\nb"]));
    }

    public function testSplitHeaderIgnoresNonMonthHeaders(): void
    {
        foreach (
            [
                "== Warning ==\ntext",
                "=== March 2026 ===\ntext",
                "== March 2026 ==",
                "text\n== March 2026 ==\ntext",
                "{{subst:User:ClueBot NG/Warnings/Warning|1=1|2=Foo|3=r|4=1}}",
                "",
            ] as $wikitext
        ) {
            $this->assertSame([null, $wikitext], $this->call('splitSectionHeader', [$wikitext]), json_encode($wikitext));
        }
    }

    // getWarningText

    public function testWarningTextParseRequest(): void
    {
        $this->call('getWarningText', [$this->change('File:Example.jpg'), 'REPORT', 1]);

        $this->assertCount(1, $this->wiki->gets);
        parse_str(parse_url($this->wiki->gets[0], PHP_URL_QUERY), $query);
        $this->assertSame('parse', $query['action']);
        $this->assertSame('true', $query['onlypst']);
        $this->assertSame(self::TALK, $query['title']);
        $this->assertSame(
            '{{subst:User:ClueBot NG/Warnings/Warning|1=1|2=:File:Example.jpg|3=REPORT' .
            ' <!{{subst:ns:0}}-- MySQL ID: 4488504 --{{subst:ns:0}}>|4=4488504}}',
            $query['text']
        );
    }

    public function testWarningTextUsesNamespacedTitle(): void
    {
        $this->call('getWarningText', [$this->change('Wikipedia:Sandbox'), 'REPORT', 2]);
        parse_str(parse_url($this->wiki->gets[0], PHP_URL_QUERY), $query);
        $this->assertStringContainsString('|2=Wikipedia:Sandbox|', $query['text']);
    }

    public function testWarningTextSignatureIsLeftForSave(): void
    {
        foreach ([1, 2, 3] as $level) {
            [, $text] = $this->call('getWarningText', [$this->change(), 'REPORT', $level]);
            $this->assertStringEndsWith(
                '<!-- Template:uw-cluebotwarning' . $level . ' --><!-- Template:uw-vandalism' . $level . ' --> ~~~~',
                $text
            );
            $this->assertSame(1, substr_count($text, '~~~~'));
        }
    }

    public function testWarningTextHeaderOnlyForLevel1(): void
    {
        $this->assertSame($this->monthHeader(), $this->call('getWarningText', [$this->change(), 'REPORT', 1])[0]);
        $this->assertNull($this->call('getWarningText', [$this->change(), 'REPORT', 2])[0]);
        $this->assertNull($this->call('getWarningText', [$this->change(), 'REPORT', 3])[0]);
    }

    public function testWarningTextFallsBackToRawSubstitution(): void
    {
        $this->wiki->parseFails = true;
        [$header, $text] = $this->call('getWarningText', [$this->change(), 'REPORT', 1]);
        $this->assertNull($header);
        $this->assertSame(
            '{{subst:User:ClueBot NG/Warnings/Warning|1=1|2=Example.jpg|3=REPORT' .
            ' <!{{subst:ns:0}}-- MySQL ID: 4488504 --{{subst:ns:0}}>|4=4488504}} ~~~~',
            $text
        );
    }

    public function testWarningTextFallsBackOnApiError(): void
    {
        $wiki = new class extends FakeWiki {
            public function get($url)
            {
                return json_encode(['error' => ['code' => 'internal_api_error']]);
            }
        };
        Api::$h = $wiki;
        [$header, $text] = $this->call('getWarningText', [$this->change(), 'REPORT', 1]);
        $this->assertNull($header);
        $this->assertStringStartsWith('{{subst:User:ClueBot NG/Warnings/Warning|1=1|', $text);
    }

    // warn / getWarningLevel end to end

    public function testEditParameters(): void
    {
        $this->warnOnce();
        $this->assertCount(1, $this->wiki->edits);
        $edit = $this->wiki->edits[0];
        $this->assertSame(self::TALK, $edit['page']);
        $this->assertSame('Warning [[Special:Contributions/' . self::USER . '|' . self::USER . ']] - #1', $edit['summary']);
        $this->assertFalse($edit['minor']);
        $this->assertFalse($edit['bot']);
    }

    public function testFullEscalationOnNewTalkPage(): void
    {
        $this->assertSame(1, $this->warnOnce());
        $this->assertSame(FakeWiki::fixture(1) . ' ' . FakeWiki::signature(), $this->page());
        $this->assertSame(1, Action::getWarningLevel(self::USER));

        $this->assertSame(2, $this->warnOnce());
        $this->assertSame(2, Action::getWarningLevel(self::USER));

        $this->assertSame(3, $this->warnOnce());
        $this->assertSame(3, Action::getWarningLevel(self::USER));

        // Next would be an AIV report, not another warning
        $this->assertSame(4, $this->warnOnce());
        $this->assertCount(3, $this->wiki->edits);

        $this->assertSame(
            FakeWiki::fixture(1) . ' ' . FakeWiki::signature() . "\n\n" .
            FakeWiki::fixture(2) . ' ' . FakeWiki::signature() . "\n\n" .
            FakeWiki::fixture(3) . ' ' . FakeWiki::signature(),
            $this->page()
        );
        $this->assertSame(1, substr_count($this->page(), $this->monthHeader()));
    }

    public function testBlankedTalkPage(): void
    {
        $this->wiki->pages[self::TALK] = '';
        $this->assertSame(0, Action::getWarningLevel(self::USER));
        $this->assertSame(1, $this->warnOnce());
        $this->assertSame(FakeWiki::fixture(1) . ' ' . FakeWiki::signature(), $this->page());
    }

    public function testEscalationAfterHumanWarning(): void
    {
        foreach (['uw-vandalism1' => 2, 'uw-vandalism2' => 3, 'uw-test3' => 4, 'uw-vandalism4im' => 5] as $template => $expected) {
            $this->setUp();
            $this->wiki->pages[self::TALK] = $this->monthHeader() . "\n" .
                'Stop. <!-- Template:' . $template . ' --> [[User:Someone|Someone]] ' . gmdate('H:i, j F Y', FakeWiki::now()) . ' (UTC)';
            $this->assertSame($expected, $this->warnOnce(), $template);
            if ($expected < 4) {
                $this->assertSame(1, substr_count($this->page(), $this->monthHeader()), $template);
                $this->assertSame($expected, Action::getWarningLevel(self::USER), $template);
            } else {
                $this->assertCount(0, $this->wiki->edits, $template);
            }
        }
    }

    /**
     * The reported case: an older (expired) warning from someone else under this month's header.
     */
    public function testReusesExistingMonthHeader(): void
    {
        $existing = $this->monthHeader() . "\n\n" . $this->oldSignedLine('uw-unsourced1');
        $this->wiki->pages[self::TALK] = $existing;

        $this->assertSame(1, $this->warnOnce());
        $this->assertSame(
            $existing . "\n\n" . substr(FakeWiki::fixture(1), strlen($this->monthHeader()) + 1) . ' ' . FakeWiki::signature(),
            $this->page()
        );
        $this->assertSame(1, substr_count($this->page(), $this->monthHeader()));
        $this->assertSame(1, Action::getWarningLevel(self::USER));

        $this->assertSame(2, $this->warnOnce());
        $this->assertSame(3, $this->warnOnce());
        $this->assertSame(1, substr_count($this->page(), $this->monthHeader()));
        $this->assertSame(3, Action::getWarningLevel(self::USER));
    }

    public function testNewHeaderWhenLastSectionIsPreviousMonth(): void
    {
        $existing = "== January 2020 ==\n" . $this->oldSignedLine('uw-vandalism3');
        $this->wiki->pages[self::TALK] = $existing;

        $this->assertSame(1, $this->warnOnce());
        $this->assertSame($existing . "\n\n" . FakeWiki::fixture(1) . ' ' . FakeWiki::signature(), $this->page());
    }

    public function testNewHeaderWhenMonthSectionIsNotLast(): void
    {
        $existing = $this->monthHeader() . "\n" . $this->oldSignedLine('uw-unsourced1') .
            "\n\n== Question about your edit ==\nWhy? [[User:Someone|Someone]] 05:43, 1 March 2020 (UTC)";
        $this->wiki->pages[self::TALK] = $existing;

        $this->assertSame(1, $this->warnOnce());
        $this->assertStringStartsWith($existing . "\n\n", $this->page());
        $this->assertStringEndsWith(FakeWiki::fixture(1) . ' ' . FakeWiki::signature(), $this->page());
        $this->assertSame(2, substr_count($this->page(), $this->monthHeader()));
    }

    public function testLevel2And3NeverAddHeader(): void
    {
        foreach ([2, 3] as $level) {
            $this->setUp();
            $this->wiki->pages[self::TALK] = "== Something else ==\nhello";
            $this->call('warn', [$this->change(), 'REPORT', $this->wiki->pages[self::TALK], $level]);
            $this->assertSame(
                "== Something else ==\nhello\n\n" . FakeWiki::fixture($level) . ' ' . FakeWiki::signature(),
                $this->page()
            );
        }
    }

    public function testParseFailureBehavesAsBefore(): void
    {
        $this->wiki->parseFails = true;
        $this->wiki->pages[self::TALK] = $this->monthHeader() . "\nhello";

        $this->assertSame(1, $this->warnOnce());
        $this->assertSame(
            $this->monthHeader() . "\nhello\n\n" .
            '{{subst:User:ClueBot NG/Warnings/Warning|1=1|2=Example.jpg|3=REPORT' .
            ' <!{{subst:ns:0}}-- MySQL ID: 4488504 --{{subst:ns:0}}>|4=4488504}} ~~~~' . "\n",
            $this->wiki->edits[0]['data']
        );
        $this->assertSame(1, Action::getWarningLevel(self::USER));

        $this->assertSame(2, $this->warnOnce());
        $this->assertSame(2, Action::getWarningLevel(self::USER));
    }

    public function testFailedEditDoesNotThrow(): void
    {
        $this->wiki->editResult = false;
        $this->assertSame(1, $this->warnOnce());
        $this->assertNull($this->page());
        $this->assertCount(1, $this->wiki->edits);
    }

    // getWarningLevel

    public function testWarningLevelIgnoresExpiredWarnings(): void
    {
        $this->wiki->pages[self::TALK] = $this->monthHeader() . "\n" . $this->oldSignedLine('uw-vandalism3');
        $this->assertSame(0, Action::getWarningLevel(self::USER));
    }

    public function testWarningLevelUsesHighestRecent(): void
    {
        $now = gmdate('H:i, j F Y', FakeWiki::now());
        $this->wiki->pages[self::TALK] =
            "== A ==\nx <!-- Template:uw-vandalism3 --> [[User:A|A]] {$now} (UTC)\n\n" .
            "== B ==\ny <!-- Template:uw-vandalism1 --> [[User:B|B]] {$now} (UTC)";
        $this->assertSame(3, Action::getWarningLevel(self::USER));
    }

    public function testWarningLevelMissingPage(): void
    {
        $this->assertSame(0, Action::getWarningLevel(self::USER, $content));
        $this->assertNull($content);
    }
}
