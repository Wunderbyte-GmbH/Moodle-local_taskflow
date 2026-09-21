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

use local_taskflow\local\personnotes\person_notes_service;
use local_taskflow\local\rules\personal_rule_assignment_service;
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
// The person is shown as a tab of the dashboard; this script only handles the person page actions.
$pageurl = new moodle_url('/local/taskflow/dashboard.php', ['view' => 'person', 'id' => $userid]);

if (!personpage::can_view((int)$USER->id, (int)$user->id)) {
    throw new required_capability_exception($context, 'local/taskflow:viewreports', 'nopermissions', '');
}

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

redirect($pageurl);
