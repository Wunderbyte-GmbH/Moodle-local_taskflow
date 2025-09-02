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
use cache_helper;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\plugininfo\taskflowadapter;
use taskflowadapter_ksw\adapter;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class receive_external_data_supervisor_change_thour_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/../mock/anonymized_data/supervisor_user_data_thour.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'externalsupervisor',
            'externalid',
            'orgunit',
            'contractend',
            'Org1',
            'Org2',
            'Org3',
            'Org4',
            'Org5',
            'Org6',
            'Org7',
        ]);
        $plugingenerator->set_config_values('ksw');
        $this->set_config_values();
    }

    /**
     * Setup the test environment.
     */
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'translator_user_externalsupervisor' => 'externalsupervisor',
            'externalsupervisor' => taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL,
            'translator_user_supervisor' => 'supervisor',
            'supervisor' => taskflowadapter::TRANSLATOR_USER_SUPERVISOR,
            'translator_user_externalid' => 'userID',
            'externalid' => taskflowadapter::TRANSLATOR_USER_EXTERNALID,
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'taskflowadapter_ksw');
        }
        cache_helper::invalidate_by_event('config', ['taskflowadapter_ksw']);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\external_adapter\external_api_base
     * @covers \local_taskflow\local\units\organisational_units\unit
     * @covers \local_taskflow\local\personas\moodle_users\types\moodle_user
     * @covers \local_taskflow\local\personas\unit_members\types\unit_member
     * @covers \local_taskflow\local\personas\unit_members\moodle_unit_member_facade
     * @covers \local_taskflow\local\personas\moodle_users\moodle_user_factory
     * @covers \local_taskflow\local\users_profile\types\thour
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_process\filters\filters_controller
     * @covers \local_taskflow\local\units\unit_hierarchy
     * @covers \local_taskflow\local\supervisor\supervisor
     */
    public function test_external_data_is_loaded(): void {
        global $DB;
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externalidfield = adapter::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
        $externalsupervisoridfield = adapter::return_shortname_for_functionname(
            taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL
        );
        $supervisoridfield = adapter::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $moodleusers = $DB->get_records('user');
        $this->assertCount(5, $moodleusers);

        $hugo = $DB->get_record('user', ['email' => "hugo.maier@ksw.ch"], '*', IGNORE_MISSING);
        $hugoprofile = profile_user_record($hugo->id);

        $marie = $DB->get_record('user', ['email' => "marie.keller@ksw.ch"], '*', IGNORE_MISSING);
        $marieprofile = profile_user_record($marie->id);

        $sophie = $DB->get_record('user', ['email' => "sophie.huber@ksw.ch"], '*', IGNORE_MISSING);
        $sophieprofile = profile_user_record($sophie->id);
        $sophieexternalid = $sophieprofile->{$externalidfield} ?? null;

        // Get sophies external id, update hugo.
        $marieexternalid = $marieprofile->{$externalidfield} ?? null;
        $this->assertNotEmpty($marieexternalid, 'Marie must have an external ID.');

        $hugoprofile->$externalsupervisoridfield = $marieexternalid;
        profile_save_custom_fields($hugo->id, (array)$hugoprofile);
        user_update_user($hugo, false, true);

        $hugomarieprofile = profile_user_record($hugo->id);
        $this->assertNotEmpty($hugomarieprofile->$externalidfield);

        $hugoprofile->$externalsupervisoridfield = $sophieexternalid;
        profile_save_custom_fields($hugo->id, (array)$hugoprofile);
        user_update_user($hugo, false, true);

        $hugosophieprofile = profile_user_record($hugo->id);
        $this->assertNotEmpty($hugosophieprofile->$externalidfield);

        $this->assertNotEquals($hugosophieprofile->$externalsupervisoridfield, $hugomarieprofile->$externalsupervisoridfield);
        $this->assertNotEquals($hugosophieprofile->$supervisoridfield, $hugomarieprofile->$supervisoridfield);
    }
}
