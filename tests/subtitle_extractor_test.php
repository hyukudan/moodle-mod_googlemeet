<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_googlemeet;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for mod_googlemeet\subtitle_extractor::parse_xml().
 *
 * parse_xml() is a public method that has no external dependencies (no DB, no
 * network, no shell commands). It receives a raw XML string and returns a
 * formatted transcript string, making it fully unit-testable.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\subtitle_extractor::class)]
class subtitle_extractor_test extends \advanced_testcase {

    /**
     * Build an instance of subtitle_extractor without triggering the yt-dlp
     * file-system check (we only need parse_xml() which is pure PHP).
     *
     * We use reflection to set the private $ytdlppath so the constructor does
     * not call find_ytdlp() with a real filesystem lookup — but since we only
     * exercise parse_xml(), which has no state dependency, direct instantiation
     * also works correctly regardless of yt-dlp availability.
     *
     * @return \mod_googlemeet\subtitle_extractor
     */
    private function make_extractor(): \mod_googlemeet\subtitle_extractor {
        return new \mod_googlemeet\subtitle_extractor('es');
    }

    // =========================================================================
    // Basic well-formed XML
    // =========================================================================

    /**
     * Single text node with a sub-minute timestamp.
     *
     */
    public function test_parse_xml_single_node(): void {
        $xml = '<transcript><text start="5.0" dur="2">Hello world</text></transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('0:05', $result);
    }

    /**
     * Multiple nodes in the same minute share one timestamp marker.
     *
     */
    public function test_parse_xml_multiple_nodes_same_minute(): void {
        $xml = '<transcript>'
            . '<text start="10.0" dur="2">First line</text>'
            . '<text start="30.0" dur="2">Second line</text>'
            . '<text start="55.0" dur="2">Third line</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        // All three in the 0:xx minute → only one timestamp marker "0:10".
        $this->assertEquals(1, substr_count($result, '0:'), 'Only one timestamp marker expected for the first minute.');
        $this->assertStringContainsString('First line', $result);
        $this->assertStringContainsString('Second line', $result);
        $this->assertStringContainsString('Third line', $result);
    }

    /**
     * Nodes that cross a minute boundary produce two timestamp markers.
     *
     */
    public function test_parse_xml_minute_boundary_adds_marker(): void {
        $xml = '<transcript>'
            . '<text start="55.0" dur="2">Before minute</text>'
            . '<text start="65.0" dur="2">After minute</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);
        $lines = explode("\n", trim($result));

        // Line 0: timestamp for minute 0 (0:55).
        $this->assertMatchesRegularExpression('/^\d+:\d{2}$/', $lines[0]);
        $this->assertStringContainsString('Before minute', $result);

        // A second timestamp must appear for the 1:xx minute.
        $this->assertMatchesRegularExpression('/1:\d{2}/', $result);
        $this->assertStringContainsString('After minute', $result);
    }

    /**
     * Timestamps beyond one hour use H:MM:SS format.
     *
     */
    public function test_parse_xml_hour_format(): void {
        $xml = '<transcript>'
            . '<text start="3700.0" dur="3">Long video text</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        // 3700 seconds = 1 hour 1 min 40 sec → "1:01:40".
        $this->assertStringContainsString('1:01:40', $result);
        $this->assertStringContainsString('Long video text', $result);
    }

    // =========================================================================
    // HTML entities
    // =========================================================================

    /**
     * Common HTML entities are decoded in the output.
     *
     */
    public function test_parse_xml_html_entities_decoded(): void {
        $xml = '<transcript>'
            . '<text start="5.0" dur="2">Tom &amp; Jerry</text>'
            . '<text start="10.0" dur="2">He said &quot;hello&quot;</text>'
            . '<text start="15.0" dur="2">Caf&#233;</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertStringContainsString('Tom & Jerry', $result);
        $this->assertStringContainsString('He said "hello"', $result);
        $this->assertStringContainsString('Café', $result);
    }

    /**
     * Named HTML5 entities (&apos;, &nbsp;) are decoded.
     *
     */
    public function test_parse_xml_html5_entities(): void {
        $xml = '<transcript>'
            . '<text start="5.0" dur="2">It&apos;s fine</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertStringContainsString("It's fine", $result);
    }

    // =========================================================================
    // Edge cases: empty and whitespace-only nodes
    // =========================================================================

    /**
     * Nodes with empty text content are silently skipped.
     *
     */
    public function test_parse_xml_empty_text_nodes_skipped(): void {
        $xml = '<transcript>'
            . '<text start="5.0" dur="2"></text>'
            . '<text start="10.0" dur="2">   </text>'
            . '<text start="15.0" dur="2">Real text</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        // Only the non-empty node contributes text.
        $this->assertStringContainsString('Real text', $result);

        // Whitespace-only nodes must not appear as blank lines between content.
        // The output should contain "Real text" without preceding empty lines
        // other than the timestamp.
        $lines = array_filter(explode("\n", $result), fn($l) => trim($l) !== '');
        $this->assertCount(2, array_values($lines)); // timestamp + "Real text".
    }

    /**
     * Empty transcript element returns an empty string.
     *
     */
    public function test_parse_xml_empty_transcript(): void {
        $xml = '<transcript></transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertSame('', $result);
    }

    // =========================================================================
    // Malformed / invalid XML
    // =========================================================================

    /**
     * Totally invalid XML returns an empty string (libxml error suppressed).
     *
     */
    public function test_parse_xml_invalid_xml_returns_empty(): void {
        $extractor = $this->make_extractor();

        $this->assertSame('', $extractor->parse_xml('THIS IS NOT XML'));
        $this->assertSame('', $extractor->parse_xml(''));
        $this->assertSame('', $extractor->parse_xml('<unclosed'));
        $this->assertSame('', $extractor->parse_xml('<transcript><text start="5">no close'));
    }

    /**
     * XML with a valid root but incorrect element structure returns empty string.
     *
     */
    public function test_parse_xml_wrong_root_element(): void {
        // Valid XML but root is not <transcript> — simplexml_load_string returns
        // a doc object but the foreach over ->text produces nothing.
        $xml = '<subtitles><line start="5.0">Hello</line></subtitles>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        // No <text> children → empty output (no timestamp, no text).
        $this->assertSame('', $result);
    }

    // =========================================================================
    // Output format / ordering
    // =========================================================================

    /**
     * Lines are in chronological order and timestamps precede their content.
     *
     */
    public function test_parse_xml_chronological_order(): void {
        $xml = '<transcript>'
            . '<text start="65.0" dur="2">Second minute text</text>'
            . '<text start="125.0" dur="2">Third minute text</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);
        $lines = explode("\n", trim($result));

        // Line 0 must be timestamp "1:05", line 1 the text, line 2 timestamp "2:05", line 3 text.
        $this->assertMatchesRegularExpression('/^\d+:\d{2}/', $lines[0]);
        $this->assertSame('Second minute text', $lines[1]);
        $this->assertMatchesRegularExpression('/^\d+:\d{2}/', $lines[2]);
        $this->assertSame('Third minute text', $lines[3]);
    }

    /**
     * Output lines are joined with "\n" (no trailing newline from implode).
     *
     */
    public function test_parse_xml_newline_separator(): void {
        $xml = '<transcript>'
            . '<text start="5.0" dur="2">Line one</text>'
            . '<text start="6.0" dur="2">Line two</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        // implode("\n", $lines) does not add a trailing newline.
        $this->assertStringNotContainsString("\n\n", $result);
        $this->assertFalse(str_ends_with($result, "\n"), 'Output should not have a trailing newline.');
    }

    /**
     * CDATA content in text nodes is handled correctly.
     *
     */
    public function test_parse_xml_cdata_content(): void {
        $xml = '<transcript>'
            . '<text start="5.0" dur="2"><![CDATA[Text with <special> chars & stuff]]></text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertStringContainsString('Text with <special> chars & stuff', $result);
    }

    /**
     * Unicode / multibyte characters are preserved correctly.
     *
     */
    public function test_parse_xml_unicode_text(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<transcript>'
            . '<text start="5.0" dur="2">日本語テスト</text>'
            . '<text start="65.0" dur="2">Ärger über Straße</text>'
            . '</transcript>';
        $extractor = $this->make_extractor();
        $result = $extractor->parse_xml($xml);

        $this->assertStringContainsString('日本語テスト', $result);
        $this->assertStringContainsString('Ärger über Straße', $result);
    }
}
