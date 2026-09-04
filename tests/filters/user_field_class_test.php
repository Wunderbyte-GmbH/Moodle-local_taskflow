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
use local_taskflow\local\filters\filter_factory;
use local_taskflow\local\filters\types\user_field;

/**
 * Runtime evaluation of the user_field filter (firstaccess / lastaccess).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\filters\types\user_field
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_field_class_test extends advanced_testcase {
    /**
     * The factory resolves the type and the operators behave like user_profile_field.
     */
    public function test_is_valid(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $lastaccess = time() - 10 * DAYSECS;
        $DB->set_field('user', 'lastaccess', $lastaccess, ['id' => $user->id]);
        $DB->set_field('user', 'firstaccess', 0, ['id' => $user->id]);

        $factory = new filter_factory();
        $filter = $factory->instance((object)[
            'filtertype' => 'user_field', 'userfield' => 'firstaccess', 'operator' => 'equals', 'value' => '0', 'key' => 'role',
        ]);
        $this->assertInstanceOf(user_field::class, $filter);
        $this->assertTrue($filter->is_valid([], (int)$user->id));

        $checks = [
            [['userfield' => 'firstaccess', 'operator' => 'not_equals', 'value' => '0'], false],
            [['userfield' => 'lastaccess', 'operator' => 'nowminusdays', 'value' => '5'], true],
            [['userfield' => 'lastaccess', 'operator' => 'nowminusdays', 'value' => '20'], false],
            [['userfield' => 'lastaccess', 'operator' => 'since', 'date' => $lastaccess - 1], true],
            [['userfield' => 'lastaccess', 'operator' => 'since', 'date' => $lastaccess + 1], false],
            [['userfield' => 'lastaccess', 'operator' => 'before', 'date' => $lastaccess + 1], true],
            [['userfield' => 'lastaccess', 'operator' => 'bigger', 'value' => '1'], false],
            [['userfield' => 'password', 'operator' => 'equals', 'value' => ''], false],
        ];
        foreach ($checks as [$data, $expected]) {
            $instance = new user_field((object)($data + ['filtertype' => 'user_field', 'key' => 'role']));
            $this->assertSame($expected, $instance->is_valid([], (int)$user->id), json_encode($data));
        }

        // Missing user never matches.
        $instance = new user_field((object)['userfield' => 'lastaccess', 'operator' => 'equals', 'value' => '0']);
        $this->assertFalse($instance->is_valid([], 9999999));
    }
}
