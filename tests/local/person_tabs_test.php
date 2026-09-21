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

namespace local_taskflow\local\dashboard;

use advanced_testcase;
use context_system;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Person tabs of the dashboard: stored per viewer, limited, and always within the person page scope.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\dashboard\person_tabs
 */
final class person_tabs_test extends advanced_testcase {
    /**
     * Managers open any person, never themselves; closing works per tab and for all tabs.
     */
    public function test_open_and_close_as_manager(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $anna = $this->getDataGenerator()->create_user();
        $ben = $this->getDataGenerator()->create_user();

        $this->assertTrue(person_tabs::open((int)$admin->id, (int)$anna->id));
        $this->assertTrue(person_tabs::open((int)$admin->id, (int)$ben->id));
        $this->assertTrue(person_tabs::open((int)$admin->id, (int)$anna->id));
        $this->assertFalse(person_tabs::open((int)$admin->id, (int)$admin->id));
        $this->assertSame([(int)$anna->id, (int)$ben->id], person_tabs::get((int)$admin->id));

        person_tabs::close((int)$admin->id, (int)$anna->id);
        $this->assertSame([(int)$ben->id], person_tabs::get((int)$admin->id));

        person_tabs::close_all((int)$admin->id);
        $this->assertSame([], person_tabs::get((int)$admin->id));
    }

    /**
     * Users without person page scope cannot open anybody, and the tab list is per viewer.
     */
    public function test_scope_and_per_viewer_storage(): void {
        $this->resetAfterTest();
        $plain = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->assertFalse(person_tabs::open((int)$plain->id, (int)$other->id));
        $this->assertSame([], person_tabs::get((int)$plain->id));

        $admin = get_admin();
        person_tabs::open((int)$admin->id, (int)$other->id);
        $this->assertSame([], person_tabs::get((int)$plain->id));

        // A stored id the viewer may not see (e.g. written by hand) is never returned.
        set_user_preference(person_tabs::PREFERENCE, (string)$other->id, $plain);
        $this->assertSame([], person_tabs::get((int)$plain->id));
    }

    /**
     * At most MAX_TABS persons stay open.
     */
    public function test_limit(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        for ($i = 0; $i < person_tabs::MAX_TABS; $i++) {
            $this->assertTrue(person_tabs::open((int)$admin->id, (int)$this->getDataGenerator()->create_user()->id));
        }
        $this->assertFalse(person_tabs::open((int)$admin->id, (int)$this->getDataGenerator()->create_user()->id));
        $this->assertCount(person_tabs::MAX_TABS, person_tabs::get((int)$admin->id));
    }

    /**
     * Supervisors open their whole team at once; a person who leaves the team disappears from the tabs.
     */
    public function test_open_team_and_scope_change(): void {
        global $DB;
        $this->resetAfterTest();
        $fieldid = $this->configure_supervisor_field();
        $supervisor = $this->getDataGenerator()->create_user();
        $this->assign_supervisor_capability((int)$supervisor->id);
        $zoe = $this->getDataGenerator()->create_user([
            'firstname' => 'Zoe',
            'lastname' => 'Zeller',
            'profile_field_supervisor' => (string)$supervisor->id,
        ]);
        $adam = $this->getDataGenerator()->create_user([
            'firstname' => 'Adam',
            'lastname' => 'Adler',
            'profile_field_supervisor' => (string)$supervisor->id,
        ]);
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertFalse(person_tabs::open((int)$supervisor->id, (int)$stranger->id));
        $result = person_tabs::open_team((int)$supervisor->id);
        $this->assertSame(['open' => 2, 'skipped' => 0], $result);
        $this->assertSame([(int)$adam->id, (int)$zoe->id], person_tabs::get((int)$supervisor->id));

        $DB->set_field('user_info_data', 'data', '0', ['userid' => $zoe->id, 'fieldid' => $fieldid]);
        $this->assertSame([(int)$adam->id], person_tabs::get((int)$supervisor->id));
    }

    /**
     * Deleted persons are dropped from the tabs.
     */
    public function test_deleted_person_is_dropped(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $anna = $this->getDataGenerator()->create_user();
        person_tabs::open((int)$admin->id, (int)$anna->id);
        delete_user($anna);
        $this->assertSame([], person_tabs::get((int)$admin->id));
    }

    /**
     * Profile field and adapter mapping used by supervisor::get_visible_subordinate_ids().
     *
     * @return int Id of the supervisor profile field.
     */
    private function configure_supervisor_field(): int {
        set_config('external_api_option', 'standard', 'local_taskflow');
        set_config('supervisor_field', 'supervisor', 'local_taskflow');
        set_config(taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'supervisor', 'taskflowadapter_standard');
        set_config('supervisor', taskflowadapter::TRANSLATOR_USER_SUPERVISOR, 'taskflowadapter_standard');
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'supervisor',
            'name' => 'supervisor',
        ]);
        return (int)$field->id;
    }

    /**
     * Gives a user the supervisor capability in the system context.
     *
     * @param int $userid
     * @return void
     */
    private function assign_supervisor_capability(int $userid): void {
        $contextid = context_system::instance()->id;
        $roleid = create_role('Tabs supervisor ' . $userid, 'tabssupervisor' . $userid, 'Tabs supervisor');
        assign_capability('local/taskflow:issupervisor', CAP_ALLOW, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);
    }
}
