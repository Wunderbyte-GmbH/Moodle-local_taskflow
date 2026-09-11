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

namespace local_taskflow\privacy;

use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_taskflow\local\dashboard\person_tabs;
use stdClass;

/**
 * Privacy provider of local_taskflow: data about a person in their user context, actions in the system context.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var stdClass Employee with assignments. */
    private stdClass $employee;

    /** @var stdClass Supervisor who acts on the employee's and the other person's records. */
    private stdClass $supervisor;

    /** @var stdClass Another employee. */
    private stdClass $other;

    /** @var int Rule for everybody. */
    private int $ruleid;

    /** @var int Rule defined for the employee only. */
    private int $personalruleid;

    /** @var int Assignment of the employee. */
    private int $assignmentid;

    /** @var int Assignment of the other employee. */
    private int $otherassignmentid;

    /**
     * Data of three persons in all Taskflow tables.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->employee = $gen->create_user();
        $this->supervisor = $gen->create_user();
        $this->other = $gen->create_user();
        $e = (int)$this->employee->id;
        $s = (int)$this->supervisor->id;
        $o = (int)$this->other->id;
        $now = time();

        $this->ruleid = $DB->insert_record('local_taskflow_rules', [
            'unitid' => 0, 'userid' => 0, 'rulename' => 'Fire safety', 'rulejson' => '{}', 'eventname' => '', 'isactive' => 1,
        ]);
        $this->personalruleid = $DB->insert_record('local_taskflow_rules', [
            'unitid' => 0,
            'userid' => $e,
            'rulename' => 'Leadership curriculum',
            'rulejson' => json_encode(['rulejson' => ['rule' => ['userid' => $e, 'name' => 'Leadership curriculum']]]),
            'eventname' => '',
            'isactive' => 1,
        ]);
        $assignment = [
            'targets' => '[]', 'messages' => '[]', 'ruleid' => $this->ruleid, 'unitid' => 0, 'active' => 1, 'status' => 0,
            'duedate' => $now + WEEKSECS, 'assigneddate' => $now, 'usermodified' => $s, 'timecreated' => $now,
            'timemodified' => $now, 'keepchanges' => 0, 'overduecounter' => 0, 'prolongedcounter' => 0,
        ];
        $this->assignmentid = $DB->insert_record('local_taskflow_assignment', $assignment + ['userid' => $e]);
        $this->otherassignmentid = $DB->insert_record('local_taskflow_assignment', $assignment + ['userid' => $o]);

        foreach ([[$this->assignmentid, $e], [$this->otherassignmentid, $o]] as [$aid, $uid]) {
            $DB->insert_record('local_taskflow_history', [
                'assignmentid' => $aid, 'userid' => $uid, 'type' => 'manual_change',
                'data' => json_encode(['action' => 'updated', 'data' => ['usermodified' => $s, 'status' => 5]]),
                'timecreated' => $now, 'createdby' => $s, 'annotation' => 'Checked',
            ]);
            $DB->insert_record('local_taskflow_requests', [
                'request' => 1, 'userid' => $uid, 'assignmentid' => $aid, 'status' => 1, 'usermodified' => $s,
                'timemodified' => $now, 'timecreated' => $now, 'treated' => 1, 'forhr' => 0, 'comment' => 'Not relevant',
            ]);
            $DB->insert_record('local_taskflow_int_com', [
                'assignmentid' => $aid, 'message' => 'Please finish soon', 'usermodified' => $s,
                'timemodified' => $now, 'timecreated' => $now,
            ]);
            $DB->insert_record('local_taskflow_rule_users', [
                'ruleid' => $this->ruleid, 'userid' => $uid, 'annotation' => 'Succession', 'usermodified' => $s,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('local_taskflow_person_notes', [
                'userid' => $uid, 'note' => 'Talked about the curriculum', 'noteformat' => FORMAT_HTML,
                'usermodified' => $s, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('local_taskflow_unit_members', [
                'unitid' => 5, 'userid' => $uid, 'active' => 1, 'timeadded' => $now, 'timemodified' => $now, 'usermodified' => $s,
            ]);
        }
        $DB->insert_record('local_taskflow_int_com', [
            'assignmentid' => $this->assignmentid, 'message' => 'Done tomorrow', 'usermodified' => $e,
            'timemodified' => $now, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_taskflow_last_seen', [
            'userid' => $e, 'assignmentid' => $this->assignmentid, 'lastseen' => $now,
        ]);
        $DB->insert_record('local_taskflow_last_seen', [
            'userid' => $s, 'assignmentid' => $this->otherassignmentid, 'lastseen' => $now,
        ]);
        $DB->insert_record('local_taskflow_sent_messages', [
            'messageid' => 0, 'ruleid' => $this->ruleid, 'userid' => $e, 'timesent' => $now,
        ]);
        $DB->insert_record('local_taskflow_assgin_comp', [
            'assignmentid' => $this->assignmentid, 'userid' => $e, 'competencyid' => 3, 'competencyevidenceid' => 4,
            'status' => 'pending', 'timecreated' => $now, 'timemodified' => $now, 'validationondate' => 0,
        ]);
        $DB->insert_record('local_taskflow_units', [
            'name' => 'Sales', 'description' => '', 'tissid' => 0, 'criteria' => '', 'timecreated' => $now,
            'timemodified' => $now, 'usermodified' => $s,
        ]);
        set_user_preference(person_tabs::PREFERENCE, $e . ',' . $o, $this->supervisor);
    }

    /**
     * All tables, the preference and the links are described.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_taskflow'))->get_collection();
        $names = array_map(fn($item) => $item->get_name(), $items);
        $expected = [
            'local_taskflow_assignment', 'local_taskflow_person_notes', 'local_taskflow_int_com', person_tabs::PREFERENCE,
            'core_message', 'taskflowadapter',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * Data about a person lives in their user context, their actions in the system context.
     */
    public function test_get_contexts_for_userid(): void {
        $employee = provider::get_contexts_for_userid((int)$this->employee->id)->get_contextids();
        $this->assertEqualsCanonicalizing(
            [context_user::instance($this->employee->id)->id, context_system::instance()->id],
            array_map('intval', $employee)
        );
        $this->assertEquals(
            [context_user::instance($this->other->id)->id],
            array_map('intval', provider::get_contexts_for_userid((int)$this->other->id)->get_contextids())
        );
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int)$nobody->id)->get_contextids());
    }

    /**
     * The export contains the assignments, the chat and the notes about the person, and the actions separately.
     */
    public function test_export_user_data(): void {
        $usercontext = context_user::instance($this->employee->id);
        $this->export_context_data_for_user((int)$this->employee->id, $usercontext, 'local_taskflow');
        $writer = writer::with_context($usercontext);
        $this->assertTrue($writer->has_any_data());
        $assignments = $writer->get_data([get_string('privacy:export:assignments', 'local_taskflow')]);
        $this->assertSame('Fire safety', $assignments->assignments[0]['rule']);
        $chat = $writer->get_data([get_string('privacy:export:chat', 'local_taskflow')]);
        $this->assertCount(2, $chat->chat);
        $notes = $writer->get_data([get_string('privacy:export:notes', 'local_taskflow')]);
        $this->assertSame('Talked about the curriculum', $notes->notes[0]['note']);

        $systemcontext = context_system::instance();
        $this->export_context_data_for_user((int)$this->supervisor->id, $systemcontext, 'local_taskflow');
        $actions = writer::with_context($systemcontext)->get_data([get_string('privacy:export:actions', 'local_taskflow')]);
        $this->assertCount(2, $actions->notes);
        $this->assertCount(2, $actions->requests);
    }

    /**
     * The open person tabs are exported.
     */
    public function test_export_user_preferences(): void {
        provider::export_user_preferences((int)$this->supervisor->id);
        $prefs = writer::with_context(context_system::instance())->get_user_preferences('local_taskflow');
        $this->assertSame($this->employee->id . ',' . $this->other->id, $prefs->{person_tabs::PREFERENCE}->value);
    }

    /**
     * Deleting a user context removes the data about that person only.
     */
    public function test_delete_data_for_all_users_in_user_context(): void {
        global $DB;
        $e = (int)$this->employee->id;
        provider::delete_data_for_all_users_in_context(context_user::instance($e));

        $tables = [
            'local_taskflow_assignment', 'local_taskflow_history', 'local_taskflow_requests', 'local_taskflow_person_notes',
            'local_taskflow_rule_users', 'local_taskflow_unit_members', 'local_taskflow_last_seen',
            'local_taskflow_sent_messages', 'local_taskflow_assgin_comp',
        ];
        foreach ($tables as $table) {
            $this->assertFalse($DB->record_exists($table, ['userid' => $e]), $table);
        }
        $this->assertFalse($DB->record_exists('local_taskflow_int_com', ['assignmentid' => $this->assignmentid]));
        $rule = $DB->get_record('local_taskflow_rules', ['id' => $this->personalruleid]);
        $this->assertSame(0, (int)$rule->userid);
        $this->assertSame(0, json_decode($rule->rulejson, true)['rulejson']['rule']['userid']);
        $this->assertTrue($DB->record_exists('local_taskflow_assignment', ['userid' => $this->other->id]));
        $this->assertTrue($DB->record_exists('local_taskflow_person_notes', ['userid' => $this->other->id]));
    }

    /**
     * Deleting the system context anonymises every acting user and keeps the records.
     */
    public function test_delete_data_for_all_users_in_system_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context(context_system::instance());
        $this->assertFalse($DB->record_exists_select('local_taskflow_person_notes', 'usermodified > 0'));
        $this->assertFalse($DB->record_exists_select('local_taskflow_history', 'createdby > 0'));
        $this->assertSame(2, $DB->count_records('local_taskflow_person_notes'));
        $this->assertSame(2, $DB->count_records('local_taskflow_assignment'));
    }

    /**
     * Deleting the supervisor removes their own data and anonymises what they did on other people's records.
     */
    public function test_delete_data_for_user_anonymises_actions(): void {
        global $DB;
        $s = (int)$this->supervisor->id;
        $contexts = [context_user::instance($s)->id, context_system::instance()->id];
        provider::delete_data_for_user(new approved_contextlist($this->supervisor, 'local_taskflow', $contexts));

        $this->assertFalse($DB->record_exists('local_taskflow_last_seen', ['userid' => $s]));
        $tables = [
            'local_taskflow_person_notes', 'local_taskflow_requests', 'local_taskflow_rule_users', 'local_taskflow_int_com',
            'local_taskflow_assignment', 'local_taskflow_units', 'local_taskflow_unit_members',
        ];
        foreach ($tables as $table) {
            $this->assertFalse($DB->record_exists($table, ['usermodified' => $s]), $table);
        }
        $this->assertSame(2, $DB->count_records('local_taskflow_person_notes'));
        $this->assertSame(2, $DB->count_records('local_taskflow_requests'));
        $this->assertSame(2, $DB->count_records('local_taskflow_rule_users'));
        foreach ($DB->get_records('local_taskflow_history') as $entry) {
            $this->assertSame(0, (int)$entry->createdby);
            $this->assertSame(0, json_decode($entry->data, true)['data']['usermodified']);
        }
        $this->assertSame('Done tomorrow', $DB->get_field(
            'local_taskflow_int_com',
            'message',
            ['usermodified' => $this->employee->id]
        ));
    }

    /**
     * Users per context: the person in their user context, the acting users in the system context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist(context_user::instance($this->employee->id), 'local_taskflow');
        provider::get_users_in_context($userlist);
        $this->assertEquals([(int)$this->employee->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist(context_system::instance(), 'local_taskflow');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int)$this->supervisor->id, (int)$this->employee->id],
            array_map('intval', $userlist->get_userids())
        );
    }

    /**
     * Deleting a list of users touches only those users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $system = context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'local_taskflow', [$this->supervisor->id]));
        $this->assertFalse($DB->record_exists('local_taskflow_int_com', ['usermodified' => $this->supervisor->id]));
        $this->assertTrue($DB->record_exists('local_taskflow_int_com', ['usermodified' => $this->employee->id]));

        $usercontext = context_user::instance($this->other->id);
        provider::delete_data_for_users(new approved_userlist($usercontext, 'local_taskflow', [$this->employee->id]));
        $this->assertTrue($DB->record_exists('local_taskflow_assignment', ['userid' => $this->other->id]));
        provider::delete_data_for_users(new approved_userlist($usercontext, 'local_taskflow', [$this->other->id]));
        $this->assertFalse($DB->record_exists('local_taskflow_assignment', ['userid' => $this->other->id]));
        $this->assertTrue($DB->record_exists('local_taskflow_assignment', ['userid' => $this->employee->id]));
    }
}
