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

/**
 * Behat step definitions for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here. This file is also included from behat_init.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps for mod_googlemeet.
 */
class behat_mod_googlemeet extends behat_base {

    /**
     * Visit a plugin script of an activity with an explicit recording id (e.g. a stale link).
     *
     * @Given /^I visit the "(?P<script_string>[^"]*)" script of googlemeet "(?P<name_string>[^"]*)" with recording id "(?P<recording_string>\d+)"$/
     * @param string $script view.php or material.php
     * @param string $name Activity name.
     * @param string $recordingid Recording id to request.
     */
    public function i_visit_script_of_googlemeet_with_recording(string $script, string $name, string $recordingid): void {
        global $DB;
        if (!in_array($script, ['view.php', 'material.php'], true)) {
            throw new Exception('Unsupported script ' . $script);
        }
        $instance = $DB->get_record('googlemeet', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $instance->id, $instance->course, false, MUST_EXIST);
        $this->getSession()->visit($this->locate_path('/mod/googlemeet/' . $script . '?id=' . $cm->id .
            '&recording=' . $recordingid));
    }

    /**
     * Configure an offline Google OAuth 2 issuer (no network) so the recordings list is rendered.
     *
     * Without an enabled issuer the activity page only shows the live-class block.
     *
     * @Given /^an offline Google issuer is configured for googlemeet$/
     */
    public function an_offline_google_issuer_is_configured(): void {
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Google (behat)', 'image' => '', 'baseurl' => 'https://accounts.google.com',
            'clientid' => 'behat-client', 'clientsecret' => 'behat-secret', 'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email', 'loginparams' => '',
            'loginparamsoffline' => 'access_type=offline&prompt=consent', 'alloweddomains' => '', 'enabled' => 1,
            'showonloginpage' => 0, 'basicauth' => 0, 'servicetype' => 'google',
        ]);
        $issuer->create();
        foreach ([
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ] as $name => $url) {
            (new \core\oauth2\endpoint(0, (object)['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        }
        set_config('issuerid', $issuer->get('id'), 'googlemeet');
    }
}
