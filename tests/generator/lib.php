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
 * Module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_competency\api;
use core_competency\competency;
use local_taskflow\local\actions\targets\types\moodlecourse;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\assignments\types\standard_assignment;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\personas\unit_members\types\unit_member;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\unit_rules;
use local_taskflow\local\units\organisational_units\cohort;
use local_taskflow\local\units\organisational_units\unit;
use local_taskflow\local\units\unit_relations;
use local_taskflow\plugininfo\taskflowadapter;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Class to handle module booking data generator
 *
 * @package local_taskflow
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2023 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_taskflow_generator extends testing_module_generator {
    // phpcs:disable
    /**
     * To be called from data reset code only, do not use in tests.
     *
     * @return void
     */
    public function reset() {
        parent::reset();
    }
    // phpcs:enable

    /**
     * Creates a standard assignemnt for a user.
     * @param int $userid
     * @param int $ruleid
     *
     * @return \local_taskflow\local\assignments\assignment
     *
     */
    public function create_user_assignment(int $userid, int $ruleid) {

        $data = [
            'userid' => $userid,
            'ruleid' => $ruleid,
            'unitid' => 1,
            'assigneddate' => time(),
            'duedate' => time() + 3600,
        ];

        $assignment = new assignment();
        $result = $assignment->add_or_update_assignment($data);

        return $assignment;
    }


    /**
     * Helper to run tasks within time.
     *
     *
     * @param mixed $cronlock
     * @param mixed $lock
     * @param mixed $mocktime
     *
     * @return void
     *
     */
    public function runtaskswithintime($cronlock, $lock, $mocktime) {
        global $DB;

        $params = [];

        $tasks = $DB->get_recordset('task_adhoc', $params);
        foreach ($tasks as $record) {
            if ($record->nextruntime <= $mocktime) {
                $task = \core\task\manager::adhoc_task_from_record($record);
                $user = null;
                if ($userid = $task->get_userid()) {
                    // This task has a userid specified.
                    $user = \core_user::get_user($userid);

                    // User found. Check that they are suitable.
                    \core_user::require_active_user($user, true, true);
                }

                $task->set_lock($lock);
                $cronlock->release();

                \core\cron::prepare_core_renderer();
                \core\cron::setup_user($user);

                $task->execute();
                \core\task\manager::adhoc_task_complete($task);

                unset($task);
            }
        }
        $tasks->close();
    }

    /**
     * Creates more or less empty rule.
     * @param array $options
     *
     * @return int
     *
     */
    public function create_rule(array $options = []) {

        global $DB;

        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'rulename' => 'Test Rule',
            'rulejson' => '{}',
        ]);

        return $ruleid;
    }

    /**
     * Creates custom user profile fields in Moodle using the provided shortnames.
     *
     * @param array $shortnames Array of strings to use as shortnames for custom fields.
     * @return array Array of created field IDs indexed by shortname.
     * @throws moodle_exception If a field could not be created.
     */
    public function create_custom_profile_fields(array $shortnames): array {
        global $DB, $CFG;

        $createdfields = [];

        foreach ($shortnames as $shortname) {
            // Skip if field with this shortname already exists.
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }

            // Define the field data.
            $data = (object)[
                'shortname' => $shortname,
                'name' => ucfirst($shortname),
                'datatype' => 'text',
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => 1, // Default category (you might want to ensure this exists or create your own).
                'sortorder' => 0,
                'required' => 0,
                'locked' => 0,
                'visible' => 1,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_HTML,
                'param1' => 30, // Text field max length.
            ];

            // Create the field.
            require_once($CFG->dirroot . '/user/profile/definelib.php');
            $handler = new profile_define_base();

            $handler->define_save($data);

            // Get the ID of the created field.
            $record = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', MUST_EXIST);
            $createdfields[$shortname] = $record->id;
        }

        return $createdfields;
    }

    /**
     * Creates Supervisorrole for Mapping.
     *
     * @return int
     *
     */
    public function create_supervisorrole() {
        global $DB;
        if ($role = $DB->get_record('role', ['shortname' => 'supervisor'])) {
            return $role->id;
        }
        $roleid = create_role(
            'Supervisor',
            'supervisor',
            ''
        );
        $contextid = context_system::instance()->id;
        assign_capability(
            'local/taskflow:issupervisor',
            CAP_ALLOW,
            $roleid,
            $contextid
        );
        return $roleid;
    }

    /**
     * Set config values
     * @param string $type
     * @param array $override
     * @param array $overridesubplugin
     *
     * @return void
     *
     */
    public function set_config_values(
        string $type = 'standard',
        array $override = [],
        array $overridesubplugin = []
    ): void {

        // First, we set the general taskflow settings.
        $taskflowsettings = [
            'organisational_unit_option' => 'cohort',
            'supervisor_field' => 'supervisor',
            'external_api_option' => $type,
            'supervisorrole' => $this->create_supervisorrole(),
        ];

        // Now, set the settings for the specific type.
        switch ($type) {
            case 'tuines':
                $taskflowadaptersettings = [
                    'usingprolongedstate' => 1,
                    taskflowadapter::TRANSLATOR_USER_FIRSTNAME => "firstName",
                    taskflowadapter::TRANSLATOR_USER_LASTNAME => "lastName",
                    taskflowadapter::TRANSLATOR_USER_EMAIL => "eMailAddress",
                    taskflowadapter::TRANSLATOR_USER_TARGETGROUP => "targetGroup",
                    "units" => taskflowadapter::TRANSLATOR_USER_TARGETGROUP,
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT => "orgUnit",
                    "organisation" => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR => "directSupervisor",
                    "supervisor" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
                    taskflowadapter::TRANSLATOR_USER_LONG_LEAVE => "currentlyOnLongLeave",
                    "longleave" => taskflowadapter::TRANSLATOR_USER_LONG_LEAVE,
                    taskflowadapter::TRANSLATOR_USER_EXTERNALID => "tissId",
                    "externalid" => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_NAME => "displayNameDE",
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_DESCRIPTION => "descriptionDE",
                    taskflowadapter::TRANSLATOR_TARGET_GROUP_UNITID => "number",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTEND => "contractEnd",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTSTART => "contractStart",
                    "contractend" => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                    "contractstart" => taskflowadapter::TRANSLATOR_USER_CONTRACTSTART,
                    'organisational_unit_option' => 'cohort',
                    'user_profile_option' => 'tuines',
                    'supervisor_field' => 'supervisor',
                    'excludestatus' => '3,7',
                ];
                break;
            case 'ksw':
                $taskflowadaptersettings = [
                    taskflowadapter::TRANSLATOR_USER_FIRSTNAME => "Firstname",
                    taskflowadapter::TRANSLATOR_USER_LASTNAME => "LastName",
                    taskflowadapter::TRANSLATOR_USER_EMAIL => "DefaultEmailAddress",
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT => "Organisation",
                    taskflowadapter::TRANSLATOR_USER_EXTERNALID => "userID",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTEND => "ExitDate",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTSTART => "EntryDate",
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL => 'Manager_Id',
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR => 'supervisor',
                    "supervisor_external" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL,
                    "orgunit" => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    "supervisor" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
                    "externalid" => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
                    "contractend" => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                    "contractstart" => taskflowadapter::TRANSLATOR_USER_CONTRACTSTART,
                    'organisational_unit_option' => 'cohort',
                    'user_profile_option' => 'thour',
                    'supervisor_field' => 'supervisor',
                ];
                break;
            case 'standard':
            default:
                $taskflowadaptersettings = [
                    taskflowadapter::TRANSLATOR_USER_FIRSTNAME => "Firstname",
                    taskflowadapter::TRANSLATOR_USER_LASTNAME => "LastName",
                    taskflowadapter::TRANSLATOR_USER_EMAIL => "DefaultEmailAddress",
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT => "Organisation",
                    taskflowadapter::TRANSLATOR_USER_EXTERNALID => "userID",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTEND => "ExitDate",
                    taskflowadapter::TRANSLATOR_USER_CONTRACTSTART => "EntryDate",
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL => 'Manager_Id',
                    taskflowadapter::TRANSLATOR_USER_SUPERVISOR => 'supervisor',
                    "supervisor_external" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL,
                    "orgunit" => taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    "supervisor" => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
                    "externalid" => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
                    "contractend" => taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
                    "contractstart" => taskflowadapter::TRANSLATOR_USER_CONTRACTSTART,
                    'organisational_unit_option' => 'cohort',
                    'user_profile_option' => 'thour',
                    'supervisor_field' => 'supervisor',
                ];
                break;
        }
        foreach ($taskflowsettings as $key => $value) {
            $value = $override[$key] ?? $value;
            set_config($key, $value, 'local_taskflow');
        }
        foreach ($taskflowadaptersettings as $key => $value) {
            $value = $overridesubplugin[$key] ?? $value;
            set_config($key, $value, 'taskflowadapter_' . $type);
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
    }

    /**
     * This function makes sure that the data expected by the test is correctly created.
     *
     * @param mixed $user
     * @param mixed $action
     * @param mixed $option
     * @param mixed $rule
     *
     * @return void
     *
     */
    public function apply_user_action($user, $action, $option, $rule) {
        switch ($action) {
            case 'completed':
                // Mark course as completed for user.
                $option = singleton_service::get_instance_of_booking_option($option->cmid, $option->id);
                $option->user_submit_response($user, 0, 0, 0, true);
                $option->toggle_user_completion($user->id);
                break;
        }
    }

    /**
     * Create any number of competencies needed.
     * @param advanced_testcase $testcase
     * @param int $number
     *
     * @return array
     *
     */
    public function create_competencies(advanced_testcase $testcase, int $number = 1): array {

        set_config('usecompetencies', 1, 'booking');

        $competencies = [];
        $scale = $testcase->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

        // Create a competency.
        $framework = api::create_framework((object)[
            'shortname' => 'testframework',
            'idnumber' => 'testframework',
            'contextid' => context_system::instance()->id,
            'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode([
                ['scaleid' => $scale->id],
                ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
                ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
            ]),
        ]);

        while ($number-- > 0) {
            // Create compentencies.
            $record = (object)[
                'shortname' => 'testcompetency' . $number,
                'idnumber' => 'testcompetency' . $number,
                'competencyframeworkid' => $framework->get('id'),
                'scaleid' => null,
                'description' => 'A test competency ' . $number,
                'id' => 0,
                'scaleconfiguration' => null,
                'parentid' => 0,
            ];
            $competency = new competency(0, $record);
            $competency->set('sortorder', 0);
            $competency->create();

            $competencies[] = $competency;
        }

        return $competencies;
    }

    /**
     * Create a booking option with given params.
     * @param advanced_testcase $testcase
     * @param int $courseid
     * @param stdClass $user
     * @param int $number
     * @param array $bookinginstancedata
     * @param array $bookingoptiondata
     *
     * @return array
     *
     */
    public function create_booking_options(
        advanced_testcase $testcase,
        int $courseid,
        stdClass $user, // As booking manager.
        int $number = 1,
        array $bookinginstancedata = [],
        array $bookingoptiondata = [],
    ): array {
        global $DB;

        $totalnumber = $number;

        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $bdata['course'] = $courseid;
        $bdata['bookingmanager'] = $user->username;

        foreach ($bookinginstancedata as $key => $value) {
            $bdata[$key] = $value;
        }

        $booking = $testcase->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = get_class($testcase)::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1.
        while ($number-- > 0) {
            $bodata = [
                'bookingid' => $booking->id,
                'text' => 'option_' . $number,
                'chooseorcreatecourse' => 1, // Connected existing course.
                'courseid' => $courseid,
                'description' => 'Will start tomorrow',
                'optiondateid_0' => "0",
                'daystonotify_0' => "0",
                'coursestarttime_0' => strtotime('+ 5 days', time()),
                'courseendtime_0' => strtotime('+ 10 days', time()),
                'teachersforoption' => $user->username,
            ];

            // If we want to create more than one booking option, we can introduce bodata for each.
            if ($totalnumber > 1) {
                $boinsertdata = array_shift($bookingoptiondata);
            } else {
                $boinsertdata = $bookingoptiondata;
            }
            // Write the custom data before creation.
            foreach ($boinsertdata as $key => $value) {
                $bodata[$key] = $value;
            }
            $option = $plugingenerator->create_option((object)$bodata);
            singleton_service::destroy_booking_option_singleton($option->id);
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Teardown function to make sure no singletons are left.
     *
     * @return void
     *
     */
    public function teardown() {
        // Taskflow.
        \local_taskflow\singleton_service::destroy_instance();
        \local_taskflow\local\actions\targets\types\bookingoption::destroy_instance();
        \local_taskflow\local\actions\targets\types\competency::destroy_instance();
        external_api_base::destroy_instance();
        moodlecourse::destroy_instance();
        standard_assignment::destroy_instance();
        unit_member::destroy_instance();
        rules::destroy_instance();
        unit_rules::destroy_instance();
        unit_relations::destroy_instance();
        cohort::destroy_instance();
        unit::teardown();
        // From booking.
        singleton_service::destroy_instance();
    }
}
