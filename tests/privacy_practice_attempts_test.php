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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_googlemeet\local\practice_attempts;
use mod_googlemeet\privacy\provider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy API coverage of googlemeet_practice_attempts (ANA-05).
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\privacy\provider::class)]
final class privacy_practice_attempts_test extends \core_privacy\tests\provider_testcase {

    /**
     * Two activities, two students with attempts.
     *
     * @return array
     */
    private function fixture(): array {
        global $DB;

        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gm1 = $gen->create_module('googlemeet', ['course' => $course->id, 'url' => 'https://meet.google.com/abc-defg-hij']);
        $gm2 = $gen->create_module('googlemeet', ['course' => $course->id, 'url' => 'https://meet.google.com/abc-defg-hik']);
        $u1 = $gen->create_and_enrol($course, 'student');
        $u2 = $gen->create_and_enrol($course, 'student');

        $recs = [];
        foreach ([$gm1, $gm2] as $gm) {
            $recs[$gm->id] = (int)$DB->insert_record('googlemeet_recordings', (object)[
                'googlemeetid' => $gm->id, 'recordingid' => 'drive-' . $gm->id, 'name' => 'Class ' . $gm->id,
                'createdtime' => time(), 'duration' => '00:10:00', 'webviewlink' => 'https://drive.google.com/x',
                'visible' => 1, 'timemodified' => time(),
            ]);
        }
        practice_attempts::record((int)$gm1->id, $recs[$gm1->id], 11, (int)$u1->id, false);
        practice_attempts::record((int)$gm1->id, $recs[$gm1->id], 11, (int)$u1->id, true);
        practice_attempts::record((int)$gm1->id, $recs[$gm1->id], 12, (int)$u2->id, false);
        practice_attempts::record((int)$gm2->id, $recs[$gm2->id], 21, (int)$u1->id, true);

        return [
            \context_module::instance($gm1->cmid),
            \context_module::instance($gm2->cmid),
            $u1, $u2, $gm1, $gm2, $recs,
        ];
    }

    /**
     * The table is declared in the metadata.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_googlemeet'));
        $tables = [];
        foreach ($collection->get_collection() as $item) {
            $tables[] = $item->get_name();
        }
        $this->assertContains('googlemeet_practice_attempts', $tables);
    }

    /**
     * Contexts, users, export and the three delete paths.
     */
    public function test_contexts_export_and_delete(): void {
        global $DB;

        [$ctx1, $ctx2, $u1, $u2, $gm1, $gm2, $recs] = $this->fixture();

        $contextids = provider::get_contexts_for_userid($u1->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$ctx1->id, $ctx2->id], array_map('intval', $contextids));

        $userlist = new userlist($ctx1, 'mod_googlemeet');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], array_map('intval', $userlist->get_userids()));

        // Export.
        provider::export_user_data(new approved_contextlist($u1, 'mod_googlemeet', [$ctx1->id]));
        $data = writer::with_context($ctx1)->get_related_data([get_string('privacy:practiceattempts', 'googlemeet')],
            'recording_' . $recs[$gm1->id]);
        $this->assertCount(2, $data->attempts);
        $this->assertEquals(11, $data->attempts[0]['questionid']);

        // Delete one user in one context.
        provider::delete_data_for_user(new approved_contextlist($u1, 'mod_googlemeet', [$ctx1->id]));
        $this->assertEquals(0, $DB->count_records(practice_attempts::TABLE, ['googlemeetid' => $gm1->id, 'userid' => $u1->id]));
        $this->assertEquals(1, $DB->count_records(practice_attempts::TABLE, ['googlemeetid' => $gm1->id, 'userid' => $u2->id]));
        $this->assertEquals(1, $DB->count_records(practice_attempts::TABLE, ['googlemeetid' => $gm2->id, 'userid' => $u1->id]));

        // Delete a user list.
        provider::delete_data_for_users(new approved_userlist($ctx1, 'mod_googlemeet', [$u2->id]));
        $this->assertEquals(0, $DB->count_records(practice_attempts::TABLE, ['googlemeetid' => $gm1->id]));

        // Delete everything in a context.
        provider::delete_data_for_all_users_in_context($ctx2);
        $this->assertEquals(0, $DB->count_records(practice_attempts::TABLE));
    }
}
