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
 * Dashboard: HR overview (all assignments) and team overview (supervisors), built from the existing
 * dashboard building blocks and arranged like the person and organisation pages.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\form\person_switcher;
use local_taskflow\output\adapter_view_resolver;
use local_taskflow\output\hroverview;
use local_taskflow\output\teamoverview;
use local_taskflow\taskflow_stringmanager;

require_once(__DIR__ . '/../../config.php');

$view = optional_param('view', '', PARAM_ALPHA);

require_login();
$context = context_system::instance();

$canhr = hroverview::can_view((int)$USER->id);
$canteam = teamoverview::can_view((int)$USER->id);
if ($view !== 'hr' && $view !== 'team') {
    $view = $canhr ? 'hr' : 'team';
}
if (($view === 'hr' && !$canhr) || ($view === 'team' && !$canteam)) {
    throw new required_capability_exception(
        $context,
        $view === 'hr' ? 'local/taskflow:editassignment' : 'local/taskflow:issupervisor',
        'nopermissions',
        ''
    );
}

$pageurl = new moodle_url('/local/taskflow/dashboard.php', ['view' => $view]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('base');
$PAGE->set_title($SITE->fullname . ': ' . taskflow_stringmanager::get_string('dashboard'));
$PAGE->set_heading(taskflow_stringmanager::get_string('dashboard'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('pluginname'), new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('dashboard'));

// The active adapter may replace the whole view (class and template); otherwise the core view is used.
if ($view === 'hr') {
    $renderable = adapter_view_resolver::instance('hroverview', [$pageurl]);
} else {
    $renderable = adapter_view_resolver::instance('teamoverview', [$pageurl, (int)$USER->id]);
}
$renderer = $PAGE->get_renderer('local_taskflow');
$data = $renderable->export_for_template($renderer);

// Navigation strip shared with the person and organisation pages.
$data['dashboardurl'] = (new moodle_url('/local/taskflow/dashboard.php'))->out(false);
$data['isdashboard'] = true;
$data['hrurl'] = $canhr ? (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'hr']))->out(false) : '';
$data['teamurl'] = $canteam ? (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'team']))->out(false) : '';
if (
    has_capability('local/taskflow:viewreports', $context)
    || has_capability('local/taskflow:issupervisor', $context)
) {
    $switcher = new person_switcher(
        new moodle_url('/local/taskflow/person.php'),
        null,
        'get',
        '',
        ['class' => 'local-taskflow-person-switcher']
    );
    $data['switcher'] = $switcher->render();
    $PAGE->requires->js_call_amd('local_taskflow/personswitcher', 'init', [0]);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($renderable->get_template(), $data);
echo $OUTPUT->footer();
