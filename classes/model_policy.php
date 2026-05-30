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
 * Model selection and fallback policy for the Gemini client.
 *
 * Encapsulates which model is tried first and the ordered chain of models to
 * attempt when an earlier one fails. This keeps the model/fallback policy in a
 * single cohesive, testable place instead of being spread across hardcoded
 * constants and if-checks inside {@see gemini_client}.
 *
 * The policy is a small immutable value object: given a primary model and a
 * fallback model it exposes an ordered attempt chain `[primary, fallback]`
 * (de-duplicated, so a client already configured on the fallback model yields a
 * single-element chain and therefore performs no redundant retry).
 *
 * This is intentionally minimal and Gemini-specific. It is the natural seam to
 * extend later (e.g. a longer fallback chain, or a per-provider policy) without
 * touching the request/parse logic in {@see gemini_client}.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_policy {

    /** @var string The primary model to try first. */
    private $primary;

    /** @var string The fallback model to try if the primary fails. */
    private $fallback;

    /**
     * Constructor.
     *
     * @param string $primary The primary (configured) model to try first.
     * @param string $fallback The fallback model to try if the primary fails.
     */
    public function __construct(string $primary, string $fallback) {
        $this->primary = $primary;
        $this->fallback = $fallback;
    }

    /**
     * Get the primary model that should be tried first.
     *
     * @return string
     */
    public function get_primary(): string {
        return $this->primary;
    }

    /**
     * Get the fallback model.
     *
     * @return string
     */
    public function get_fallback(): string {
        return $this->fallback;
    }

    /**
     * Get the ordered chain of models to attempt.
     *
     * The first element is always the primary model; the fallback model is
     * appended only when it differs from the primary, so a client already
     * configured on the fallback yields a single-element chain (and thus no
     * redundant retry). This preserves the existing behaviour where a primary
     * that equals the fallback is never retried.
     *
     * @return string[] Ordered, de-duplicated list of model names to try.
     */
    public function get_attempt_chain(): array {
        if ($this->primary === $this->fallback) {
            return [$this->primary];
        }
        return [$this->primary, $this->fallback];
    }

    /**
     * Whether a further model remains to try after the given one.
     *
     * @param string $model The model that was just attempted.
     * @return bool True if there is a subsequent model in the attempt chain.
     */
    public function has_fallback_after(string $model): bool {
        $chain = $this->get_attempt_chain();
        $index = array_search($model, $chain, true);
        return $index !== false && isset($chain[$index + 1]);
    }
}
