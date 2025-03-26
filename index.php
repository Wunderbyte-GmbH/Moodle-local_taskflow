<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $CFG, $DB, $USER;

require_login();

$context = context_system::instance();
// Set page context.
$PAGE->set_context($context);
// Set page layout.
$PAGE->set_pagelayout('base');

$PAGE->set_title($SITE->fullname . ': ' . get_string('pluginname', 'local_taskflow'));
$PAGE->set_heading($SITE->fullname);
$PAGE->set_url(new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->ignore_active(true);
$PAGE->navbar->add(get_string('pluginname', 'local_taskflow'), new moodle_url('/local/taskflow/index.php'));

$output = $PAGE->get_renderer('local_taskflow');
echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_taskflow/initview', [
  'userid' => $USER->id,
]);

echo $OUTPUT->footer();
