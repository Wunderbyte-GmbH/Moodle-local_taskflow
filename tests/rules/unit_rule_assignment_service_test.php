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

namespace local_taskflow\local\rules;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\units\unit_hierarchy;
use local_taskflow\local\units\unit_relations;

/**
 * Assigning rules to units from the organisation page, with and without inheritance.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\rules\unit_rule_assignment_service
 */
final class unit_rule_assignment_service_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('organisational_unit_option', 'cohort', 'local_taskflow');
        set_config('external_api_option', 'standard', 'local_taskflow');
    }

    /**
     * Creates a unit member row.
     *
     * @param int $unitid
     * @param int $userid
     * @return void
     */
    private function add_member(int $unitid, int $userid): void {
        global $DB;
        $DB->insert_record('local_taskflow_unit_members', (object)[
            'unitid' => $unitid, 'userid' => $userid, 'active' => 1, 'timeadded' => time(),
        ]);
    }

    /**
     * Assigning a curriculum to a unit with inheritance reaches the child unit; removing it drops both out.
     */
    public function test_assign_with_inheritance_and_unassign(): void {
        global $DB;

        $parent = $this->getDataGenerator()->create_cohort();
        $child = $this->getDataGenerator()->create_cohort();
        unit_relations::create_or_update_relations($child->id, $parent->id);
        unit_hierarchy::invalidate_cache();

        $parentmember = $this->getDataGenerator()->create_user();
        $childmember = $this->getDataGenerator()->create_user();
        $this->add_member((int)$parent->id, (int)$parentmember->id);
        $this->add_member((int)$child->id, (int)$childmember->id);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule([
            'name' => 'Curriculum',
            'targets' => [['targettype' => 'moodlecourse', 'targetid' => $course->id, 'targetname' => $course->fullname]],
        ]);
        $this->assertSame([], unit_rule_assignment_service::get_unit_ids_for_rule($ruleid));

        $service = new unit_rule_assignment_service();
        $service->assign($ruleid, (int)$parent->id, true, 2);

        $this->assertSame([(int)$parent->id], unit_rule_assignment_service::get_unit_ids_for_rule($ruleid));
        $rules = unit_rule_assignment_service::get_rules_for_unit((int)$parent->id);
        $this->assertArrayHasKey($ruleid, $rules);
        $this->assertTrue($rules[$ruleid]->inheritance);
        $inherited = unit_rule_assignment_service::get_inherited_rules_for_unit((int)$child->id);
        $this->assertArrayHasKey($ruleid, $inherited);
        $this->assertSame((int)$parent->id, $inherited[$ruleid]->inheritedfrom);

        $assigned = assignment_status_facade::get_status_identifier('assigned');
        foreach ([$parentmember, $childmember] as $user) {
            $assignment = $DB->get_record(
                'local_taskflow_assignment',
                ['userid' => $user->id, 'ruleid' => $ruleid],
                '*',
                MUST_EXIST
            );
            $this->assertEquals($assigned, $assignment->status);
            $this->assertEquals(1, $assignment->active);
        }

        $service->unassign($ruleid, (int)$parent->id, 2);
        $this->assertSame([], unit_rule_assignment_service::get_unit_ids_for_rule($ruleid));
        $droppedout = assignment_status_facade::get_status_identifier('droppedout');
        foreach ([$parentmember, $childmember] as $user) {
            $assignment = $DB->get_record(
                'local_taskflow_assignment',
                ['userid' => $user->id, 'ruleid' => $ruleid],
                '*',
                MUST_EXIST
            );
            $this->assertEquals($droppedout, $assignment->status);
        }
    }

    /**
     * Without inheritance only the direct members are addressed; assignable rules exclude bound rules.
     */
    public function test_assign_without_inheritance(): void {
        global $DB;

        $parent = $this->getDataGenerator()->create_cohort();
        $child = $this->getDataGenerator()->create_cohort();
        unit_relations::create_or_update_relations($child->id, $parent->id);
        unit_hierarchy::invalidate_cache();
        $parentmember = $this->getDataGenerator()->create_user();
        $childmember = $this->getDataGenerator()->create_user();
        $this->add_member((int)$parent->id, (int)$parentmember->id);
        $this->add_member((int)$child->id, (int)$childmember->id);

        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $ruleid = $generator->create_rule(['name' => 'Direct only']);
        $this->assertArrayHasKey($ruleid, unit_rule_assignment_service::get_assignable_rules());

        (new unit_rule_assignment_service())->assign($ruleid, (int)$parent->id, false, 2);

        $this->assertArrayNotHasKey($ruleid, unit_rule_assignment_service::get_assignable_rules());
        $this->assertTrue($DB->record_exists('local_taskflow_assignment', ['userid' => $parentmember->id, 'ruleid' => $ruleid]));
        $this->assertFalse($DB->record_exists('local_taskflow_assignment', ['userid' => $childmember->id, 'ruleid' => $ruleid]));
        $this->assertSame([], unit_rule_assignment_service::get_inherited_rules_for_unit((int)$child->id));
    }
}
