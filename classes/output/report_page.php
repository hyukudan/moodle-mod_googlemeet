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

namespace mod_googlemeet\output;

use mod_googlemeet\local\practice_attempts;
use moodle_url;
use renderer_base;

/**
 * Teacher report page (ANA-04): template context for mod_googlemeet/report_page.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_page implements \renderable, \templatable {

    /** @var array Result of report_builder::build(). */
    protected $data;

    /** @var \stdClass Course-module. */
    protected $cm;

    /** @var \stdClass Activity record. */
    protected $googlemeet;

    /** @var \context_module Context. */
    protected $context;

    /** @var array Filter state: groupid, inactive, groups (id => name), page, perpage. */
    protected $filters;

    /** @var string[] Identity fields to show. */
    protected $identityfields;

    /**
     * Constructor.
     *
     * @param array $data Result of report_builder::build().
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass $cm Course-module.
     * @param \context_module $context Context.
     * @param array $filters Filter state.
     * @param string[] $identityfields Identity fields.
     */
    public function __construct(array $data, \stdClass $googlemeet, \stdClass $cm, \context_module $context,
            array $filters, array $identityfields) {
        $this->data = $data;
        $this->googlemeet = $googlemeet;
        $this->cm = $cm;
        $this->context = $context;
        $this->filters = $filters;
        $this->identityfields = $identityfields;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $recordings = $this->data['recordings'];
        $totalrec = count($recordings);
        $students = $this->data['totalstudents'];
        $now = time();

        $headers = [];
        $index = 0;
        foreach ($recordings as $recording) {
            $index++;
            $stats = $this->data['recordingstats'][$recording->id];
            $headers[] = [
                'index' => $index,
                'name' => format_string(googlemeet_display_name((string)$recording->name), true,
                    ['context' => $this->context]),
                'date' => userdate($recording->createdtime, get_string('strftimedateshort', 'langconfig')),
                'hidden' => empty($recording->visible),
                'url' => (new moodle_url('/mod/googlemeet/view.php',
                    ['id' => $this->cm->id, 'recording' => $recording->id]))->out(false),
                'completedlabel' => get_string('report_rec_completed', 'googlemeet',
                    ['n' => $stats['completed'], 'total' => $students]),
            ];
        }

        // Paginate rows (the full set is needed for the filters and the summary).
        $allrows = array_values($this->data['rows']);
        $perpage = (int)$this->filters['perpage'];
        $rowsslice = $perpage > 0 ? array_slice($allrows, $this->filters['page'] * $perpage, $perpage) : $allrows;

        $rows = [];
        foreach ($rowsslice as $row) {
            $cells = [];
            foreach ($recordings as $recording) {
                $cell = $row['cells'][$recording->id];
                $state = $cell['completed'] ? 'completed' : ($cell['opened'] ? 'partial' : 'unseen');
                $cells[] = [
                    'pct' => $cell['pct'],
                    'state' => $state,
                    'iscompleted' => $state === 'completed',
                    'ispartial' => $state === 'partial',
                    'isunseen' => $state === 'unseen',
                    'title' => get_string('report_cell_title', 'googlemeet', [
                        'pct' => $cell['pct'],
                        'date' => $cell['timemodified'] ? userdate($cell['timemodified'],
                            get_string('strftimedatetimeshort', 'langconfig')) : get_string('never'),
                    ]),
                ];
            }
            $identity = [];
            foreach ($this->identityfields as $field) {
                $identity[] = ['value' => s((string)($row['user']->$field ?? ''))];
            }
            $rows[] = [
                'fullname' => fullname($row['user']),
                'profileurl' => (new moodle_url('/user/view.php',
                    ['id' => $row['user']->id, 'course' => $this->cm->course]))->out(false),
                'identity' => $identity,
                'completedlabel' => $row['completed'] . '/' . $totalrec,
                'lastaccess' => $row['lastaccess'] ? userdate($row['lastaccess'],
                    get_string('strftimedatetimeshort', 'langconfig')) : get_string('never'),
                'lastaccessago' => $row['lastaccess'] ? format_time($now - $row['lastaccess']) : '',
                'inactive' => $row['inactive'],
                'cells' => $cells,
            ];
        }

        // Summary.
        $inactive = 0;
        $completedsum = 0;
        foreach ($allrows as $row) {
            $inactive += $row['inactive'] ? 1 : 0;
            $completedsum += $row['completed'];
        }
        $avgpct = ($students && $totalrec) ? (int)round($completedsum * 100 / ($students * $totalrec)) : 0;

        // Most failed questions per recording.
        $failed = practice_attempts::get_most_failed_by_recording((int)$this->googlemeet->id, 5);
        $failedsections = [];
        foreach ($recordings as $recording) {
            if (empty($failed[$recording->id])) {
                continue;
            }
            $items = [];
            foreach ($failed[$recording->id] as $item) {
                $items[] = [
                    'name' => $item['name'],
                    'wrongpct' => 100 - $item['correctpct'],
                    'detail' => get_string('report_failed_detail', 'googlemeet', [
                        'wrong' => $item['wrong'],
                        'attempts' => $item['attempts'],
                        'users' => $item['users'],
                    ]),
                ];
            }
            $failedsections[] = [
                'name' => format_string(googlemeet_display_name((string)$recording->name), true,
                    ['context' => $this->context]),
                'url' => (new moodle_url('/mod/googlemeet/view.php',
                    ['id' => $this->cm->id, 'recording' => $recording->id]))->out(false),
                'items' => $items,
            ];
        }

        $groups = [];
        foreach ($this->filters['groups'] as $id => $name) {
            $groups[] = ['id' => $id, 'name' => $name, 'selected' => $id === (int)$this->filters['groupid']];
        }

        $identityheaders = [];
        foreach ($this->identityfields as $field) {
            $identityheaders[] = ['name' => \core_user\fields::get_display_name($field)];
        }

        return [
            'cmid' => $this->cm->id,
            'formaction' => (new moodle_url('/mod/googlemeet/report.php'))->out(false),
            'hasgroups' => !empty($groups),
            'groups' => $groups,
            'allgroupsselected' => empty($this->filters['groupid']),
            'inactive' => !empty($this->filters['inactive']),
            'inactivedays' => \mod_googlemeet\local\report_builder::INACTIVE_DAYS,
            'studentcount' => $students,
            'recordingcount' => $totalrec,
            'avgpct' => $avgpct,
            'inactivecount' => $inactive,
            'hasrecordings' => $totalrec > 0,
            'hasrows' => !empty($rows),
            'headers' => $headers,
            'identityheaders' => $identityheaders,
            'rows' => $rows,
            'hasfailed' => !empty($failedsections),
            'failedsections' => $failedsections,
        ];
    }
}
