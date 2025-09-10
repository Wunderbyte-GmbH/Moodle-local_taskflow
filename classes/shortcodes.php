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
 * Shortcodes for local_taskflow
 *
 * @package local_taskflow
 * @subpackage db
 * @copyright 2025 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_taskflow;

use context_system;
use core_component;
use local_taskflow\output\assignmentsdashboard;
use local_taskflow\output\rulesdashboard;

/**
 * Shows the dashboard.
 */
class shortcodes {
    /**
     * Prints the assignements.
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function assignmentsdashboard($shortcode, $args, $content, $env, $next) {
        global $PAGE;

        $error = shortcodes_handler::validatecondition($shortcode, $args, ['local/taskflow:editassignment']);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $arguments = self::normalize_arguments($args);

        $renderinstance = new assignmentsdashboard(0, $arguments);
        $renderinstance->get_assignmentsdashboard();
        $renderinstance->set_general_table_heading();

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($renderinstance);
    }

    /**
     * My Assignments.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function myassignments($shortcode, $args, $content, $env, $next) {
        global $PAGE, $USER;

        $error = shortcodes_handler::validatecondition($shortcode, $args, []);
        if ($error['error'] === 1) {
            return $error['message'];
        }
        $arguments = self::normalize_arguments($args);
        $arguments['active'] = $arguments['active'] ?? 1;
        $arguments['userid'] = $arguments['userid'] ?? $USER->id;
        $renderinstance = new assignmentsdashboard($arguments['userid'], $arguments);
        $renderinstance->get_assignmentsdashboard();
        $renderinstance->set_my_table_heading();
        $renderinstance->set_my_table_information();

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($renderinstance);
    }

    /**
     * My Assignments.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function supervisorassignments($shortcode, $args, $content, $env, $next) {
        global $PAGE, $USER, $OUTPUT;

        $error = shortcodes_handler::validatecondition($shortcode, $args, ['local/taskflow:issupervisor']);
        if ($error['error'] === 1) {
            return $error['message'];
        }
        $arguments = self::normalize_arguments($args);
        $renderinstance = new assignmentsdashboard($USER->id, $arguments);
        $renderinstance->get_supervisordashboard();
        if (!empty($args['overdue'])) {
            $renderinstance->set_overdue_table_heading();
        } else {
            $renderinstance->set_supervisor_table_heading();
        }
        $output = "";
        if (
            core_component::get_plugin_directory('mod', 'booking')
            && (!isset($args['hidedeputyselect']) || empty($args['hidedeputyselect']))
            && has_capability('mod/booking:assigndeputies', context_system::instance())
        ) {
            $output .= $OUTPUT->render_from_template('mod_booking/deputyselect', []);
        }

        $renderer = $PAGE->get_renderer('local_taskflow');
        $output .= $renderer->render($renderinstance);
        return $output;
    }

    /**
     * Prints out list of previous history items in a card..
     * Arguments can be 'userid'.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function rulesdashboard($shortcode, $args, $content, $env, $next) {

        global $PAGE;

        $error = shortcodes_handler::validatecondition($shortcode, $args, ['local/taskflow:viewrules']);
        if ($error['error'] === 1) {
            return $error['message'];
        }

        $dashboard = new rulesdashboard([]);
        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
    }

    /**
     * So we don't have one place to interprete shortcode arguments,
     *
     * @param array $args
     *
     * @return array
     *
     */
    private static function normalize_arguments(array $args) {
        // 0 means inactive only.
        // 1 means active only.
        // 2 means all.
        $args['active'] = $args['active'] ?? 2;

        return $args;
    }
}
