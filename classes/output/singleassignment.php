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

use context_system;
use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_types\requests_manager;
use local_taskflow\local\rules\rules;
use local_taskflow\local\supervisor\supervisor;
use mod_booking\singleton_service;
use renderable;
use renderer_base;
use taskflowadapter_tuines\form\internal_communication_form;
use templatable;
use context_user;
use moodle_exception;
use moodle_url;
use stdClass;
use html_writer;
use local_taskflow\taskflow_stringmanager;
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
        global $DB, $PAGE, $USER;

        if (empty($data['id'])) {
            throw new moodle_exception('invalidassignmentid', 'local_taskflow');
        }

        $PAGE->set_context(context_system::instance());
        $url = new moodle_url('/local/taskflow/assignment.php', ['id' => $data['id']]);
        $PAGE->set_url($url);

        if (!empty($data['returnurl'])) {
            $this->data['returnurl'] = $data['returnurl'];
        }

        $assignment = assignment::get_instance($data['id']);
        $assignmentdata = $assignment->return_class_data();

        $assignmentdata->assignmentid = $assignmentdata->id;
        $this->data['assignmentdata'] = [];
        $this->data['assignmentdata'] = $assignmentdata;
        $this->data['userid'] = $assignmentdata->userid;
        $this->data['fullname'] = $assignmentdata->fullname;
        $this->data['assignmentdata']->duedate = $this->set_due_date_information($assignmentdata->duedate);
        $this->get_request_states($this->data);

        if (
            class_exists('mod_booking\\price') &&
            method_exists('\mod_booking\price', 'set_bookforuser')
        ) {
            \mod_booking\price::set_bookforuser($assignmentdata->userid);
        }
        $supervisor = supervisor::get_supervisor_for_user($assignmentdata->userid);
        if (!empty($supervisor->id)) {
            $this->data['supervisoremail'] = $supervisor->email;
            $this->data['supervisorfullname'] = "$supervisor->firstname $supervisor->lastname";
            $this->data['supervisorid'] = $supervisor->id;
        }
        if (class_exists('mod_booking\\shortcodes')) {
            $targets = json_decode($assignmentdata->targets, true);
            if (is_array($targets)) {
                foreach ($targets as $target) {
                    $target['allowuploadevidence'] = $this->data['allowuploadevidence'];
                    $target['targetname'] = targets_factory::get_name($target['targettype'], $target['targetid']);
                    if ($target['completionstatus'] == 1) {
                        $target['completed'] = 1;
                    }
                    $target['assignmentid'] = $data['id'];
                    $target['targettypestr'] = taskflow_stringmanager::get_string($target['targettype']);
                    $this->process_target($target, $assignmentdata);
                }
            }
        }

        // Get user picture.
        $user = \core_user::get_user($assignmentdata->userid);
        $userpicture = new \user_picture($user);
        $userpicture->size = 1;
        $this->data['profilepicurl'] = $userpicture->get_url($PAGE)->out(false);
        $this->data['ismyassignment'] = $assignment->is_my_assignment();

        // Get user assignment list.
        $args = [];
        $env = new stdClass();
        $myassignments = \local_taskflow\shortcodes::myassignments('myassignments', $args, null, $env, $env);
        $this->data['myassignments'] = $myassignments;
        $this->data['hasinternalcommunication'] = false;
        $this->data['internalcommunicationform'] = '';
        if ($this->is_my_assignment() || $this->i_am_supervisor()) {
            $allowinternalcommunication = !empty((int) get_config('local_taskflow', 'allowinternalcommunication'));
            if ($allowinternalcommunication) {
                $commentform = new internal_communication_form(
                    null,
                    null,
                    'post',
                    '',
                    [],
                    true,
                    ['id' => $this->data['assignmentdata']->id, 'userid' => $USER->id]
                );
                $commentform->set_data_for_dynamic_submission();
                $renderedform = $commentform->render();
                if (!empty($renderedform)) {
                    $this->data['internalcommunicationform'] = $renderedform;
                    $this->data['hasinternalcommunication'] = true;
                }
            }
        }
    }

    /**
     * Prepare course list for the target.
     * @param array $data
     * @return void
     */
    private function get_request_states(&$data): void {
        global $DB;
        // Global all request states.
        $requestmanager = new requests_manager();
        $requests = array_merge($requestmanager->get_active_request_types(), $requestmanager->get_inactive_request_types());

        // Local request states.
        rules::destroy_instance();
        $rule = rules::instance($data['assignmentdata']->ruleid);
        $rulejson = json_decode($rule->get_rulesjson());

        foreach ($requests as $key => $request) {
            $rulekey = 'receiver_' . $key;
            if (
                isset($rulejson->rulejson->rule->actions[0]->requests->$rulekey) &&
                is_number($rulejson->rulejson->rule->actions[0]->requests->$rulekey)
            ) {
                $data[$key] = true;
                $requesttypes = $requestmanager->get_request_types_with_ids();
                $requesttypes = array_flip($requesttypes);

                $data[$key . '_disabled'] = $this->get_disabled_status(
                    $data,
                    $requesttypes[$key]
                );
            } else {
                $data[$key] = false;
            }
        }
        return;
    }

    /**
     * Prepare course list for the target.
     * @param array $data
     * @param int $key
     * @return bool
     */
    private function get_disabled_status($data, $key): bool {
        global $DB;

        $requestdata = [
            'userid' => $data['userid'],
            'assignmentid' => $data['assignmentdata']->id,
            'status' => $key,
            'treated' => requests::TREATED_STATUS_UNTREATED,
        ];
        return $DB->record_exists('local_taskflow_requests', $requestdata);
    }


    /**
     * Prepare course list for the target.
     * @param string $duedate
     * @return string
     */
    private function set_due_date_information($duedate): string {
        if (!empty($duedate)) {
            return userdate($duedate);
        }
        return taskflow_stringmanager::get_string('duedatenotsetyet');
    }

    /**
     * Prepare course list for the target.
     * @param array $target
     * @param int $userid
     * @return array
     */
    public function prepare_courselist(array $target, int $userid): array {
        $courselist = [];
        $courselist['targetname'] = targets_factory::get_name($target['targettype'], $target['targetid']);
        $courselist['list'] = \mod_booking\option\fields\competencies::get_list_of_similar_options(
            $target['targetid'],
            null,
            false,
            $userid
        );
        if (empty($list)) {
            $list = taskflow_stringmanager::get_string('nocoursesavailable');
        }
        return $courselist;
    }

    /**
     * Process competency target.
     * @param array $target
     * @param mixed $assignmentdata
     * @return array
     */
    private function process_competency_target($target, $assignmentdata): array {
        global $USER;

        $target['allowuploadevidence'] = get_config('local_taskflow', 'allowuploadevidence');

        $target['evidence'] = \local_taskflow\local\competencies\assignment_competency::get_with_evidence_by_user_and_competency(
            $assignmentdata->userid,
            $target['targetid'],
            true
        );

        // TODO: Better check if user should see the actionbuttons to treat the request.
        if (
            $assignmentdata->userid != $USER->id
            && has_capability('local/taskflow:issupervisor', context_system::instance())
        ) {
            $target['displayactionbuttons'] = true;
        }

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
        return $target;
    }

    /**
     * Process booking target.
     * @param array $target
     * @param \stdClass $assignment
     * @return array
     */
    private function process_booking_target(array $target, stdClass $assignment) {
        global $PAGE;

        $returnurl = $PAGE->url->out();
        $settings = singleton_service::get_instance_of_booking_option_settings($target['targetid']);
        $url = new moodle_url("/mod/booking/optionview.php", [
            "optionid" => (int)$settings->id,
            "cmid" => (int)$settings->cmid,
            "userid" => (int)$assignment->userid,
            'returnto' => 'url',
            'returnurl' => $returnurl,
        ]);
        $target['targetname'] = html_writer::link($url, format_string($target['targetname']));

        return $target;
    }

    /**
     * Process booking target.
     * @param array $target
     * @param \stdClass $assignment
     * @return array
     */
    private function process_course_target(array $target, stdClass $assignment) {
        $url = new moodle_url('/course/view.php', [
            'id' => $target['targetid'],
        ]);
        $target['targetname'] = html_writer::link($url, $target['targetname']);
        return $target;
    }

    /**
     * Process the target based on its type.
     * @param array $target
     * @param stdClass $assignmentdata
     * @return void
     */
    private function process_target(array $target, stdClass $assignmentdata): void {
        switch ($target['targettype'] ?? null) {
            case 'competency':
                $this->data['target'][]  = $this->process_competency_target($target, $assignmentdata);
                $this->data['hascompetency'] = true;
                $this->data['courselist'][] = $this->prepare_courselist($target, (int)$assignmentdata->userid);
                break;
            case 'bookingoption':
                $this->data['target'][] = $this->process_booking_target($target, $assignmentdata);
                break;
            case 'moodlecourse':
                $this->data['target'][] = $this->process_course_target($target, $assignmentdata);
                break;
            default:
                $this->data['target'][] = $target;
        }
    }

    /**
     * check if it is my assignment
     * @return bool
     */
    public function is_my_assignment(): bool {
        return $this->data['ismyassignment'];
    }

    /**
     * check if it is my assignment
     * @return bool
     */
    public function i_am_supervisor(): bool {
        global $USER;
        return ($this->data['supervisorid'] ?? false) == $USER->id;
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

    /**
     * Returns the id of the assignment's user.
     *
     * @return int
     *
     */
    public function get_userid(): int {
        return $this->data['userid'];
    }

    /**
     * Returns the assignmentdata.
     *
     * @return stdClass
     *
     */
    public function get_assignmentdata(): stdClass {
        return $this->data['assignmentdata'];
    }
}
