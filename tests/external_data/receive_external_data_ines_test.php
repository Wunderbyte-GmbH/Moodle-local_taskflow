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

namespace local_taskflow\external_data;

use advanced_testcase;
use DateTime;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receive_external_data_ines_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/user_data_ines.json');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $profilefields = $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'externalid',
            'units',
            'organisation',
            'targetgroup',
            'longleave',
            'contractend',
            'contractstart',
        ]);

        $plugingenerator->set_config_values('tuines');
        set_config("tissid_info", 'tissid_info', 'taskflowadapter_tuines');
    }


    /**
     * Example test: Ensure external data is loaded.
     * @covers \taskflowadapter_tuines\taskflowadapter_tuines
     * @covers \taskflowadapter_tuines\adapter
     * @covers \local_taskflow\local\external_adapter\external_api_base
     * @covers \local_taskflow\local\units\organisational_units\unit
     * @covers \local_taskflow\local\personas\moodle_users\types\moodle_user
     * @covers \local_taskflow\local\personas\unit_members\types\unit_member
     * @covers \local_taskflow\local\personas\unit_members\moodle_unit_member_facade
     * @covers \local_taskflow\local\personas\moodle_users\moodle_user_factory
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_process\filters\filters_controller
     * @covers \local_taskflow\local\supervisor\supervisor
     * @covers \local_taskflow\local\eventhandlers\unit_member_updated
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();

        $date = new DateTime();
        $date->modify('+1 year');
        $formatted = $date->format('Y-m-d');
        foreach ($externaldata->persons as &$person) {
            if ($person->firstName != 'Berta') {
                $person->contractEnd = $formatted;
            }
        }

        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $this->assertCount(4, $DB->get_records('cohort'));

        $createduser = \core_user::get_user_by_email('david.drunter@tuwien.ac.at');
        $this->assertNotEmpty($createduser, 'Der User sollte erstellt worden sein.');
        $profile = profile_user_record($createduser->id, false);

        $endinfo = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_CONTRACTEND);

        $this->assertNotEmpty($profile->{$endinfo});

        $unitmemebers = $DB->get_records('local_taskflow_unit_members');
        $this->assertCount(16, $unitmemebers);

        $cohortmemebers = $DB->get_records('cohort_members');
        $this->assertCount(16, $cohortmemebers);

        // Fake data.
        global $DB;
        $user = $DB->get_record('user', ['firstname' => 'Berta']);
        $rule = $DB->insert_record(
            'local_taskflow_rules',
            [
                'unitid' => '',
                'userid' => $user->id,
                'rulename' => 'BertaBoss',
                'rulejson' => '{}',
                'isactive' => '1',
                ]
        );
        $DB->insert_record(
            'local_taskflow_assignment',
            (object)[
                'userid' => $user->id,
                'ruleid' => $rule,
                'status' => 0,
                'active' => 1,
            ]
        );
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/missing_user_data_ines.json');
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $apidatamanager->process_incoming_data();
        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertEquals($assignment->status, assignment_status_facade::get_status_identifier('droppedout'));
        }

        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/user_data_ines.json');
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $apidatamanager->process_incoming_data();
        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertNotEquals($assignment->status, assignment_status_facade::get_status_identifier('droppedout'));
        }
    }
}
