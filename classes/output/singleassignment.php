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
 * @author     Thomas Winkler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use local_taskflow\local\assignments\assignment;
use renderable;
use renderer_base;
use templatable;
use context_user;
use moodle_exception;
use moodle_url;
/**
 * Display this element
 * @package local_taskflow
 *
 */
class singleassignment implements renderable, templatable {
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

        global $DB, $PAGE;

        if (empty($data['id'])) {
            throw new \moodle_exception('invalidassignmentid', 'local_taskflow');
        }
        $assignment = new assignment($data['id']);
        $assignmentdata = $assignment->return_class_data();

        $this->data['assignmentdata'] = [];
        $this->data['assignmentdata'] = $assignmentdata;
        $this->data['userid'] = $assignmentdata->userid;
        $this->data['fullname'] = $assignmentdata->fullname;
        $this->data['assignmentdata']->duedate = userdate($assignmentdata->duedate);

        if (class_exists('mod_booking\\shortcodes')) {
            $targets = json_decode($assignmentdata->targets, true);
            $competencyids = '';
            $this->data['courselist'] = "";
            if (is_array($targets)) {
                foreach ($targets as $target) {
                    if (isset($target['targettype']) && $target['targettype'] === 'competency') {
                        $target['evidence'] =
                            \local_taskflow\local\competencies\assignment_competency::get_with_evidence_by_user_and_competency(
                                $assignmentdata->userid,
                                $target['targetid']
                            );

                        if (empty((array) $target['evidence'])) {
                            unset($target['evidence']);
                        } else {
                            $userevidence = \core_competency\api::read_user_evidence($target['evidence']->competencyevidenceid);
                            $fs = get_file_storage();

                            $files = $fs->get_area_files(
                                context_user::instance($assignmentdata->userid)->id,
                                'core_competency',
                                'userevidence',
                                $userevidence->get('id'),
                                'sortorder, itemid, filepath, filename',
                                false
                            );

                            foreach ($files as $file) {
                                $url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    $file->get_itemid(),
                                    $file->get_filepath(),
                                    $file->get_filename()
                                );
                                $target['file'][] = [
                                    'url' => $url->out(),
                                    'name' => $file->get_filename(),
                                ];
                            }
                        }

                        $this->data['target'][] = $target;
                        $competencyids .= $target['targetid'];
                    }
                }


                $list = \mod_booking\option\fields\competencies::get_list_of_similar_options($competencyids);
                if (empty($list)) {
                    $list = get_string('nocoursesavailable', 'local_taskflow');
                }
                $this->data['courselist'] .= $list;
                $this->data['courselist'] .= '<br>';
            }
        }
        $user = \core_user::get_user($assignmentdata->userid);
        $userpicture = new \user_picture($user);
        $userpicture->size = 1;
        $this->data['profilepicurl'] = $userpicture->get_url($PAGE)->out(false);
        $this->data['ismyassignment'] = $assignment->is_my_assignment();
    }

    /**
     * check if it is my assignment
     * @return bool
     */
    public function is_my_assignment(): bool {
        return $this->data['ismyassignment'];
    }

    /**
     * Prepare data for use in a template
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return $this->data;
    }
}
