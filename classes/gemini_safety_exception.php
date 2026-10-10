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
 * IA-03: Gemini refused to answer because of its content-safety filters.
 *
 * Raised when the response has finishReason = SAFETY (or a blocked prompt). Its message is a clear,
 * non-technical explanation for the teacher; it is stored as the analysis error as is. It is not
 * transient: retrying the same content produces the same block.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_safety_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $categories Comma-separated blocked categories (may be empty), for the debug info.
     */
    public function __construct(string $categories = '') {
        parent::__construct('ai_error_safety', 'googlemeet', '', null, $categories !== '' ? 'Blocked: ' . $categories : null);
    }

    /**
     * Text to store and show to teachers (getMessage() also carries the debug info in developer mode).
     *
     * @return string
     */
    public function get_user_message(): string {
        return get_string('ai_error_safety', 'googlemeet');
    }
}
