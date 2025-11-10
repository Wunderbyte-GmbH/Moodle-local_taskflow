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
use local_taskflow\output\dashboard;
require_login();

global $CFG, $PAGE, $OUTPUT;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_pagelayout('base');
$PAGE->set_title($SITE->fullname . ': ' . get_string('pluginname', 'local_taskflow'));
$PAGE->set_heading($SITE->fullname);
$PAGE->set_url(new moodle_url('/local/taskflow/index.php'));
$PAGE->navbar->add(get_string('pluginname', 'local_taskflow'));

echo $OUTPUT->header();

$arguments = [];
$dummydata = [
    "notificationsurl" => "/local/dashboard/notifications.php",
    "delegateurl" => "/local/dashboard/delegate.php",

    "approvals" => [
        [
            "title" => "Urlaubsgenehmigung – Max Mustermann",
            "date"  => "08. Oktober 2025",
            "link"  => "/local/dashboard/approval/1",
        ],
        [
            "title" => "Kursanmeldung – Anna Schmidt",
            "date"  => "07. Oktober 2025",
            "link"  => "/local/dashboard/approval/2",
        ],
    ],

    "upcomingevents" => [
        [
            "name" => "Team-Meeting",
            "date" => "10. Oktober 2025, 10:00 Uhr",
            "type" => "Meeting",
        ],
        [
            "name" => "Sicherheits-Training",
            "date" => "15. Oktober 2025",
            "type" => "Schulung",
        ],
        [
            "name" => "Feedbackgespräch",
            "date" => "18. Oktober 2025",
            "type" => "Gespräch",
        ],
    ],

    "activities" => [
        [
            "name"     => "Einführung in Arbeitssicherheit",
            "progress" => 75,
        ],
        [
            "name"     => "Datenschutz-Grundlagen",
            "progress" => 40,
        ],
        [
            "name"     => "Führungskräftetraining",
            "progress" => 90,
        ],
    ],
    "activitychart" => "<canvas id='activityChart'></canvas>",
];
echo $OUTPUT->render_from_template('local_taskflow/dashboards/dashboard_draft', $dummydata);

echo $OUTPUT->footer();
