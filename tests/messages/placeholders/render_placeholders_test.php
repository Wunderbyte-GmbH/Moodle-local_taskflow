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

namespace local_taskflow\messages\placeholders;

use advanced_testcase;
use local_taskflow\local\messages\messages_factory;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class render_placeholders_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\messages\types\standard
     * @covers \local_taskflow\local\actions\targets\targets_base
     * @covers \local_taskflow\local\actions\targets\targets_factory
     * @covers \local_taskflow\local\actions\targets\types\bookingoption
     * @covers \local_taskflow\local\actions\targets\types\competency
     * @covers \local_taskflow\local\actions\targets\types\moodlecourse
     */
    public function test_render_message(): void {
        global $DB;
        $userid = $this->set_db_user();
        $courseids = $this->set_courses_db();
        $competencyid = $this->set_competency_db();
        $bookingoptionid = $this->set_bookingoption_db();
        $message = $this->set_message_db();
        $ruleid = $this->set_rule_db(
            $courseids,
            $message->id,
            $competencyid,
            $bookingoptionid
        );

        $message->messagetype = $message->class;
        $message->messageid = $message->id;
        $messageoinstance = messages_factory::instance($message, $userid, $ruleid);
        $messageoinstance->send_and_save_message();
        $this->assertNotEmpty(1);
        $sentmsg = $DB->get_records('local_taskflow_sent_messages');
        $this->assertCount(1, $sentmsg);
    }

    /**
     * Setup the test environment.
     */
    protected function set_bookingoption_db(): int {
        global $DB;
        $record = new stdClass();
        $record->bookingid = 12;
        $record->text = 'Testing booking option';
        $record->enrolmentstatus = 1;
        $record->description = 'Testing booking option Testing booking option';
        $record->descriptionformat = 0;
        $record->limitanswers = 1;
        if ($DB->get_manager()->table_exists('booking_options')) {
            return $DB->insert_record('booking_options', $record);
        }
        return 0;
    }

    /**
     * Setup the test environment.
     */
    protected function set_competency_db(): int {
        global $DB, $USER;

        $record = new stdClass();
        $record->shortname = 'brute_competency';
        $record->idnumber = 'brute001';
        $record->description = 'No API used here.';
        $record->descriptionformat = FORMAT_HTML;
        $record->competencyframeworkid = 1;
        $record->parentid = 0;
        $record->sortorder = 0;
        $record->path = 'testing_purpose';
        $record->ruleoutcome = 0;
        $record->ruleconfig = null;
        $record->ruletype = null;
        $record->scaleid = 1;
        $record->scaleconfiguration = '{"default":1,"proficient":2}';
        $record->timecreated = time();
        $record->timemodified = time();
        $record->usermodified = $USER->id;

        return $DB->insert_record('competency', $record);
    }

    /**
     * Setup the test environment.
     */
    protected function set_db_user(): int {
        $user = [
            'auth' => 'manual',
            'confirmed' => 1,
            'username' => 'testuser9999',
            'password' => 'P@ssword1',
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'testuser9999@example.com',
            'mnethostid' => 1,
        ];
        require_once(__DIR__ . '/../../../../../user/lib.php');
        $newuserid = user_create_user((object)$user, false, false);
        return $newuserid;
    }

    /**
     * Setup the test environment.
     */
    protected function set_courses_db(): array {
        global $DB;
        $courses = json_decode(file_get_contents(__DIR__ . '/../../mock/courses/courses.json'));
        $courseids = [];
        foreach ($courses as $course) {
            $newcourse = create_course((object)$course);
            $courseids[] = $newcourse->id;
        }
        return $courseids;
    }

    /**
     * Setup the test environment.
     * @return stdClass
     */
    protected function set_message_db(): stdClass {
        global $DB;
        $message = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/message.json'));
        $id = $DB->insert_record('local_taskflow_messages', $message);
        $message->id = $id;
        return $message;
    }

    /**
     * Setup the test environment.
     * @param array $courses
     * @param int $messageid
     * @param int $competencyid
     * @param int $bookingoptionid
     * @return int
     */
    protected function set_rule_db($courses, $messageid, $competencyid, $bookingoptionid): int {
        global $DB;
        $rulejson = json_decode(file_get_contents(__DIR__ . '/../../mock/rules/taskflow_rule_template.json'));

        foreach ($rulejson->rulejson->rule->actions as $actions) {
            $actions->messages = self::change_message_ids($actions->messages, $messageid);
        }

        $rulejson = json_encode($rulejson);
        $rulejson = str_replace("{COMPETENCYID}", $competencyid, $rulejson);
        $rulejson = str_replace("{BOOKINGOPTIONID}", $bookingoptionid, $rulejson);
        $randomkey = array_rand($courses);
        $rulejson = str_replace("{MOODLECOURSEID}", $courses[$randomkey], $rulejson);

        $rule = [
            'unitid' => 1,
            'rulename' => 'Testing rule',
            'rulejson' => $rulejson,
            'eventname' => 'Testing event',
            'isactive' => 1,
        ];
        return $DB->insert_record('local_taskflow_rules', $rule);
    }

    /**
     * Setup the test environment.
     * @param array $messages
     * @param int $messageid
     */
    protected function change_message_ids(&$messages, $messageid): array {
        foreach ($messages as $message) {
            $message->messageid = $messageid;
        }
        return $messages;
    }

    /**
     * Setup the test environment.
     * @param array $targets
     * @param array $courseids
     */
    protected function change_target_ids($targets, $courseids): array {
        foreach ($targets as $target) {
            $randomkey = array_rand($courseids);
            $target->targetid = $courseids[$randomkey];
        }
        return $targets;
    }
}
