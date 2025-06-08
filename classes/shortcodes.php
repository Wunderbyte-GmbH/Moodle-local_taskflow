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

use local_taskflow\output\assignmentsdashboard;
use local_taskflow\output\rulesdashboard;

/**
 * Shows the dashboard.
 */
class shortcodes {
    /**
     * Prints the assignements.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function assignmentsdashboard($shortcode, $args, $content, $env, $next) {
        global $PAGE, $USER;

        $arguments = self::return_settings_from_args($args);

        $dashboard = new assignmentsdashboard(0, 0, $arguments['active']);

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
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

        $arguments = self::return_settings_from_args($args);

        $dashboard = new assignmentsdashboard($USER->id, 0, $arguments['active']);

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
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
        global $PAGE, $USER;

        $arguments = self::return_settings_from_args($args);

        $dashboard = new assignmentsdashboard(0, $USER->id, $arguments['active']);

        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
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

        $dashboard = new rulesdashboard([]);
        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
    }

    /**
     * So we don't have one place to interprete shortcode arguments,
     *
     * @param array $args
     *
     * @return [type]
     *
     */
    private static function return_settings_from_args(array $args) {

        if (!empty($args['inactive'])) {
            $active = false;
        } else {
            $active = true;
        }

        return [
            'active' => $active,
        ];
    }
}
