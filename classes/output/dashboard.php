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
 * Contains class mod_questionnaire\output\indexpage
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output;

use core_component;
use local_taskflow\local\dashboardcache\dashboardcache;
use local_taskflow\shortcodes;
use renderable;
use renderer_base;
use stdClass;
use templatable;
use cache;
use context_system;
use mod_booking\shortcodes as bookingshortcodes;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class dashboard implements renderable, templatable {
    /**
     * data is the array used for output.
     *
     * @var array
     */
    private $data = [];

    /**
     * data is the array used for output.
     * @var array
     */
    public $arguments = [];

    /**
     * Userid
     * @var int
     */
    public $userid = 0;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param array $arguments
     *
     */
    public function __construct(int $userid = 0, array $arguments = []) {
        $this->userid = $userid;
        $this->arguments = $arguments;
        $this->set_data();
    }

    /**
     * get_assignmentsdashboard.
     */
    public function set_data() {
        global $USER;

        $env = new stdClass();
        $next = fn($a) => $a;

        // These are the elements for the Reports tab.
        $html = shortcodes::rulesdashboard('', [], null, $env, $next);
        if (!empty($html)) {
            $data['rules'][] = $html;
        }
        $html = shortcodes::assignmentsdashboard('', [], null, $env, $next);
        if (!empty($html)) {
            $data['rules'][] = $html;
        }
        $html = shortcodes::supervisorassignments(
            '',
            [
                'overdue' => 1,
                'chart' => 1,
            ],
            null,
            $env,
            $next
        );
        if (!empty($html)) {
            $data['rules'][] = $html;
        }
        $html = shortcodes::supervisorassignments('', ['overdue' => 0, 'chart' => 1, 'deputyselect' => 1], null, $env, $next);
        if (!empty($html)) {
            $data['rules'][] = $html;
        }

        if (has_capability('local/taskflow:issupervisor', context_system::instance())) {
            $data['rules'][] = bookingshortcodes::listtoapprove('', [], null, $env, $next);
        }

        // These Elements show up in the statistics tab.
        // First, render the chart of all active assignments.
        $html = shortcodes::assignmentsdashboard('', ['active' => 1, 'chart' => 1], null, $env, $next);
        if (!empty($html)) {
            $data['dashboard'][] = $html;
        }
        // Now add a list of the top 5 overdue assignments.
        $html = shortcodes::assignmentsdashboard('', ['overdue' => 1, 'top5' => 1], null, $env, $next);
        if (!empty($html)) {
            $data['dashboard'][] = $html;
        }
        if (core_component::get_plugin_directory('mod', 'booking')) {
            $data['booking'][] = \mod_booking\shortcodes::mycourselist('', [], null, $env, $next);
        }
        $cache   = cache::make('local_taskflow', 'dashboardfilter');
        $filter  = $cache->get('dashboardfilter') ?: [];

        $store = new dashboardcache();
        if (has_capability('local/taskflow:viewreports', context_system::instance())) {
            $data['showuserselector'] = true;
        }
        $store->set_userid($USER->id);
        $filter = $store->get_all_users();

        if ($filter && isset($filter['userids']) && is_array($filter['userids'])) {
            foreach ($filter['userids'] as $userid => $info) {
                $html = [];
                $html[] = $this->get_user_info($userid);
                $html[] = $this->show_user_stats($userid);
                $html[] = shortcodes::myassignments(
                    '',
                    ['userid' => $userid],
                    null,
                    $env,
                    $next
                );
                $data['users'][] = [
                    'id'       => $userid,
                    'username' => $info['username'],
                    'html'     => $html,
                ];
            }
        }
        $this->data = [
            'data' => $data,
            'template' => 'local_taskflow/dashboard',
        ];
    }

    /**
     * Summary of get_user_info
     * @param mixed $userid
     * @return string
     */
    private function get_user_info($userid) {
        global $DB, $PAGE;

        if ($userid) {
            $fields = 'firstname,lastname,email';
            $renderinstance = new userinfocard($userid, $fields);

            $renderer = $PAGE->get_renderer('local_taskflow');
            return $renderer->render($renderinstance);
        }
        return '';
    }

    /**
     * Renders the user stats.
     *
     * @param mixed $userid
     *
     * @return string
     *
     */
    private function show_user_stats($userid) {
        global $PAGE;

        $renderinstance = new userstatscard($userid);

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($renderinstance);
    }

    /**
     * Prepare data for use in a template
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return $this->data;
    }
}
