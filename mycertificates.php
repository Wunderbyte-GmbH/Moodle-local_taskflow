<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/
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
 * Handles viewing the certificates for a certain user.
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_taskflow\table\my_certificates_table;

require_once('../../config.php');

$userid = optional_param('userid', 0, PARAM_INT);
$download = optional_param('download', null, PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', \tool_certificate\certificate::ISSUES_PER_PAGE, PARAM_INT);
$pageurl = $url = new moodle_url('/local/taskflow/mycertificates.php', ['userid' => $userid,
    'page' => $page, 'perpage' => $perpage, ]);

// Requires a login.
require_login();

// Check that we have a valid user.
$user = \core_user::get_user($userid ?: $USER->id, '*', MUST_EXIST);
if (!\tool_certificate\permission::can_view_list($user->id)) {
    require_capability('tool/certificate:viewallcertificates', context_system::instance());
}

$table = new my_certificates_table($user->id, $download);
$table->define_baseurl($pageurl);

if ($table->is_downloading()) {
    $table->download();
    exit();
}

$PAGE->set_url($pageurl);
$PAGE->set_context(context_user::instance($user->id));
$PAGE->navigation->extend_for_user($user);
$PAGE->set_title(get_string('mycertificates', 'tool_certificate'));

$PAGE->set_pagelayout('standard');

$PAGE->navbar->add(get_string('profile'), new moodle_url('/user/profile.php', ['id' => $user->id]));
$PAGE->navbar->add(get_string('mycertificates', 'tool_certificate'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mycertificates', 'tool_certificate'));
echo html_writer::div(get_string('mycertificatesdescription', 'tool_certificate'));
$table->out($perpage, false);
echo $OUTPUT->footer();
