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
use moodle_url;

/**
 * The adapter view resolver falls back to the core views and honours adapter overrides.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\output\adapter_view_resolver
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
     * An unknown adapter name never breaks the resolution.
     */
    public function test_unknown_adapter_falls_back(): void {
        $this->resetAfterTest();
        set_config('external_api_option', 'doesnotexist', 'local_taskflow');
        $this->assertSame(personpage::class, adapter_view_resolver::resolve_class('personpage'));
    }
}
