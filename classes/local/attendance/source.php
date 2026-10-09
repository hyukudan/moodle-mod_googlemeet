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

namespace mod_googlemeet\local\attendance;

/**
 * Where attendance comes from (the Google Meet REST API, or a test double).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface source {

    /**
     * Conferences held in the room with this meeting code that started in [$from, $to].
     *
     * @param string $meetingcode Meeting code (abc-defg-hij).
     * @param int $from Earliest start time.
     * @param int $to Latest start time.
     * @return array[] Each: ['name' => 'conferenceRecords/...', 'start' => int, 'end' => int].
     * @throws scope_exception When the token lacks the Meet scope.
     */
    public function list_conferences(string $meetingcode, int $from, int $to): array;

    /**
     * Participants of one conference.
     *
     * @param string $conference Conference resource name (conferenceRecords/...).
     * @return array[] Each: ['type' => signedin|anonymous|phone, 'displayname' => string,
     *                 'googleuserid' => string ('' unless signed in), 'email' => string ('' when unknown),
     *                 'sessions' => [[start, end], ...]].
     * @throws scope_exception When the token lacks the Meet scope.
     */
    public function list_participants(string $conference): array;
}
