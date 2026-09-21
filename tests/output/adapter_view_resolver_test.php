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

namespace local_taskflow\output;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use moodle_url;

/**
 * The adapter view resolver falls back to the core views and honours adapter overrides.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\output\adapter_view_resolver
 * @covers     \taskflowadapter_standard\output\singleassignment
 * @covers     \local_taskflow\output\myoverview
 */
final class adapter_view_resolver_test extends advanced_testcase {
    /**
     * Without an adapter override the core view classes and templates are used.
     */
    public function test_fallback_to_core_views(): void {
        $this->resetAfterTest();
        set_config('external_api_option', 'standard', 'local_taskflow');

        $this->assertSame(personpage::class, adapter_view_resolver::resolve_class('personpage'));
        $this->assertSame(unitspage::class, adapter_view_resolver::resolve_class('unitspage'));

        $user = $this->getDataGenerator()->create_user();
        $view = adapter_view_resolver::instance('personpage', [$user, false, new moodle_url('/local/taskflow/person.php')]);
        $this->assertInstanceOf(personpage::class, $view);
        $this->assertSame('local_taskflow/personpage', $view->get_template());

        $view = adapter_view_resolver::instance('unitspage', [false, 0, new moodle_url('/local/taskflow/units.php')]);
        $this->assertInstanceOf(unitspage::class, $view);
        $this->assertSame('local_taskflow/unitspage', $view->get_template());
    }

    /**
     * Only the Standard adapter overrides the assignment detail page; KSW and TU Wien keep the core view.
     */
    public function test_singleassignment_override_only_for_standard(): void {
        $this->resetAfterTest();

        set_config('external_api_option', 'standard', 'local_taskflow');
        $this->assertSame(
            \taskflowadapter_standard\output\singleassignment::class,
            adapter_view_resolver::resolve_class('singleassignment')
        );

        foreach (['ksw', 'tuines'] as $adapter) {
            set_config('external_api_option', $adapter, 'local_taskflow');
            $this->assertSame(singleassignment::class, adapter_view_resolver::resolve_class('singleassignment'));
        }
    }

    /**
     * The status badge of the Standard adapter uses the table wording, counters included.
     */
    public function test_standard_status_display(): void {
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $display = \taskflowadapter_standard\output\singleassignment::get_status_display((object)[
            'status' => $overdue,
            'overduecounter' => 2,
            'prolongedcounter' => 0,
        ]);
        $this->assertSame(assignment_status_facade::get_specific_names($overdue) . ' (2)', $display['name']);
        $this->assertSame('local-taskflow-status-' . $overdue, $display['statusclass']);

        $display = \taskflowadapter_standard\output\singleassignment::get_status_display((object)['status' => -2]);
        $this->assertSame(assignment_status_facade::get_specific_names(-2), $display['name']);
        $this->assertSame('local-taskflow-status-m2', $display['statusclass']);
    }

    /**
     * The "Me" view is the person page of the logged-in user, without assigning rights and without notes.
     */
    public function test_myoverview_for_own_user(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('external_api_option', 'standard', 'local_taskflow');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertTrue(myoverview::can_view_own((int)$user->id));
        $this->assertFalse(myoverview::can_view_own(0));
        $this->assertSame(myoverview::class, adapter_view_resolver::resolve_class('myoverview'));

        $PAGE->set_url(new moodle_url('/local/taskflow/dashboard.php', ['view' => 'me']));
        $view = adapter_view_resolver::instance(
            'myoverview',
            [new moodle_url('/local/taskflow/dashboard.php', ['view' => 'me']), (int)$user->id]
        );
        $this->assertSame('local_taskflow/dashboardpage', $view->get_template());

        $data = $view->export_for_template($PAGE->get_renderer('local_taskflow'));
        $this->assertTrue($data['isme']);
        $this->assertFalse($data['isperson']);
        $this->assertFalse($data['canassign']);
        $this->assertFalse($data['canviewnotes']);
        $this->assertSame([], $data['notes']);
        $this->assertSame((int)$user->id, $data['userid']);
    }

    /**
     * An unknown adapter name never breaks the resolution.
     */
    public function test_unknown_adapter_falls_back(): void {
        $this->resetAfterTest();
        set_config('external_api_option', 'doesnotexist', 'local_taskflow');
        $this->assertSame(personpage::class, adapter_view_resolver::resolve_class('personpage'));
    }
}
