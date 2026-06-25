<?php
namespace mod_googlemeet;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for notes HTML sanitization.
 *
 * @package   mod_googlemeet
 * @covers    \mod_googlemeet\client
 */
final class notes_sanitize_test extends \advanced_testcase {

    /**
     * Invoke the private sanitize_notes_html() via reflection.
     */
    private function sanitize(string $html): string {
        $client = new \ReflectionClass(client::class);
        $method = $client->getMethod('sanitize_notes_html');
        $method->setAccessible(true);
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
