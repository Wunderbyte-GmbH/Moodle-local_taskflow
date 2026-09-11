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
 * Person page: training profile of one user with the option to assign rules and curricula.
 *
 * @package     local_taskflow
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\form\person_switcher;
use local_taskflow\local\personnotes\person_notes_service;
use local_taskflow\local\rules\personal_rule_assignment_service;
use local_taskflow\output\adapter_view_resolver;
use local_taskflow\output\personpage;
use local_taskflow\taskflow_stringmanager;

require_once(__DIR__ . '/../../config.php');

$userid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$ruleid = optional_param('ruleid', 0, PARAM_INT);
$noteid = optional_param('noteid', 0, PARAM_INT);

require_login();

$user = core_user::get_user($userid, '*', MUST_EXIST);
$context = context_system::instance();
$pageurl = new moodle_url('/local/taskflow/person.php', ['id' => $userid]);

if (!personpage::can_view((int)$USER->id, (int)$user->id)) {
    throw new required_capability_exception($context, 'local/taskflow:viewreports', 'nopermissions', '');
}
$canassign = has_capability('local/taskflow:assignrulestouser', $context);

if ($action === 'unassign' && !empty($ruleid)) {
    require_sesskey();
    require_capability('local/taskflow:assignrulestouser', $context);
    // The page-level scope check above already limits this to persons the viewer is in charge of.
    (new personal_rule_assignment_service())->unassign($ruleid, (int)$user->id);
    redirect($pageurl, taskflow_stringmanager::get_string('unassignrule_success'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'deletenote' && !empty($noteid)) {
    require_sesskey();
    (new person_notes_service())->delete($noteid, (int)$USER->id);
    redirect(
        $pageurl,
        taskflow_stringmanager::get_string('deletepersonnote_success'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('base');
$PAGE->set_title(fullname($user) . ': ' . taskflow_stringmanager::get_string('personpage'));
$PAGE->set_heading(taskflow_stringmanager::get_string('personpage'));
$PAGE->navbar->add(taskflow_stringmanager::get_string('pluginname'), new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->add(fullname($user));

// The active adapter may replace the whole view (class and template); otherwise the core view is used.
$renderable = adapter_view_resolver::instance('personpage', [$user, $canassign, $pageurl]);
$renderer = $PAGE->get_renderer('local_taskflow');
$data = $renderable->export_for_template($renderer);

// Person switcher: the dashboard's scoped user search (all users with viewreports, own team as supervisor).
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
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template($renderable->get_template(), $data);
echo $OUTPUT->footer();
