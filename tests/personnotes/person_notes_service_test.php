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

namespace local_taskflow\local\personnotes;

use advanced_testcase;
use context_system;
use local_taskflow\output\personpage;

/**
 * Notes about a person: capability plus team scope.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\personnotes\person_notes_service
 * @covers     \local_taskflow\output\personpage::can_view
 */
final class person_notes_service_test extends advanced_testcase {
    /** @var int Role with the note capabilities but no manager rights. */
    private int $supervisorroleid;

    /**
     * Setup: standard adapter with the supervisor profile field.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('external_api_option', 'standard', 'local_taskflow');
        set_config('organisational_unit_option', 'cohort', 'local_taskflow');
        set_config('supervisor_field', 'supervisor', 'local_taskflow');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $generator->create_custom_profile_fields(['supervisor', 'deputy']);
        set_config('supervisor', 'translator_user_supervisor', 'taskflowadapter_standard');
        set_config('deputy', 'translator_user_deputy', 'taskflowadapter_standard');

        $this->supervisorroleid = create_role('Supervisor', 'supervisor', '');
        $context = context_system::instance();
        $caps = [
            'local/taskflow:issupervisor',
            'local/taskflow:viewpersonnotes',
            'local/taskflow:createpersonnotes',
            'local/taskflow:deletepersonnotes',
        ];
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $this->supervisorroleid, $context->id);
        }
        set_config('personnotesdeletewindow', 15 * MINSECS, 'local_taskflow');
    }

    /**
     * Makes $supervisor the supervisor of $user through the profile field.
     *
     * @param \stdClass $user
     * @param \stdClass $supervisor
     * @return void
     */
    private function set_supervisor(\stdClass $user, \stdClass $supervisor): void {
        global $DB;
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id, 'fieldid' => $fieldid, 'data' => (string)$supervisor->id, 'dataformat' => 0,
        ]);
    }

    /**
     * A supervisor reaches only their team; a manager everybody; the person never sees their own notes.
     */
    public function test_scope_and_capabilities(): void {
        global $DB;
        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, context_system::instance()->id);
        $supervisor = $this->getDataGenerator()->create_user();
        role_assign($this->supervisorroleid, $supervisor->id, context_system::instance()->id);
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->set_supervisor($member, $supervisor);

        // Person page visibility: team for supervisors, everybody for managers, nobody else - not even oneself.
        $this->assertTrue(personpage::can_view((int)$supervisor->id, (int)$member->id));
        $this->assertFalse(personpage::can_view((int)$supervisor->id, (int)$outsider->id));
        $this->assertTrue(personpage::can_view((int)$manager->id, (int)$outsider->id));
        $this->assertFalse(personpage::can_view((int)$member->id, (int)$member->id));
        $this->assertFalse(personpage::can_view((int)$outsider->id, (int)$member->id));

        // Notes.
        $this->assertTrue(person_notes_service::can_create((int)$supervisor->id, (int)$member->id));
        $this->assertFalse(person_notes_service::can_create((int)$supervisor->id, (int)$outsider->id));
        $this->assertTrue(person_notes_service::can_view((int)$manager->id, (int)$member->id));
        $this->assertFalse(person_notes_service::can_view((int)$member->id, (int)$member->id));

        $service = new person_notes_service();
        $noteid = $service->add((int)$member->id, 'Talk about the leadership curriculum.', (int)$supervisor->id);
        $notes = person_notes_service::get_notes((int)$member->id);
        $this->assertCount(1, $notes);
        $this->assertSame('Talk about the leadership curriculum.', $notes[$noteid]->note);
        $this->assertEquals($supervisor->id, $notes[$noteid]->usermodified);

        // Own notes within the window: yes. Others' notes: only with deleteotherspersonnotes (manager).
        $this->assertTrue(person_notes_service::can_delete((int)$supervisor->id, $notes[$noteid]));
        $this->assertTrue(person_notes_service::can_delete((int)$manager->id, $notes[$noteid]));
        $this->assertFalse(person_notes_service::can_delete((int)$member->id, $notes[$noteid]));
        $managernote = $service->add((int)$member->id, 'Written by HR.', (int)$manager->id);
        $managernotes = person_notes_service::get_notes((int)$member->id);
        $this->assertFalse(person_notes_service::can_delete((int)$supervisor->id, $managernotes[$managernote]));

        // Own notes outside the window: no.
        $DB->set_field('local_taskflow_person_notes', 'timecreated', time() - 16 * MINSECS, ['id' => $noteid]);
        $old = person_notes_service::get_notes((int)$member->id)[$noteid];
        $this->assertFalse(person_notes_service::can_delete((int)$supervisor->id, $old));
        $this->assertTrue(person_notes_service::can_delete((int)$manager->id, $old));
        set_config('personnotesdeletewindow', 0, 'local_taskflow');
        $this->assertTrue(person_notes_service::can_delete((int)$supervisor->id, $old));

        $this->expectException(\required_capability_exception::class);
        $service->add((int)$outsider->id, 'Not my team.', (int)$supervisor->id);
    }
}
