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

/**
 * IA-03: Gemini safety settings and the SAFETY finish reason.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gemini_client::class)]
#[CoversClass(gemini_safety_exception::class)]
final class gemini_safety_test extends \advanced_testcase {

    /**
     * Call a private parser of the client with a simulated raw API response.
     *
     * @param string $method Parser method.
     * @param array|object $response Simulated decoded response.
     * @return mixed
     */
    private function parse(string $method, $response) {
        $client = new gemini_client();
        $reflection = new \ReflectionMethod($client, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($client, json_encode($response));
    }

    /**
     * A response blocked by the safety filters.
     *
     * @return array
     */
    private function safety_response(): array {
        return [
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'index' => 0,
                'safetyRatings' => [
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'probability' => 'HIGH', 'blocked' => true],
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'probability' => 'NEGLIGIBLE'],
                ],
            ]],
        ];
    }

    /**
     * Default threshold is BLOCK_ONLY_HIGH for every category; it is configurable; junk falls back.
     */
    public function test_build_safety_settings(): void {
        $this->resetAfterTest();

        $settings = gemini_client::build_safety_settings();
        $this->assertCount(4, $settings);
        foreach ($settings as $setting) {
            $this->assertSame('BLOCK_ONLY_HIGH', $setting['threshold']);
        }
        $this->assertEqualsCanonicalizing(gemini_client::SAFETY_CATEGORIES, array_column($settings, 'category'));

        set_config('aisafetythreshold', 'BLOCK_MEDIUM_AND_ABOVE', 'googlemeet');
        $this->assertSame(['BLOCK_MEDIUM_AND_ABOVE'], array_unique(array_column(gemini_client::build_safety_settings(),
            'threshold')));

        set_config('aisafetythreshold', 'BLOCK_EVERYTHING', 'googlemeet');
        $this->assertSame(['BLOCK_ONLY_HIGH'], array_unique(array_column(gemini_client::build_safety_settings(),
            'threshold')));
    }

    /**
     * finishReason = SAFETY raises the specific exception with the clear message.
     */
    public function test_safety_finish_reason_has_specific_message(): void {
        $this->resetAfterTest();
        try {
            $this->parse('parse_analysis_response', $this->safety_response());
            $this->fail('A SAFETY response must throw');
        } catch (gemini_safety_exception $e) {
            $this->assertSame('ai_error_safety', $e->errorcode);
            // getMessage() appends the debug info under PHPUnit / developer debugging.
            $this->assertStringStartsWith(get_string('ai_error_safety', 'googlemeet'), $e->getMessage());
            $this->assertSame(get_string('ai_error_safety', 'googlemeet'), $e->get_user_message());
            $this->assertStringContainsString('HARM_CATEGORY_DANGEROUS_CONTENT', (string)$e->debuginfo);
            $this->assertStringNotContainsString('HARM_CATEGORY_HARASSMENT', (string)$e->debuginfo);
            $this->assertNotSame(get_string('ai_error_generic', 'googlemeet'), $e->getMessage());
            // Not transient: no automatic retry loop on content that will be blocked again.
            $this->assertNotInstanceOf(gemini_transient_exception::class, $e);
        }
    }

    /**
     * Every parser recognises the block (questions, chapters, plain text).
     */
    public function test_all_parsers_detect_safety(): void {
        $this->resetAfterTest();
        foreach (['parse_questions_response', 'parse_chapters_response', 'extract_text_from_response'] as $method) {
            try {
                $this->parse($method, $this->safety_response());
                $this->fail("{$method} must throw on SAFETY");
            } catch (gemini_safety_exception $e) {
                // getMessage() appends the debug info under PHPUnit / developer debugging.
                $this->assertStringStartsWith(get_string('ai_error_safety', 'googlemeet'), $e->getMessage());
                $this->assertSame(get_string('ai_error_safety', 'googlemeet'), $e->get_user_message());
            }
        }
    }

    /**
     * A blocked prompt (promptFeedback.blockReason, no candidates) is reported the same way.
     */
    public function test_blocked_prompt(): void {
        $this->resetAfterTest();
        $this->expectException(gemini_safety_exception::class);
        $this->parse('parse_analysis_response', ['promptFeedback' => ['blockReason' => 'SAFETY']]);
    }

    /**
     * A normal response is not affected; other non-text failures keep their old error.
     */
    public function test_normal_and_other_responses(): void {
        $this->resetAfterTest();
        $ok = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => json_encode([
                    'summary' => 'Resumen', 'keypoints' => ['a'], 'topics' => ['b'], 'chapters' => [], 'language' => 'es',
                ])]]],
            ]],
        ];
        $result = $this->parse('parse_analysis_response', $ok);
        $this->assertSame('Resumen', $result->summary);

        gemini_client::assert_not_blocked(null);
        gemini_client::assert_not_blocked((object)['candidates' => [(object)['finishReason' => 'MAX_TOKENS']]]);

        try {
            $this->parse('parse_analysis_response', ['candidates' => [['finishReason' => 'MAX_TOKENS']]]);
            $this->fail('A response without text must still fail');
        } catch (\moodle_exception $e) {
            $this->assertNotInstanceOf(gemini_safety_exception::class, $e);
        }
    }

    /**
     * The background pipeline stores the clear message as the analysis error.
     */
    public function test_failure_stores_clear_message(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $recordingid = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => 1, 'recordingid' => 'x', 'name' => 'R', 'createdtime' => $now, 'duration' => '1:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view', 'visible' => 1, 'timemodified' => $now,
        ]);
        $id = $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recordingid, 'status' => 'processing', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        (new ai_service())->record_permanent_failure($id, (new gemini_safety_exception('X'))->get_user_message());
        $row = $DB->get_record('googlemeet_ai_analysis', ['id' => $id]);
        $this->assertSame('failed', $row->status);
        $this->assertSame(get_string('ai_error_safety', 'googlemeet'), $row->error);
    }
}
