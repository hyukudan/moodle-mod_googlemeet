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
 * The googlemeet/aicontext setting injected into Gemini prompts.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(gemini_client::class)]
final class ai_context_test extends \advanced_testcase {

    /**
     * Build the video analysis prompt through reflection.
     *
     * @return string
     */
    private function analysis_prompt(): string {
        $client = new gemini_client();
        $method = new \ReflectionMethod($client, 'build_analysis_prompt');
        return $method->invoke($client, 'Unit 3', '1:00:00', 'https://example.com/video');
    }

    /**
     * The default context is used while the setting has never been saved.
     */
    public function test_default_when_unset(): void {
        $this->resetAfterTest();
        unset_config('aicontext', 'googlemeet');
        $this->assertSame(gemini_client::DEFAULT_AI_CONTEXT, gemini_client::get_ai_context());
        $this->assertStringContainsString('Teaching context: ' . gemini_client::DEFAULT_AI_CONTEXT, $this->analysis_prompt());
    }

    /**
     * A custom context is whitespace-normalised and added to the prompt.
     */
    public function test_custom_context_is_normalised_and_injected(): void {
        $this->resetAfterTest();
        set_config('aicontext', "  Nursing   licensing\n exam preparation ", 'googlemeet');
        $this->assertSame('Nursing licensing exam preparation', gemini_client::get_ai_context());
        $this->assertStringContainsString('Teaching context: Nursing licensing exam preparation', $this->analysis_prompt());
    }

    /**
     * An empty setting adds no context line.
     */
    public function test_empty_context_sends_nothing(): void {
        $this->resetAfterTest();
        set_config('aicontext', '', 'googlemeet');
        $this->assertSame('', gemini_client::get_ai_context());
        $this->assertStringNotContainsString('Teaching context:', $this->analysis_prompt());
    }

    /**
     * Very long contexts are truncated.
     */
    public function test_context_is_capped(): void {
        $this->resetAfterTest();
        set_config('aicontext', str_repeat('a', 2000), 'googlemeet');
        $this->assertSame(gemini_client::AI_CONTEXT_MAXLENGTH, \core_text::strlen(gemini_client::get_ai_context()));
    }
}
