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

namespace local_taskflow;

use advanced_testcase;
use local_taskflow\local\rules\rules;

/**
 * Generator create_rule() honours its options and stays backwards compatible.
 *
 * @package    local_taskflow
 * @covers     \local_taskflow_generator::create_rule
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generator_create_rule_test extends advanced_testcase {
    /**
     * Default call: 'Test Rule', active, valid rule document without filters/targets.
     */
    public function test_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');

        $ruleid = $generator->create_rule();
        $row = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertSame('Test Rule', $row->rulename);
        $this->assertSame(1, (int)$row->isactive);
        $this->assertSame(0, (int)$row->unitid);

        $rule = json_decode($row->rulejson, true)['rulejson']['rule'];
        $this->assertSame('Test Rule', $rule['name']);
        $this->assertSame('duration', $rule['duedatetype']);
        $this->assertSame([], $rule['filter']);
        $this->assertCount(1, $rule['actions']);
        $this->assertSame([], $rule['actions'][0]['targets']);
        $this->assertInstanceOf(rules::class, rules::instance($ruleid));
    }

    /**
     * Options are written into the row and the JSON document (RULE_JSON_FORMAT.md shape).
     */
    public function test_options(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $course = $this->getDataGenerator()->create_course();

        $ruleid = $generator->create_rule([
            'unitid' => 42,
            'name' => 'Datenschutz',
            'description' => 'Basics',
            'duedatetype' => 'fixeddate',
            'fixeddate' => 1900000000,
            'extensionperiod' => 86400,
            'activationdelay' => 3600,
            'cyclic' => true,
            'cyclicduration' => 100,
            'inheritance' => 1,
            'recursive' => 1,
            'isactive' => 0,
            'filters' => [
                ['userprofilefield' => 'supervisor', 'operator' => 'not_equals', 'value' => '124'],
                ['filtertype' => 'user_field', 'userfield' => 'lastaccess', 'operator' => 'nowminusdays', 'value' => '30'],
            ],
            'targets' => [
                ['targettype' => 'moodlecourse', 'targetid' => (int)$course->id, 'completebeforenext' => 1],
                ['targettype' => 'competency', 'targetid' => 7],
            ],
            'messages' => [12, ['messageid' => 15]],
            'requests' => ['receiver_allowselfextension' => '0'],
        ]);

        $row = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertSame(42, (int)$row->unitid);
        $this->assertSame('Datenschutz', $row->rulename);
        $this->assertSame(0, (int)$row->isactive);

        $rule = json_decode($row->rulejson, true)['rulejson']['rule'];
        $this->assertSame('Basics', $rule['description']);
        $this->assertSame(0, $rule['enabled']);
        $this->assertSame('fixeddate', $rule['duedatetype']);
        $this->assertSame(1900000000, $rule['fixeddate']);
        $this->assertSame(86400, $rule['extensionperiod']);
        $this->assertSame(3600, $rule['activationdelay']);
        $this->assertSame(1, $rule['cyclicvalidation']);
        $this->assertSame(100, $rule['cyclicduration']);
        $this->assertSame(1, $rule['inheritance']);
        $this->assertSame(1, $rule['recursive']);

        $this->assertCount(2, $rule['filter']);
        $this->assertSame('user_profile_field', $rule['filter'][0]['filtertype']);
        $this->assertSame('not_equals', $rule['filter'][0]['operator']);
        $this->assertSame('role', $rule['filter'][0]['key']);
        $this->assertSame('user_field', $rule['filter'][1]['filtertype']);

        $targets = $rule['actions'][0]['targets'];
        $this->assertCount(2, $targets);
        $this->assertSame((int)$course->id, $targets[0]['targetid']);
        $this->assertSame(1, $targets[0]['completebeforenext']);
        $this->assertSame('enroll', $targets[0]['actiontype']);
        $this->assertSame('competency', $targets[1]['targettype']);
        $this->assertSame(0, $targets[1]['completebeforenext']);

        $this->assertSame([['messageid' => 12], ['messageid' => 15]], $rule['actions'][0]['messages']);
        $this->assertSame(['receiver_allowselfextension' => '0'], $rule['actions'][0]['requests']);
    }
}
