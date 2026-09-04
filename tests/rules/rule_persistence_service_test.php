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

namespace local_taskflow\rules;

use advanced_testcase;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\rules\rule_persistence_service;
use tool_mocktesttime\time_mock;

/**
 * Test the rule_persistence_service.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_taskflow\local\rules\rule_persistence_service
 */
final class rule_persistence_service_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config('organisational_unit_option', 'cohort', 'local_taskflow');
    }

    /**
     * Returns a minimal valid ruledata array.
     *
     * @param string $name
     * @return array
     */
    private function ruledata(string $name = 'Service rule'): array {
        return [
            'unitid' => 99,
            'userid' => 0,
            'rulename' => $name,
            'rulejson' => json_encode(['rulejson' => ['rule' => ['name' => $name]]]),
            'isactive' => 1,
        ];
    }

    /**
     * Given ruledata without an id, when it is persisted,
     * then a new row exists and the rule_created_updated event fires.
     */
    public function test_persist_inserts_rule_and_fires_event(): void {
        global $DB, $USER;

        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $ruleid = (new rule_persistence_service())->persist($this->ruledata(), (int)$USER->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotEmpty($ruleid);

        $record = $DB->get_record('local_taskflow_rules', ['id' => $ruleid]);
        $this->assertEquals('Service rule', $record->rulename);
        $this->assertEquals(99, (int)$record->unitid);
        $this->assertEquals(1, (int)$record->isactive);

        $ruleevents = array_filter($events, fn($event) => $event instanceof rule_created_updated);
        $this->assertCount(1, $ruleevents);
        $event = reset($ruleevents);
        $this->assertEquals($ruleid, $event->objectid);
        $this->assertEquals($USER->id, $event->userid);
    }

    /**
     * Given an existing rule, when ruledata with its id is persisted,
     * then the row is updated instead of inserted.
     */
    public function test_persist_updates_existing_rule(): void {
        global $DB, $USER;

        $this->setAdminUser();

        $service = new rule_persistence_service();
        $ruleid = $service->persist($this->ruledata(), (int)$USER->id);

        $updated = $this->ruledata('Renamed rule');
        $updated['id'] = $ruleid;
        $returnedid = $service->persist($updated, (int)$USER->id);

        $this->assertEquals($ruleid, $returnedid);
        $this->assertCount(1, $DB->get_records('local_taskflow_rules'));
        $this->assertEquals(
            'Renamed rule',
            $DB->get_field('local_taskflow_rules', 'rulename', ['id' => $ruleid])
        );
    }

    /**
     * Given multistep form steps, when build_from_steps is called,
     * then the step 1 form class transforms them into ruledata.
     */
    public function test_build_from_steps_uses_step_one_formclass(): void {
        $mockformclass = new class {
            /**
             * Returns the ruledata for the given steps.
             *
             * @param array $steps
             * @return array
             */
            public function get_data_to_persist(array $steps): array {
                return [
                    'unitid' => $steps[1]['unitid'],
                    'rulename' => $steps[1]['name'],
                    'rulejson' => json_encode(['name' => $steps[1]['name']]),
                    'isactive' => 1,
                ];
            }
        };

        $mockclassname = 'local_taskflow_rule_persistence_mockstep_' . uniqid();
        class_alias(get_class($mockformclass), $mockclassname);

        $steps = [
            1 => [
                'formclass' => $mockclassname,
                'unitid' => 42,
                'name' => 'Built rule',
            ],
        ];

        $ruledata = rule_persistence_service::build_from_steps($steps);

        $this->assertEquals(42, $ruledata['unitid']);
        $this->assertEquals('Built rule', $ruledata['rulename']);
        $this->assertArrayHasKey('rulejson', $ruledata);
    }
}
