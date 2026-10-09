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

use mod_googlemeet\local\recording_cleanup;

/**
 * Daily purge of recordings that stayed in the teacher trash longer than googlemeet/trashretentiondays (OPS-03).
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_trash extends \core\task\scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_purge_trash', 'mod_googlemeet');
    }

    /**
     * Purge expired trash with the central cascade delete.
     */
    public function execute() {
        $days = recording_cleanup::get_retention_days();
        if ($days <= 0) {
            mtrace('mod_googlemeet purge_trash: retention disabled (0 days), nothing purged.');
            return;
        }
        $stats = recording_cleanup::purge_expired_trash(null, $days);
        mtrace(sprintf('mod_googlemeet purge_trash: retention %d days, %d recording(s) purged '
                . '(analysis %d, progress %d, attempts %d, files %d, preferences %d, questions deleted %d, hidden %d).',
            $days, $stats['recordings'], $stats['analysis'], $stats['progress'], $stats['attempts'], $stats['files'],
            $stats['preferences'], $stats['questionsdeleted'], $stats['questionshidden']));
    }
}
