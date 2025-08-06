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
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use advanced_testcase;
use context_system;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editassignment_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        set_config(
            'organisational_unit_option',
            'cohort',
            'local_taskflow'
        );
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\output\editassignment
     * @covers \local_taskflow\form\editassignment
     * @covers \local_taskflow\output\history
     * @covers \local_taskflow\local\assignments\assignment
     */
    public function test_export_for_template_returns_constructor_data(): void {
        global $DB, $PAGE;

        // Create a dummy user.
        $user = $this->getDataGenerator()->create_user();

        $rule = (object)[
            'rulename' => 'Rule 1',
            'rulejson' => json_encode([
                'rulejson' => [
                    'rule' => [
                        'name' => 'Test rule name',
                        'description' => 'Some description',
                    ],
                ],
            ]),
            'unitid' => 1,
            'userid' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $rule->id = $DB->insert_record('local_taskflow_rules', $rule);

        // 3. Insert assignment record.
        $assignment = (object)[
            'userid' => $user->id,
            'ruleid' => $rule->id,
            'unitid' => $rule->unitid,
            'messages' => '',
            'targets' => '[{"targettype":"bookingoption","targetid":"2","sortorder":2,"targetname":"pf2346 -
            KONDITIONSTRAINING UND SPIELE", "actiontype":"enroll","completionstatus":0,"completebeforenext":false},
            {"targettype":"bookingoption","targetid":"2", "sortorder":2,"targetname":"pf2346 - KONDITIONSTRAINING
            UND SPIELE","actiontype":"enroll","completionstatus":0, "completebeforenext":false,
            "completionstatus":1}]',
            'assigneddate' => time(),
            'duedate' => time() + 3600,
            'status' => 1,
            'active' => 1,
            'usermodified' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $assignment->id = $DB->insert_record('local_taskflow_assignment', $assignment);
        $renderable = new editassignment(['id' => $assignment->id]);
        $data = $renderable->export_for_template($this->get_renderer());
        $this->assertIsArray($data);
        $this->assertEquals($assignment->id, $data['id']);
        $this->assertNotEmpty($data['assignmentdata']);
    }

    /**
     * Example test: Ensure external data is loaded.
     */
    protected function get_renderer() {
        global $PAGE;
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url('/local/taskflow/tests');
        return $PAGE->get_renderer('local_taskflow');
    }
}
