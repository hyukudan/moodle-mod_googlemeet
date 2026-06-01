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

namespace mod_googlemeet\task;

use core\task\adhoc_task;
use mod_googlemeet\ai_service;
use mod_googlemeet\gemini_transient_exception;

/**
 * Adhoc task to generate draft question-bank questions for a recording.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_questions extends adhoc_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('question_generate_task', 'googlemeet');
    }

    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $recordingid = (int)($data->recordingid ?? 0);
        $count = (int)($data->count ?? 10);

        mtrace("Generating Google Meet questions for recording {$recordingid}...");

        try {
            $service = new ai_service();
            $created = $service->generate_questions_for_recording($recordingid, $count);
            mtrace("Created {$created} draft questions.");
        } catch (gemini_transient_exception $e) {
            mtrace('Transient Gemini error while generating questions: ' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            mtrace('Question generation failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
