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
 * Plugin administration pages are defined here.
 *
 * @package     mod_googlemeet
 * @category    admin
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $options = [''];
    $issuers = \core\oauth2\api::get_all_issuers();

    foreach ($issuers as $issuer) {
        $options[$issuer->get('id')] = s($issuer->get('name'));
    }

    $settings->add(new admin_setting_configselect(
        'googlemeet/issuerid',
        get_string('issuerid', 'googlemeet'),
        get_string('issuerid_desc', 'googlemeet'),
        0,
        $options
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/multieventdateexpanded',
        get_string('multieventdateexpanded', 'googlemeet'),
        get_string('multieventdateexpanded_desc', 'googlemeet'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/roomurlexpanded',
        get_string('roomurlexpanded', 'googlemeet'),
        get_string('roomurlexpanded_desc', 'googlemeet'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/notificationexpanded',
        get_string('notificationexpanded', 'googlemeet'),
        get_string('notifycationexpanded_desc', 'googlemeet'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/notify',
        get_string('notify', 'googlemeet'),
        get_string('notify_help', 'googlemeet'),
        1
    ));

    $minutes = array();
    for ($i = 0; $i <= 120; $i = $i + 5) {
        $minutes[$i] = $i;
    }

    $settings->add(new admin_setting_configselect(
        'googlemeet/minutesbefore',
        get_string('minutesbefore', 'googlemeet'),
        get_string('minutesbefore_help', 'googlemeet'),
        10,
        $minutes
    ));

    $settings->add(new admin_setting_confightmleditor(
        'googlemeet/emailcontent',
        get_string('emailcontent', 'googlemeet'),
        get_string('emailcontent_help', 'googlemeet'),
        get_string('emailcontent_default', 'googlemeet'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        'googlemeet/autosynchours_default',
        get_string('autosynchours_default', 'googlemeet'),
        get_string('autosynchours_default_desc', 'googlemeet'),
        4,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'googlemeet/maxsyncattempts',
        get_string('maxsyncattempts', 'googlemeet'),
        get_string('maxsyncattempts_desc', 'googlemeet'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'googlemeet/syncretryinterval',
        get_string('syncretryinterval', 'googlemeet'),
        get_string('syncretryinterval_desc', 'googlemeet'),
        10800,
        PARAM_INT
    ));

    // AI Features section.
    $settings->add(new admin_setting_heading(
        'googlemeet/aiheading',
        get_string('ai_settings', 'googlemeet'),
        get_string('ai_settings_desc', 'googlemeet')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/enableai',
        get_string('enableai', 'googlemeet'),
        get_string('enableai_desc', 'googlemeet'),
        0
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'googlemeet/geminiapikey',
        get_string('geminiapikey', 'googlemeet'),
        get_string('geminiapikey_desc', 'googlemeet'),
        ''
    ));

    // The list must stay in sync with gemini_client::DEFAULT_MODEL / FALLBACK_MODEL,
    // otherwise the configured model never matches what the code actually requests.
    $aimodels = [
        'gemini-3.5-flash' => 'Gemini 3.5 Flash (Stable, default)',
        'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash Lite (Stable, cheap/fast, fallback)',
        'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro (Preview, most capable)',
        'gemini-3-flash-preview' => 'Gemini 3 Flash (Preview)',
        'gemini-2.5-flash' => 'Gemini 2.5 Flash (Fast)',
        'gemini-2.5-pro' => 'Gemini 2.5 Pro (More capable)',
    ];

    $settings->add(new admin_setting_configselect(
        'googlemeet/aimodel',
        get_string('aimodel', 'googlemeet'),
        get_string('aimodel_desc', 'googlemeet'),
        'gemini-3.5-flash',
        $aimodels
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/ai_autogenerate',
        get_string('ai_autogenerate', 'googlemeet'),
        get_string('ai_autogenerate_desc', 'googlemeet'),
        0
    ));

    // When enabled, synced recordings are granted "anyone with the link" read access on Google
    // Drive so enrolled students (who are not the Drive owner) can play the embedded recording.
    // Default 1 preserves prior behaviour; disabling improves privacy but breaks playback for
    // everyone except the Google account that owns the recordings.
    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/makerecordingspublic',
        get_string('makerecordingspublic', 'googlemeet'),
        get_string('makerecordingspublic_desc', 'googlemeet'),
        1
    ));

    // Language code used when extracting Google Drive auto-generated subtitles
    // (e.g. es, en, pt-BR). PARAM_ALPHANUMEXT allows the regional-variant hyphen.
    $settings->add(new admin_setting_configtext(
        'googlemeet/subtitlelanguage',
        get_string('subtitlelanguage', 'googlemeet'),
        get_string('subtitlelanguage_desc', 'googlemeet'),
        'es',
        PARAM_ALPHANUMEXT
    ));
}
