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

/**
 * Excepción lanzada cuando Gemini API devuelve un error transitorio
 * (rate limit, sobrecarga, 429/500/503). El caller debe aplicar back-off
 * y reintentar más tarde.
 *
 * Hereda de \moodle_exception para que los bloques catch existentes que
 * atrapan moodle_exception o \Exception sigan funcionando sin cambios.
 *
 * @package     mod_googlemeet
 * @copyright   2024 Your Name
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gemini_transient_exception extends \moodle_exception {

    /**
     * Constructor.
     *
     * @param string $message Human-readable error message from the API.
     */
    public function __construct(string $message) {
        parent::__construct('ai_error', 'googlemeet', '', $message);
    }
}
