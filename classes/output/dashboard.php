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
use local_taskflow\output\assignmentsdashboard\myassignmentsprovider;
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
        global $USER, $PAGE;

        $env = new stdClass();
        $next = fn($a) => $a;

        if (has_capability('local/taskflow:issupervisor', context_system::instance())) {
            $subplugin = get_config('local_taskflow', 'external_api_option');
            $class = "taskflowadapter_$subplugin\\output\\supervisordashboard";
            if (class_exists($class)) {
                $supervisordashboard = new $class($this->userid, $this->arguments);
            } else {
                $class = "taskflowadapter_standard\\output\\supervisordashboard";
                $supervisordashboard = new $class($this->userid, $this->arguments);
            }

            $renderer = $PAGE->get_renderer('local_taskflow');
            $supervisordashboardhtml = $renderer->render_from_template(
                'local_taskflow/dashboards/supervisordashboard',
                $supervisordashboard->export_for_template($renderer)
            );
            $data['rules'][] = $supervisordashboardhtml;
        } else {
            $data['rules'][] = "";
        }
        $hrusersstring = get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers');
        $hrusers = explode(',', $hrusersstring);
        if (
            in_array($USER->id, $hrusers, false)
            || has_capability('local/taskflow:editassignment', context_system::instance())
        ) {
            $subplugin = get_config('local_taskflow', 'external_api_option');
            $class = "taskflowadapter_$subplugin\\output\\admindashboard";
            if (class_exists($class)) {
                $admindashboard = new $class($this->userid, $this->arguments);
            } else {
                $class = "taskflowadapter_standard\\output\\admindashboard";
                $admindashboard = new $class($this->userid, $this->arguments);
            }
            $renderer = $PAGE->get_renderer('local_taskflow');
            $admindashboardhtml = $renderer->render_from_template(
                'local_taskflow/dashboards/admindashboard',
                $admindashboard->export_for_template($renderer)
            );
            $data['dashboard'][] = $admindashboardhtml;
        } else {
            $data['dashboard'][] = "";
        }

        if (!empty($html)) {
            $data['dashboard'][] = $html;
        }
        $cache = cache::make('local_taskflow', 'dashboardfilter');
        $filter = $cache->get('dashboardfilter') ?: [];

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

                if (get_config('local_taskflow', 'showassignmentslist')) {
                    $provider = new myassignmentsprovider($userid, ['active' => 2]);
                    $renderinstance = new assignmentsdashboard($provider, 0, []);
                    $renderinstance->get_assignmentsdashboard();
                    $renderinstance->set_general_table_heading([]);
                    $renderer = $PAGE->get_renderer('local_taskflow');

                    $html[] = $renderer->render($renderinstance);
                }
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
