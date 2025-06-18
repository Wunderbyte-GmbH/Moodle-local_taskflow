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
 * Contains class mod_questionnaire\output\indexpage
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use local_taskflow\local\assignments\assignment;
use renderable;
use renderer_base;
use templatable;
use context_system;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class editassignment implements renderable, templatable {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    private $data = [];

    /**
     * Constructor.
     * @param array $data
     */
    public function __construct(array $data) {

        global $DB, $CFG, $PAGE;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        if (empty($data['id'])) {
            throw new \moodle_exception('invalidassignmentid', 'local_taskflow');
        }
        $assignment = new assignment($data['id']);
        $this->data['assignmentdata'] = [];

        $assignmentdata = $assignment->return_class_data();

        $labels = [
            'fullname' => [
                'label' => get_string('fullname'),
                'returnvalue' => fn($value) => format_string($value),
            ],
            'targetgroup' => [
                'label' => get_string('targetgroup', 'local_taskflow'),
                'returnvalue' => function ($value) {
                    $user = \core_user::get_user($value);
                    $profilefileds = profile_user_record($user->id, false);
                    if ($user) {
                        return 'x';
                    }
                    return get_string('unknown');
                },
            ],
            'name' => [
                'label' => get_string('name'),
                'returnvalue' => fn($value) => format_string($value),
            ],
            'ruledescription' => [
                'label' => get_string('description'),
                'returnvalue' => fn($value) => format_string($value),
            ],
            'assigneddate' => [
                'label' => get_string('assigneddate', 'local_taskflow'),
                'returnvalue' => fn($value) => userdate($value),
            ],
            'active' => [
                'label' => get_string('status'),
                'returnvalue' => function ($value) {
                    switch ($value) {
                        case 1:
                            return get_string('active');
                        case 0:
                            return get_string('inactive');
                        default:
                            return get_string('unknown');
                    }
                },
            ],
            'usermodified' => [
                'label' => get_string('usermodified', 'local_taskflow'),
                'returnvalue' => function ($value) {
                    $user = \core_user::get_user($value);
                    return fullname($user);
                },
            ],
        ];

        foreach ($labels as $key => $value) {
            $this->data['assignmentdata'][] = [
                'label' => $value['label'],
                'value' => $value['returnvalue']($assignmentdata->{$key} ?? ''),
            ];
        }
        if (has_capability('local/taskflow:viewassignment', context_system::instance())) {
            // We create the Form to edit the element.
            $form = new \local_taskflow\form\editassignment(
                null,
                null,
                'post',
                '',
                [],
                true,
                [
                    'id' => $assignment->id,
                ]
            );
            $form->set_data_for_dynamic_submission();
            $this->data['editassignmentform'] = $form->render();
        }
        $this->data['id'] = $assignment->id;

        $historydata = new history($assignment->id);
        $renderer = $PAGE->get_renderer('local_taskflow');
        $this->data['historylist'] = $renderer->render_history($historydata);
    }

    /**
     * Prepare data for use in a template
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->data;
    }
}
