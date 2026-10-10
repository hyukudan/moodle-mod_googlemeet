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

namespace mod_googlemeet\local;

/**
 * Run code as another Moodle user (the room organiser) without leaking OAuth tokens between users.
 *
 * core\oauth2\client keeps the access token in $SESSION->{'oauth2-state-<issuerid>'}, a key that does
 * not include the user id, and \core\session\manager::set_user() does not touch $SESSION. In cron all
 * tasks of a run share one $SESSION, so after impersonating organiser A the next task impersonating B
 * would call Google with A's token (empty Meet/Drive listings, recordings wrongly trashed). begin()
 * stashes and clears those keys before switching user; end() clears what the impersonated user left
 * and puts the original ones back.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class impersonation {

    /** @var string Prefix of the session keys where core stores OAuth 2 access tokens. */
    private const OAUTH_PREFIX = 'oauth2-state-';

    /**
     * Switch to $user with a clean OAuth session state.
     *
     * @param \stdClass $user User to impersonate.
     * @return array Opaque state for end().
     */
    public static function begin(\stdClass $user): array {
        $state = [
            'user' => $GLOBALS['USER'] ?? null,
            'oauth' => self::take_oauth_state(),
        ];
        \core\session\manager::set_user($user);
        return $state;
    }

    /**
     * Restore the user and the OAuth session state saved by begin().
     *
     * @param array $state Value returned by begin().
     * @return void
     */
    public static function end(array $state): void {
        global $SESSION;
        self::take_oauth_state();
        if (isset($SESSION)) {
            foreach ($state['oauth'] ?? [] as $key => $value) {
                $SESSION->$key = $value;
            }
        }
        if (!empty($state['user'])) {
            \core\session\manager::set_user($state['user']);
        }
    }

    /**
     * Remove every OAuth access token from the session and return them.
     *
     * @return array key => value
     */
    private static function take_oauth_state(): array {
        global $SESSION;
        $taken = [];
        if (!isset($SESSION) || !is_object($SESSION)) {
            return $taken;
        }
        foreach (array_keys(get_object_vars($SESSION)) as $key) {
            if (strpos((string)$key, self::OAUTH_PREFIX) === 0) {
                $taken[$key] = $SESSION->$key;
                unset($SESSION->$key);
            }
        }
        return $taken;
    }
}
