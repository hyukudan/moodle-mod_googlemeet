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

use mod_googlemeet\privacy\provider;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * Key points review checklist stored as a user preference.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class keypoints_checklist_test extends \advanced_testcase {

    /**
     * Hash changes with the key points and state of another list is ignored.
     */
    public function test_hash_and_state(): void {
        $hash = googlemeet_keypoints_hash(['A  point', 'B']);
        $this->assertSame($hash, googlemeet_keypoints_hash(['A point ', 'B']));
        $this->assertNotSame($hash, googlemeet_keypoints_hash(['A point', 'C']));
        $this->assertSame('101', googlemeet_keypoints_state($hash . ':101', $hash, 3));
        $this->assertSame('', googlemeet_keypoints_state($hash . ':101', $hash, 2));
        $this->assertSame('', googlemeet_keypoints_state('00000000:101', $hash, 3));
        $this->assertSame('', googlemeet_keypoints_state('garbage', $hash, 3));
    }

    /**
     * Callback: only the current user, and only a valid format.
     */
    public function test_preference_callback(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $prefs = googlemeet_user_preferences();
        $key = '/^mod_googlemeet_kp_\\d+$/';
        $this->assertArrayHasKey($key, $prefs);
        $def = $prefs[$key];
        $this->assertTrue(call_user_func($def['permissioncallback'], $user));
        $this->assertFalse(call_user_func($def['permissioncallback'], $other));

        $this->assertSame('1a2b3c4d:0110', ($def['cleancallback'])('1a2b3c4d:0110'));
        $this->assertSame('', ($def['cleancallback'])(''));
        foreach (['1a2b3c4d', 'xyz:01', '1a2b3c4d:012', '1a2b3c4d:' . str_repeat('1', 201)] as $bad) {
            try {
                ($def['cleancallback'])($bad);
                $this->fail("Accepted $bad");
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
        $this->assertSame('mod_googlemeet_kp_7', googlemeet_keypoints_preference_name(7));
    }

    /**
     * Privacy export lists the preference and deletion removes it for the user only.
     */
    public function test_privacy_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $other = $gen->create_user();
        $instance = $gen->create_module('googlemeet', ['course' => $course->id]);
        $recordingid = $DB->insert_record('googlemeet_recordings', (object)[
            'googlemeetid' => $instance->id, 'recordingid' => 'r1', 'name' => 'Rec',
            'createdtime' => time(), 'duration' => '0:10', 'webviewlink' => 'https://drive.google.com/x',
            'timemodified' => time(),
        ]);
        $name = provider::KEYPOINTS_PREFIX . $recordingid;
        set_user_preference($name, '1a2b3c4d:101', $user);
        set_user_preference($name, '1a2b3c4d:111', $other);

        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_googlemeet'));
        $names = array_map(static fn($t) => $t->get_name(), array_filter($collection->get_collection(),
            static fn($t) => $t instanceof \core_privacy\local\metadata\types\user_preference));
        $this->assertContains(provider::KEYPOINTS_PREFIX . '<recordingid>', $names);

        provider::export_user_preferences($user->id);
        $prefs = \core_privacy\local\request\writer::with_context(\context_system::instance())
            ->get_user_preferences('mod_googlemeet');
        $this->assertTrue(isset($prefs->$name));
        $this->assertSame('2/3', $prefs->$name->value);

        $cm = get_coursemodule_from_instance('googlemeet', $instance->id);
        $context = \context_module::instance($cm->id);
        $list = new \core_privacy\local\request\approved_contextlist($user, 'mod_googlemeet', [$context->id]);
        provider::delete_data_for_user($list);
        // Check the table: the $user objects keep their own preference cache within the same second.
        $this->assertFalse($DB->record_exists('user_preferences', ['userid' => $user->id, 'name' => $name]));
        $this->assertSame('1a2b3c4d:111', $DB->get_field('user_preferences', 'value',
            ['userid' => $other->id, 'name' => $name]));

        provider::delete_data_for_all_users_in_context($context);
        $this->assertFalse($DB->record_exists('user_preferences', ['name' => $name]));
    }
}
