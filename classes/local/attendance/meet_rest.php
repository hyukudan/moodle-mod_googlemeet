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
 * Google Meet REST API v2 endpoints used for attendance (ANA-03).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meet_rest extends \core\oauth2\rest {

    /**
     * Define the functions of the rest API.
     *
     * @return array
     */
    public function get_api_functions() {
        return [
            'conferencerecords' => [
                'endpoint' => 'https://meet.googleapis.com/v2/conferenceRecords',
                'method' => 'get',
                'args' => [
                    'filter' => PARAM_RAW,
                    'pageSize' => PARAM_INT,
                    'pageToken' => PARAM_RAW,
                ],
                'response' => 'json',
            ],
            'participants' => [
                'endpoint' => 'https://meet.googleapis.com/v2/{parent}/participants',
                'method' => 'get',
                'args' => [
                    'parent' => PARAM_RAW,
                    'pageSize' => PARAM_INT,
                    'pageToken' => PARAM_RAW,
                ],
                'response' => 'json',
            ],
            'participantsessions' => [
                'endpoint' => 'https://meet.googleapis.com/v2/{parent}/participantSessions',
                'method' => 'get',
                'args' => [
                    'parent' => PARAM_RAW,
                    'pageSize' => PARAM_INT,
                    'pageToken' => PARAM_RAW,
                ],
                'response' => 'json',
            ],
        ];
    }
}
