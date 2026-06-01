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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for mod_googlemeet\ai_service retry selection and state changes.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_googlemeet\ai_service::class)]
class ai_service_test extends \advanced_testcase {

    /**
     * Insert a minimal googlemeet activity record.
     *
     * @return int
     */
    private function create_googlemeet(): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        return (int)$DB->insert_record('googlemeet', (object) [
            'course' => $course->id,
            'name' => 'AI retry test room',
            'originalname' => 'AI retry test room',
            'url' => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a minimal recording row.
     *
     * @param int|null $googlemeetid Google Meet instance id.
     * @return int
     */
    private function create_recording(?int $googlemeetid = null): int {
        global $DB;

        $now = time();
        $googlemeetid = $googlemeetid ?? $this->create_googlemeet();
        return (int)$DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $googlemeetid,
            'recordingid' => uniqid('drive-', true),
            'name' => 'Recording ' . random_string(8),
            'createdtime' => $now,
            'duration' => '00:05:00',
            'webviewlink' => 'https://drive.google.com/file/d/example/view',
            'visible' => 1,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert an AI analysis row.
     *
     * @param array $overrides Field overrides.
     * @return int
     */
    private function create_analysis(array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object)array_merge([
            'recordingid' => $this->create_recording(),
            'summary' => '',
            'keypoints' => '[]',
            'transcript' => '',
            'topics' => '[]',
            'language' => 'en',
            'status' => 'pending',
            'error' => null,
            'aimodel' => null,
            'retrycount' => 0,
            'nextretry' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);

        return (int)$DB->insert_record('googlemeet_ai_analysis', $record);
    }

    /**
     * Transient failures follow the bounded back-off schedule and stop when exhausted.
     *
     */
    public function test_record_transient_failure_uses_bounded_backoff_schedule(): void {
        global $DB;

        $this->resetAfterTest();

        $service = new ai_service();

        foreach (ai_service::TRANSIENT_BACKOFF as $previousretrycount => $backoff) {
            $analysisid = $this->create_analysis(['status' => 'processing', 'retrycount' => $previousretrycount]);
            $before = time();
            $service->record_transient_failure($analysisid, $previousretrycount, 'Try again');
            $after = time();

            $analysis = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], '*', MUST_EXIST);
            $expectedretrycount = $previousretrycount + 1;
            $this->assertEquals('failed', $analysis->status);
            $this->assertEquals('Try again', $analysis->error);
            $this->assertEquals($expectedretrycount, (int)$analysis->retrycount);

            if ($expectedretrycount >= ai_service::MAX_TRANSIENT_RETRIES) {
                $this->assertEquals(0, (int)$analysis->nextretry);
            } else {
                $this->assertGreaterThanOrEqual($before + $backoff, (int)$analysis->nextretry);
                $this->assertLessThanOrEqual($after + $backoff, (int)$analysis->nextretry);
            }
        }

        $analysisid = $this->create_analysis(['status' => 'processing', 'retrycount' => 12]);
        $service->record_transient_failure($analysisid, 12, 'Exhausted');
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], '*', MUST_EXIST);

        $this->assertEquals('failed', $analysis->status);
        $this->assertEquals(ai_service::MAX_TRANSIENT_RETRIES, (int)$analysis->retrycount);
        $this->assertEquals(0, (int)$analysis->nextretry);
    }

    /**
     * Permanent failures are marked failed and made non-retryable.
     *
     */
    public function test_record_permanent_failure_marks_analysis_non_retryable(): void {
        global $DB;

        $this->resetAfterTest();

        $analysisid = $this->create_analysis([
            'status' => 'processing',
            'retrycount' => 2,
            'nextretry' => time() + HOURSECS,
        ]);

        $service = new ai_service();
        $service->record_permanent_failure($analysisid, 'Bad request');

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], '*', MUST_EXIST);
        $this->assertEquals('failed', $analysis->status);
        $this->assertEquals('Bad request', $analysis->error);
        $this->assertEquals(ai_service::PERMANENT_RETRYCOUNT, (int)$analysis->retrycount);
        $this->assertEquals(0, (int)$analysis->nextretry);
    }

    /**
     * Due selection includes pending and elapsed transient failures only.
     *
     */
    public function test_get_due_analyses_includes_only_pending_and_due_transient_failures(): void {
        $this->resetAfterTest();

        $now = time();
        $pendingid = $this->create_analysis([
            'status' => 'pending',
            'timecreated' => $now - 70,
        ]);
        $duefailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => 1,
            'nextretry' => $now - 1,
            'timecreated' => $now - 60,
        ]);
        $zeroretryfailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => 0,
            'nextretry' => $now - 1,
            'timecreated' => $now - 50,
        ]);
        $futurefailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => 1,
            'nextretry' => $now + HOURSECS,
            'timecreated' => $now - 40,
        ]);
        $permanentfailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => ai_service::PERMANENT_RETRYCOUNT,
            'nextretry' => $now - 1,
            'timecreated' => $now - 30,
        ]);
        $exhaustedfailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => ai_service::MAX_TRANSIENT_RETRIES,
            'nextretry' => $now - 1,
            'timecreated' => $now - 20,
        ]);
        $completedid = $this->create_analysis([
            'status' => 'completed',
            'retrycount' => 1,
            'nextretry' => $now - 1,
            'timecreated' => $now - 10,
        ]);

        $service = new ai_service();
        $ids = array_map('intval', array_keys($service->get_due_analyses(20)));

        $this->assertContains($pendingid, $ids);
        $this->assertContains($duefailedid, $ids);
        $this->assertNotContains($zeroretryfailedid, $ids);
        $this->assertNotContains($futurefailedid, $ids);
        $this->assertNotContains($permanentfailedid, $ids);
        $this->assertNotContains($exhaustedfailedid, $ids);
        $this->assertNotContains($completedid, $ids);
    }

    /**
     * Claiming moves only pending or due transient-failure rows to processing.
     *
     */
    public function test_claim_pending_claims_only_pending_or_due_retry_rows(): void {
        global $DB;

        $this->resetAfterTest();

        $now = time();
        $pendingid = $this->create_analysis(['status' => 'pending']);
        $duefailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => 1,
            'nextretry' => $now - 1,
        ]);
        $notduefailedid = $this->create_analysis([
            'status' => 'failed',
            'retrycount' => 1,
            'nextretry' => $now + HOURSECS,
        ]);

        $service = new ai_service();

        $this->assertTrue($service->claim_pending($pendingid));
        $pending = $DB->get_record('googlemeet_ai_analysis', ['id' => $pendingid], '*', MUST_EXIST);
        $this->assertEquals('processing', $pending->status);
        $this->assertFalse($service->claim_pending($pendingid));

        $this->assertTrue($service->claim_pending($duefailedid));
        $duefailed = $DB->get_record('googlemeet_ai_analysis', ['id' => $duefailedid], '*', MUST_EXIST);
        $this->assertEquals('processing', $duefailed->status);

        $this->assertFalse($service->claim_pending($notduefailedid));
        $notduefailed = $DB->get_record('googlemeet_ai_analysis', ['id' => $notduefailedid], '*', MUST_EXIST);
        $this->assertEquals('failed', $notduefailed->status);
    }
}
