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

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for notes HTML sanitization.
 *
 * @package   mod_googlemeet
 * @category  test
 * @copyright 2026 PreparaOposiciones
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\client::class)]
final class notes_sanitize_test extends \advanced_testcase {

    /**
     * Invoke the private sanitize_notes_html() via reflection.
     */
    private function sanitize(string $html): string {
        $client = new \ReflectionClass(client::class);
        $method = $client->getMethod('sanitize_notes_html');
        // sanitize_notes_html is static-safe: no instance state used.
        $instance = $client->newInstanceWithoutConstructor();
        return $method->invoke($instance, $html);
    }

    public function test_strips_script_tags(): void {
        $this->resetAfterTest();
        $out = $this->sanitize('<html><body><p>Hola</p><script>alert(1)</script></body></html>');
        $this->assertStringContainsString('Hola', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_keeps_structure(): void {
        $this->resetAfterTest();
        $out = $this->sanitize('<html><body><h2>Temas</h2><ul><li>Punto A</li><li>Punto B</li></ul></body></html>');
        $this->assertStringContainsString('Punto A', $out);
        $this->assertStringContainsString('<li>', $out);
    }

    public function test_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->sanitize(''));
    }
}
