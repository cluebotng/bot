<?php

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

require_once 'action_functions.php';

use PHPUnit\Framework\TestCase;
use CluebotNG\Action;

final class SectionManglerTest extends TestCase
{
    private function mangle(mixed $content, mixed $header, string $text): string
    {
        return (new ReflectionMethod(Action::class, 'mangleSectionIntoPage'))
            ->invokeArgs(null, [$content, $header, $text]);
    }

    public function testNoExistingPageData(): void
    {
        $this->assertEquals(
            "== Example 10 ==\nVery important text\n",
            $this->mangle(null, "== Example 10 ==", "Very important text")
        );
    }

    public function testNoExistingPageDataNoHeader(): void
    {
        $this->assertEquals(
            "Very important text\n",
            $this->mangle(null, null, "Very important text")
        );
    }

    public function testNullHeaderAppendsToContent(): void
    {
        $this->assertEquals(
            "== something ==\nvery interesting\n\nVery important text\n",
            $this->mangle("== something ==\nvery interesting", null, "Very important text")
        );
    }

    public function testNullHeaderAppendsToMultiSectionPage(): void
    {
        $this->assertEquals(
            "== A ==\nfoo\n\n== B ==\nbar\n\nVery important text\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar", null, "Very important text")
        );
    }

    public function testNoExistingSectionHeader(): void
    {
        $this->assertEquals(
            "== something ==\nvery interesting\n\n== Example 10 ==\nVery important text\n",
            $this->mangle("== something ==\nvery interesting", "== Example 10 ==", "Very important text")
        );
    }

    public function testNewSectionAppendedAfterMultipleSections(): void
    {
        $this->assertEquals(
            "== A ==\nfoo\n\n== B ==\nbar\n\n== C ==\nnew text\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar", "== C ==", "new text")
        );
    }

    public function testNewL3SectionAppendedWhenMissing(): void
    {
        $this->assertEquals(
            "== A ==\n=== B ===\nfoo\n\n=== C ===\nnew text\n",
            $this->mangle("== A ==\n=== B ===\nfoo", "=== C ===", "new text")
        );
    }

    public function testExistingSectionHeader(): void
    {
        $this->assertEquals(
            "== Example 10 ==\nvery interesting\n\nVery important text\n",
            $this->mangle("== Example 10 ==\nvery interesting", "== Example 10 ==", "Very important text")
        );
    }

    public function testExistingSectionWithSubHeaders(): void
    {
        $this->assertEquals(
            "== Example 10 ==\nvery interesting\n=== dolphins ===\nare fun\n\nVery important text\n",
            $this->mangle("== Example 10 ==\nvery interesting\n=== dolphins ===\nare fun", "== Example 10 ==", "Very important text")
        );
    }

    public function testExistingSectionBoundedByNextPeerSection(): void
    {
        $this->assertEquals(
            "== January 2026 ==\nexisting text\n\nnew text\n\n== February 2026 ==\nother stuff\n",
            $this->mangle("== January 2026 ==\nexisting text\n\n== February 2026 ==\nother stuff", "== January 2026 ==", "new text")
        );
    }

    public function testExistingSectionInMiddleOfPage(): void
    {
        $this->assertEquals(
            "== A ==\nfoo\n\n== B ==\nbar\n\nnew text\n\n== C ==\nbaz\n",
            $this->mangle("== A ==\nfoo\n\n== B ==\nbar\n\n== C ==\nbaz", "== B ==", "new text")
        );
    }

    public function testExistingSectionWithDeepSubHeaders(): void
    {
        $content = "== A ==\n=== B ===\n==== C ====\ndeep content\n=== D ===\nless deep";
        $this->assertEquals(
            "== A ==\n=== B ===\n==== C ====\ndeep content\n=== D ===\nless deep\n\nnew text\n",
            $this->mangle($content, "== A ==", "new text")
        );
    }

    public function testExistingL3SectionBoundedByPeerL3(): void
    {
        $content = "== A ==\n=== January ===\nold\n=== February ===\nother";
        $this->assertEquals(
            "== A ==\n=== January ===\nold\n\nnew text\n\n=== February ===\nother\n",
            $this->mangle($content, "=== January ===", "new text")
        );
    }

    public function testExistingL3SectionBoundedByHigherL2(): void
    {
        $content = "=== January ===\nold content\n\n== New Top ==\nfoo";
        $this->assertEquals(
            "=== January ===\nold content\n\nnew text\n\n== New Top ==\nfoo\n",
            $this->mangle($content, "=== January ===", "new text")
        );
    }

    public function testSectionTextWithMultipleLines(): void
    {
        $this->assertEquals(
            "== A ==\nexisting\n\nline one\nline two\nline three\n",
            $this->mangle("== A ==\nexisting", "== A ==", "line one\nline two\nline three")
        );
    }

    public function testEmptySectionText(): void
    {
        $this->assertEquals(
            "== A ==\nexisting\n\n\n",
            $this->mangle("== A ==\nexisting", "== A ==", "")
        );
    }

    public function testPageWithOnlyTargetSection(): void
    {
        $this->assertEquals(
            "== Only ==\nexisting\n\nnew\n",
            $this->mangle("== Only ==\nexisting", "== Only ==", "new")
        );
    }
}
