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
 * Dashboard: HR overview (all assignments), team overview (supervisors), the own overview ("Me") and one tab per
 * opened person, built from the existing dashboard building blocks and arranged like the organisation page.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\form\person_switcher;
use local_taskflow\local\dashboard\person_tabs;
use local_taskflow\output\adapter_view_resolver;
use local_taskflow\output\hroverview;
use local_taskflow\output\myoverview;
use local_taskflow\output\personpage;
use local_taskflow\output\teamoverview;
use local_taskflow\taskflow_stringmanager;

require_once(__DIR__ . '/../../config.php');

$view = optional_param('view', '', PARAM_ALPHA);
$personid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$tabid = optional_param('tabid', 0, PARAM_INT);

require_login();
$context = context_system::instance();
$viewerid = (int)$USER->id;

$canhr = hroverview::can_view($viewerid);
$canteam = teamoverview::can_view($viewerid);
if (!myoverview::can_view_own($viewerid)) {
    throw new require_login_exception('');
}
if ($view === 'person' && $personid === $viewerid) {
    // The viewer's own page is the "Me" tab.
    $view = 'me';
}
if (!in_array($view, ['hr', 'team', 'me', 'person'], true) || ($view === 'person' && empty($personid))) {
    $view = $canhr ? 'hr' : ($canteam ? 'team' : 'me');
}
$pageurl = new moodle_url(
    '/local/taskflow/dashboard.php',
    $view === 'person' ? ['view' => 'person', 'id' => $personid] : ['view' => $view]
);

// Tab actions only change the viewer's own tab list; afterwards the page is shown again without the action.
if ($action !== '') {
    require_sesskey();
    $message = '';
    $returnurl = $pageurl;
    if ($action === 'closetab') {
        person_tabs::close($viewerid, $tabid);
        if ($view === 'person' && $personid === $tabid) {
            $returnurl = new moodle_url('/local/taskflow/dashboard.php');
        }
    } else if ($action === 'closealltabs') {
        person_tabs::close_all($viewerid);
        if ($view === 'person') {
            $returnurl = new moodle_url('/local/taskflow/dashboard.php');
        }
    } else if ($action === 'openteam' && $canteam) {
        $result = person_tabs::open_team($viewerid);
        $message = taskflow_stringmanager::get_string('openteamtabs_done', $result['open']);
        if (!empty($result['skipped'])) {
            $message .= ' ' . taskflow_stringmanager::get_string('persontabs_limit', person_tabs::MAX_TABS);
        }
    }
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_INFO);
}

if (($view === 'hr' && !$canhr) || ($view === 'team' && !$canteam)) {
    throw new required_capability_exception(
        $context,
        $view === 'hr' ? 'local/taskflow:editassignment' : 'local/taskflow:issupervisor',
        'nopermissions',
        ''
    );
}

$person = null;
if ($view === 'person') {
    $person = core_user::get_user($personid, '*', MUST_EXIST);
    if (!personpage::can_view($viewerid, (int)$person->id)) {
        throw new required_capability_exception($context, 'local/taskflow:viewreports', 'nopermissions', '');
    }
    // Showing a person opens their tab; at the limit the person is still shown, but not kept as a tab.
    if (!person_tabs::open($viewerid, (int)$person->id)) {
        \core\notification::info(taskflow_stringmanager::get_string('persontabs_limit', person_tabs::MAX_TABS));
    }
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('base');
$PAGE->set_title(
    ($person ? fullname($person) . ': ' : $SITE->fullname . ': ') . taskflow_stringmanager::get_string('dashboard')
);
$PAGE->set_heading(taskflow_stringmanager::get_string('dashboard'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('pluginname'), new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('dashboard'));

// The active adapter may replace each view (class and template); otherwise the core view is used.
if ($view === 'hr') {
    $renderable = adapter_view_resolver::instance('hroverview', [$pageurl]);
} else if ($view === 'team') {
    $renderable = adapter_view_resolver::instance('teamoverview', [$pageurl, $viewerid]);
} else if ($view === 'me') {
    $renderable = adapter_view_resolver::instance('myoverview', [$pageurl, $viewerid]);
} else {
    // Actions of the person page (remove rule, delete note) are handled by person.php, which returns to this tab.
    $renderable = adapter_view_resolver::instance('persontab', [
        $person,
        has_capability('local/taskflow:assignrulestouser', $context),
        new moodle_url('/local/taskflow/person.php', ['id' => (int)$person->id]),
    ]);
}
$renderer = $PAGE->get_renderer('local_taskflow');
$data = $renderable->export_for_template($renderer);

// Tabs: HR, Team, Me and one tab per opened person.
$data['dashboardurl'] = (new moodle_url('/local/taskflow/dashboard.php'))->out(false);
$data['isdashboard'] = true;
$data['hrurl'] = $canhr ? (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'hr']))->out(false) : '';
$data['teamurl'] = $canteam ? (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'team']))->out(false) : '';
$data['meurl'] = (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'me']))->out(false);
$tabids = person_tabs::get($viewerid);
if ($person && !in_array((int)$person->id, $tabids, true)) {
    $tabids[] = (int)$person->id;
}
$data['persontabs'] = [];
foreach ($tabids as $tabuserid) {
    $tabuser = core_user::get_user($tabuserid);
    $data['persontabs'][] = [
        'id' => $tabuserid,
        'name' => fullname($tabuser),
        'url' => (new moodle_url('/local/taskflow/dashboard.php', ['view' => 'person', 'id' => $tabuserid]))->out(false),
        'active' => $person && (int)$person->id === $tabuserid,
        'closeurl' => (new moodle_url($pageurl, [
            'action' => 'closetab',
            'tabid' => $tabuserid,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}
$data['closeallurl'] = $data['persontabs']
    ? (new moodle_url($pageurl, ['action' => 'closealltabs', 'sesskey' => sesskey()]))->out(false)
    : '';
$data['openteamurl'] = $canteam
    ? (new moodle_url($pageurl, ['action' => 'openteam', 'sesskey' => sesskey()]))->out(false)
    : '';

if (
    has_capability('local/taskflow:viewreports', $context)
    || has_capability('local/taskflow:issupervisor', $context)
) {
    // Choosing a person in the switcher opens their tab (person.php forwards to it).
    $switcher = new person_switcher(
        new moodle_url('/local/taskflow/person.php'),
        null,
        'get',
        '',
        ['class' => 'local-taskflow-person-switcher']
    );
    $data['switcher'] = $switcher->render();
    $PAGE->requires->js_call_amd('local_taskflow/personswitcher', 'init', [$person ? (int)$person->id : 0]);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($renderable->get_template(), $data);
echo $OUTPUT->footer();
