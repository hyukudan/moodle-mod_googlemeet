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

/**
 * Extracts auto-generated subtitles from Google Drive video recordings.
 *
 * Uses yt-dlp to discover the subtitle URL (timedtext endpoint with authpayload),
 * then downloads the subtitle XML with the required Referer header and parses it
 * into a timestamped transcript.
 *
 * This avoids downloading the full video file, which can be several GB.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subtitle_extractor {

    /** @var string Path to the yt-dlp binary */
    private string $ytdlppath;

    /** @var string Subtitle language to extract */
    private string $language;

    /**
     * Constructor.
     *
     * @param string $language The subtitle language code (default: 'es')
     */
    public function __construct(string $language = 'es') {
        $this->language = $language;
        $this->ytdlppath = $this->find_ytdlp();
    }

    /**
     * Check if subtitle extraction is available.
     *
     * @return bool True if yt-dlp is installed and executable
     */
    public function is_available(): bool {
        return !empty($this->ytdlppath);
    }

    /**
     * Extract subtitles from a Google Drive video URL.
     *
     * @param string $driveurl The Google Drive video URL (webviewlink)
     * @return string|null The parsed transcript with timestamps, or null on failure
     */
    public function extract(string $driveurl): ?string {
        if (!$this->is_available()) {
            debugging('subtitle_extractor: yt-dlp not available', DEBUG_DEVELOPER);
            return null;
        }

        if (empty($driveurl)) {
            return null;
        }

        // Step 1: Get the timedtext URL from yt-dlp.
        $timedtexturl = $this->get_timedtext_url($driveurl);
        if (!$timedtexturl) {
            debugging('subtitle_extractor: Could not extract timedtext URL', DEBUG_DEVELOPER);
            return null;
        }

        // Step 2: Download the subtitle XML.
        $baseurl = preg_replace('/&type=track.*$/', '', $timedtexturl);

        // Try ASR (auto-generated) track first.
        $suburl = $baseurl . '&type=track&lang=' . $this->language . '&kind=asr&fmt=1';
        $xml = $this->download_subtitle($suburl);

        if (empty($xml)) {
            // Fallback: try named track "1".
            $suburl = $baseurl . '&type=track&lang=' . $this->language . '&name=1&fmt=1';
            $xml = $this->download_subtitle($suburl);
        }

        if (empty($xml)) {
            debugging('subtitle_extractor: Could not download subtitle content', DEBUG_DEVELOPER);
            return null;
        }

        // Step 3: Parse XML into transcript text.
        $transcript = $this->parse_xml($xml);

        if (strlen($transcript) < 100) {
            debugging('subtitle_extractor: Transcript too short (' . strlen($transcript) . ' chars)', DEBUG_DEVELOPER);
            return null;
        }

        return $transcript;
    }

    /**
     * Find the yt-dlp binary.
     *
     * @return string|null Path to yt-dlp or null if not found
     */
    private function find_ytdlp(): ?string {
        // Check common locations.
        $paths = [
            '/tmp/yt-dlp',
            '/usr/local/bin/yt-dlp',
            '/usr/bin/yt-dlp',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Check PATH.
        $which = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
        if (!empty($which) && is_executable($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Use yt-dlp to extract the timedtext URL from a Google Drive video page.
     *
     * yt-dlp parses the Drive page HTML and extracts the timedtext URL
     * with the authpayload parameter needed for authentication.
     *
     * @param string $driveurl The Google Drive video URL
     * @return string|null The timedtext URL or null on failure
     */
    private function get_timedtext_url(string $driveurl): ?string {
        $cmd = escapeshellarg($this->ytdlppath)
             . ' -v --write-sub --sub-lang ' . escapeshellarg($this->language)
             . ' --skip-download --sub-format srv3'
             . ' -o /dev/null '
             . escapeshellarg($driveurl) . ' 2>&1';

        $output = shell_exec($cmd);

        if (preg_match('/Invoking http downloader on "(https:\/\/drive\.google\.com\/timedtext\?[^"]+)"/', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Download subtitle XML from the timedtext URL.
     *
     * The Referer header is required - without it, Google returns empty responses.
     *
     * @param string $url The timedtext URL with track parameters
     * @return string The subtitle XML content, or empty string on failure
     */
    private function download_subtitle(string $url): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader(['Referer: https://youtube.googleapis.com/']);

        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
        ];

        return $curl->get($url, [], $options) ?: '';
    }

    /**
     * Parse subtitle XML (fmt=1 format) into readable transcript with timestamps.
     *
     * Input format: <transcript><text start="148.92" dur="3">Hola</text>...</transcript>
     * Output format: "2:28\nHola\nbuen texto\n3:01\nmás texto..."
     *
     * Timestamps are inserted at each new minute boundary.
     *
     * @param string $xml The subtitle XML content
     * @return string The parsed transcript with timestamps
     */
    private function parse_xml(string $xml): string {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        if (!$doc) {
            return '';
        }

        $lines = [];
        $lastminute = -1;

        foreach ($doc->text as $node) {
            $start = (float)$node['start'];
            $text = trim((string)$node);

            if (empty($text)) {
                continue;
            }

            // Convert seconds to MM:SS or H:MM:SS.
            $hours = floor($start / 3600);
            $minutes = floor(($start % 3600) / 60);
            $seconds = floor($start % 60);
            $currentminute = floor($start / 60);

            // Add timestamp marker at each new minute.
            if ($currentminute > $lastminute) {
                if ($hours > 0) {
                    $timestamp = sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
                } else {
                    $timestamp = sprintf("%d:%02d", $minutes, $seconds);
                }
                $lines[] = $timestamp;
                $lastminute = $currentminute;
            }

            // Clean up HTML entities.
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }
}
