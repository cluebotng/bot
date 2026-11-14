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

final class SectionManglerTest extends TestCase
{
    private function mangle(?string $content, ?string $header, string $text): string
    {
        return (new ReflectionMethod(Action::class, 'mangleSectionIntoPage'))
            ->invokeArgs(null, [$content, $header, $text]);
    }

    public function testNoExistingPageData(): void
    {
        $this->assertSame(
            "== Example 10 ==\nVery important text\n",
            $this->mangle(null, "== Example 10 ==", "Very important text")
        );
    }

    public function testNoExistingPageDataNoHeader(): void
    {
        $this->assertSame(
            "Very important text\n",
            $this->mangle(null, null, "Very important text")
        );
    }

    public function testBlankedPageDoesNotGetLeadingNewlines(): void
    {
        foreach (['', "\n", "  \n\n"] as $content) {
            $this->assertSame(
                "== Example 10 ==\nVery important text\n",
                $this->mangle($content, "== Example 10 ==", "Very important text")
            );
            $this->assertSame(
                "Very important text\n",
                $this->mangle($content, null, "Very important text")
            );
        }
    }

    public function testNullHeaderAppendsToContent(): void
    {
        $this->assertSame(
            "== something ==\nvery interesting\n\nVery important text\n",
            $this->mangle("== something ==\nvery interesting", null, "Very important text")
        );
    }

    public function testNullHeaderAppendsToMultiSectionPage(): void
    {
        $this->assertSame(
            "== A ==\nfoo\n\n== B ==\nbar\n\nVery important text\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar", null, "Very important text")
        );
    }

    public function testNullHeaderAppendsToPageWithoutHeaders(): void
    {
        $this->assertSame(
            "{{Welcome}}\n\nVery important text\n",
            $this->mangle("{{Welcome}}", null, "Very important text")
        );
    }

    public function testTrailingWhitespaceIsNormalised(): void
    {
        $this->assertSame(
            "== A ==\nfoo\n\nnew\n",
            $this->mangle("== A ==\nfoo\n\n\n", null, "new")
        );
        $this->assertSame(
            "== A ==\nfoo\n\nnew\n",
            $this->mangle("== A ==\nfoo  \n", "== A ==", "new")
        );
    }

    public function testNoExistingSectionHeader(): void
    {
        $this->assertSame(
            "== something ==\nvery interesting\n\n== Example 10 ==\nVery important text\n",
            $this->mangle("== something ==\nvery interesting", "== Example 10 ==", "Very important text")
        );
    }

    public function testPageWithoutHeadersGetsNewHeader(): void
    {
        $this->assertSame(
            "{{Welcome}}\n\n== A ==\nnew\n",
            $this->mangle("{{Welcome}}", "== A ==", "new")
        );
    }

    public function testNewSectionAppendedAfterMultipleSections(): void
    {
        $this->assertSame(
            "== A ==\nfoo\n\n== B ==\nbar\n\n== C ==\nnew text\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar", "== C ==", "new text")
        );
    }

    public function testExistingSectionHeader(): void
    {
        $this->assertSame(
            "== Example 10 ==\nvery interesting\n\nVery important text\n",
            $this->mangle("== Example 10 ==\nvery interesting", "== Example 10 ==", "Very important text")
        );
    }

    public function testExistingSectionWithOnlyHeader(): void
    {
        $this->assertSame(
            "== A ==\n\nnew\n",
            $this->mangle("== A ==", "== A ==", "new")
        );
    }

    public function testExistingSectionPrecededByContent(): void
    {
        $this->assertSame(
            "{{Welcome}}\n\n== A ==\nfoo\n\nnew\n",
            $this->mangle("{{Welcome}}\n\n== A ==\nfoo", "== A ==", "new")
        );
    }

    public function testExistingLastSectionAfterOtherSections(): void
    {
        $this->assertSame(
            "== January 2026 ==\nold\n\n== February 2026 ==\nexisting\n\nnew\n",
            $this->mangle("== January 2026 ==\nold\n\n== February 2026 ==\nexisting", "== February 2026 ==", "new")
        );
    }

    /**
     * The section exists, but is not the last one on the page, so we must not insert into the middle
     * of the page (the new text would appear above newer discussions); a fresh header is appended instead.
     */
    public function testExistingSectionNotLastGetsNewHeaderAtBottom(): void
    {
        $this->assertSame(
            "== January 2026 ==\nexisting text\n\n== February 2026 ==\nother stuff\n\n== January 2026 ==\nnew text\n",
            $this->mangle("== January 2026 ==\nexisting text\n\n== February 2026 ==\nother stuff", "== January 2026 ==", "new text")
        );
        $this->assertSame(
            "== A ==\nfoo\n\n== B ==\nbar\n\n== C ==\nbaz\n\n== B ==\nnew text\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar\n\n== C ==\nbaz", "== B ==", "new text")
        );
    }

    /**
     * A page already containing the duplicated header (from before this change) re-uses the last one.
     */
    public function testDuplicateHeadersReuseLast(): void
    {
        $this->assertSame(
            "== A ==\none\n\n== A ==\ntwo\n\nthree\n",
            $this->mangle("== A ==\none\n\n== A ==\ntwo", "== A ==", "three")
        );
    }

    public function testLastHeaderIsSubSectionGetsNewHeader(): void
    {
        $this->assertSame(
            "== A ==\nvery interesting\n=== dolphins ===\nare fun\n\n== A ==\nnew\n",
            $this->mangle("== A ==\nvery interesting\n=== dolphins ===\nare fun", "== A ==", "new")
        );
    }

    public function testDifferentLevelHeaderIsNotReused(): void
    {
        $this->assertSame(
            "=== A ===\nfoo\n\n== A ==\nnew\n",
            $this->mangle("=== A ===\nfoo", "== A ==", "new")
        );
        $this->assertSame(
            "== A ==\nfoo\n\n=== A ===\nnew\n",
            $this->mangle("== A ==\nfoo", "=== A ===", "new")
        );
        $this->assertSame(
            "= A =\nfoo\n\n== A ==\nnew\n",
            $this->mangle("= A =\nfoo", "== A ==", "new")
        );
    }

    public function testL3HeaderReusedWhenLast(): void
    {
        $this->assertSame(
            "== A ==\n=== B ===\nfoo\n\nnew\n",
            $this->mangle("== A ==\n=== B ===\nfoo", "=== B ===", "new")
        );
    }

    public function testHeaderWhitespaceVariantsAreReused(): void
    {
        foreach (
            [
                "==March 2026==",
                "==  March 2026  ==",
                "== March  2026 ==",
                "== March 2026 ==   ",
                "== March 2026 ==\t",
            ] as $existing
        ) {
            $this->assertSame(
                "{$existing}\nold\n\nnew\n",
                $this->mangle("{$existing}\nold", "== March 2026 ==", "new"),
                "Header variant: " . json_encode($existing)
            );
        }
    }

    public function testSimilarHeadersAreNotReused(): void
    {
        foreach (
            [
                "== March 2025 ==",
                "== February 2026 ==",
                "== March 2026 warning ==",
                "== Re: March 2026 ==",
                "== march 2026 ==",
            ] as $existing
        ) {
            $this->assertSame(
                "{$existing}\nold\n\n== March 2026 ==\nnew\n",
                $this->mangle("{$existing}\nold", "== March 2026 ==", "new"),
                "Header variant: " . json_encode($existing)
            );
        }
    }

    public function testHeaderTextInsideLineIsNotAHeader(): void
    {
        $this->assertSame(
            "== A ==\nfoo\nsee == March 2026 == above\n\n== March 2026 ==\nnew\n",
            $this->mangle("== A ==\nfoo\nsee == March 2026 == above", "== March 2026 ==", "new")
        );
    }

    public function testContentAfterHeaderLineIsPreserved(): void
    {
        $content = "== March 2026 ==\n:reply\n::another reply <!-- comment -->\n{{tmbox|text=x}}";
        $this->assertSame(
            "{$content}\n\nnew\n",
            $this->mangle($content, "== March 2026 ==", "new")
        );
    }

    public function testSectionTextWithMultipleLines(): void
    {
        $this->assertSame(
            "== A ==\nexisting\n\nline one\nline two\nline three\n",
            $this->mangle("== A ==\nexisting", "== A ==", "line one\nline two\nline three")
        );
    }

    public function testExistingContentIsNeverModified(): void
    {
        $contents = [
            "== A ==\nfoo",
            "== A ==\nfoo\n\n== B ==\nbar",
            "{{Welcome}}\n\n== B ==\n=== C ===\n==== D ====\nbar",
            "== March 2026 ==\nfoo\n\n== March 2026 ==\nbar",
        ];
        foreach ($contents as $content) {
            foreach ([null, "== A ==", "== B ==", "== March 2026 ==", "=== C ==="] as $header) {
                $this->assertStringStartsWith($content . "\n\n", $this->mangle($content, $header, "new"));
                $this->assertStringEndsWith("new\n", $this->mangle($content, $header, "new"));
            }
        }
    }

    /**
     * The real world case this change is for.
     */
    public function testExampleFromTalkPage(): void
    {
        $existing = <<<'EOT'
== March 2026 ==

[[File:Information.svg|25px|alt=Information icon]] Hello, I'm [[User:Danners430|Danners430]]. I noticed that you added or changed content in an article, [[:Surat Airport]], but you didn't provide a [[Wikipedia:Reliable sources|reliable source]]. On Wikipedia, it's important that article content be [[Wikipedia:Verifiability|verifiable]]. If you'd like to resubmit your change [[Wikipedia:Citing sources|with a citation]], your edit is archived in [[Special:History/Surat Airport|the page history]]. If you have any questions, you can leave me a message on [[User talk:Danners430|my talk page]]. Thank you. <!-- Template:uw-unsourced1 --> <span class="nowrap">[[User talk:Danners430|<span style="color: RebeccaPurple">Danners430</span>]] <sub>[[Special:Contributions/Danners430|tweaks made]]</sub></span> 05:43, 1 March 2026 (UTC)
EOT;
        $warning = <<<'EOT'
[[File:Information.svg|left|25px|alt=]] Hello, and welcome to Wikipedia. This is a message letting you know that one or more of your recent edits to [[:List of Shaun the Sheep episodes]] have been undone by an automated computer program called [[User:ClueBot NG|ClueBot NG]].
{{clear}}
* ClueBot NG makes very few mistakes, but it does happen. If you believe the change you made was constructive, please [[User:ClueBot NG#Information About False Positives|read about it]], [{{User:ClueBot NG/Warnings/FPReport|4488504}} report it here], remove this message from your talk page, and then make the edit again.
* If you need help, please see the [[Help:Introduction|Introduction to Wikipedia]], and if you can't find what you are looking for there, place {{Tld|Help me}} on [[Special:EditPage/User talk:~2026-18366-2|your talk page]] and someone will drop by to help.
* The following is the log entry regarding this message: [[List of Shaun the Sheep episodes]] was [https://en.wikipedia.org/w/index.php?title=List+of+Shaun+the+Sheep+episodes&diff=1341458401&oldid=1339740710 changed] by [[Special:Contributions/~2026-18366-2|~2026-18366-2]] [[User:~2026-18366-2|(u)]] [[User talk:~2026-18366-2|(t)]] ANN scored at 0.904254 on 2026-03-03T08:50:07+00:00 <!-- MySQL ID: 4488504 -->
Thank you. <!-- Template:uw-cluebotwarning1 --><!-- Template:uw-vandalism1 --> ~~~~
EOT;

        $this->assertSame(
            $existing . "\n\n" . $warning . "\n",
            $this->mangle($existing, "== March 2026 ==", $warning)
        );
        $this->assertSame(1, substr_count($this->mangle($existing, "== March 2026 ==", $warning), "== March 2026 =="));
    }
}
