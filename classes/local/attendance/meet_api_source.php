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
 * Attendance read from the Google Meet REST API v2 (ANA-03).
 *
 * Findings that shape this class (Google docs, checked 2026-10):
 * - conferenceRecords.list only returns conferences where the authenticated user is the organizer,
 *   so it must run with the token of the Google account that owns the room (creatoremail).
 * - Participants expose a display name and, for signed-in users, an opaque id "users/{id}"; the
 *   API never returns e-mail addresses, so matching relies on remembered ids and names.
 * - Scope: meetings.space.readonly (sensitive: requires the OAuth app to be verified by Google
 *   for external users).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meet_api_source implements source {

    /** @var int Safety cap on paginated calls. */
    protected const MAX_PAGES = 20;

    /** @var meet_rest */
    protected $rest;

    /**
     * Constructor.
     *
     * @param meet_rest $rest REST client authenticated as the room organiser.
     */
    public function __construct(meet_rest $rest) {
        $this->rest = $rest;
    }

    /**
     * Parse an RFC 3339 timestamp (with or without fractional seconds).
     *
     * @param string|null $value Timestamp.
     * @return int Unix time, 0 when empty/invalid.
     */
    public static function parse_time(?string $value): int {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $value = preg_replace('/\.\d+(?=(Z|[+-]\d{2}:?\d{2})$)/i', '', $value);
        $time = strtotime($value);
        return $time === false ? 0 : (int)$time;
    }

    /**
     * Format a Unix time for Meet API filters.
     *
     * @param int $time Unix time.
     * @return string
     */
    protected static function format_time(int $time): string {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * Call the API translating authorisation errors.
     *
     * @param string $function Function name.
     * @param array $args Arguments.
     * @return \stdClass
     */
    protected function call(string $function, array $args): \stdClass {
        try {
            $response = $this->rest->call($function, $args);
        } catch (\core\oauth2\rest_exception $e) {
            $message = $e->getMessage();
            if (self::is_scope_error($message)) {
                throw new scope_exception($message);
            }
            throw $e;
        }
        return is_object($response) ? $response : new \stdClass();
    }

    /**
     * Whether a Google error means the token lacks the scope (as opposed to the API being disabled).
     *
     * @param string $message Error message ("403: ...").
     * @return bool
     */
    public static function is_scope_error(string $message): bool {
        if (!preg_match('/^\s*(401|403)\b/', $message)) {
            return false;
        }
        if (preg_match('/has not been used|is disabled|SERVICE_DISABLED/i', $message)) {
            return false;
        }
        return (bool)preg_match('/scope|insufficient|PERMISSION_DENIED|unauthenticated|invalid credentials/i', $message);
    }

    /**
     * Collect every page of a list call.
     *
     * @param string $function Function name.
     * @param array $args Arguments.
     * @param string $key Collection key in the response.
     * @return array
     */
    protected function list_all(string $function, array $args, string $key): array {
        $items = [];
        $pagetoken = '';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $callargs = $args + ['pageSize' => 100];
            if ($pagetoken !== '') {
                $callargs['pageToken'] = $pagetoken;
            }
            $response = $this->call($function, $callargs);
            foreach ((array)($response->{$key} ?? []) as $item) {
                $items[] = $item;
            }
            $pagetoken = (string)($response->nextPageToken ?? '');
            if ($pagetoken === '') {
                break;
            }
        }
        return $items;
    }

    /**
     * {@inheritDoc}
     */
    public function list_conferences(string $meetingcode, int $from, int $to): array {
        $filter = 'space.meeting_code = "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $meetingcode) . '"'
            . ' AND start_time >= "' . self::format_time($from) . '"'
            . ' AND start_time <= "' . self::format_time($to) . '"';
        $conferences = [];
        foreach ($this->list_all('conferencerecords', ['filter' => $filter], 'conferenceRecords') as $record) {
            if (empty($record->name)) {
                continue;
            }
            $conferences[] = [
                'name' => (string)$record->name,
                'start' => self::parse_time($record->startTime ?? ''),
                'end' => self::parse_time($record->endTime ?? ''),
            ];
        }
        return $conferences;
    }

    /**
     * {@inheritDoc}
     */
    public function list_participants(string $conference): array {
        $participants = [];
        foreach ($this->list_all('participants', ['parent' => $conference], 'participants') as $item) {
            $type = 'signedin';
            $user = $item->signedinUser ?? null;
            if (!$user && !empty($item->anonymousUser)) {
                $type = 'anonymous';
                $user = $item->anonymousUser;
            } else if (!$user && !empty($item->phoneUser)) {
                $type = 'phone';
                $user = $item->phoneUser;
            }

            $sessions = [];
            if (!empty($item->name)) {
                try {
                    foreach ($this->list_all('participantsessions', ['parent' => $item->name], 'participantSessions')
                            as $session) {
                        $sessions[] = [self::parse_time($session->startTime ?? ''), self::parse_time($session->endTime ?? '')];
                    }
                } catch (scope_exception $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $sessions = [];
                }
            }
            if (!$sessions) {
                // Fallback: one span from the first join to the last leave.
                $sessions[] = [self::parse_time($item->earliestStartTime ?? ''), self::parse_time($item->latestEndTime ?? '')];
            }

            $participants[] = [
                'type' => $type,
                'displayname' => trim((string)($user->displayName ?? '')),
                'googleuserid' => $type === 'signedin' ? trim((string)($user->user ?? '')) : '',
                'email' => '',
                'sessions' => $sessions,
            ];
        }
        return $participants;
    }
}
