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
use local_taskflow\shortcodes;
use mod_booking\booking_answers\booking_answers;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Display the user stats card.
 * @package local_taskflow
 *
 */
class userstatscard implements renderable, templatable {
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
     * Prepare data for user stats.
     *
     * @param int $userid
     * @param array $arguments
     *
     */
    public function __construct(int $userid = 0, array $arguments = []) {
        global $DB;
        $env = new stdClass();
        $next = fn($a) => $a;

        if (core_component::get_plugin_directory('mod', 'booking')) {
            $data['profile']['entries'] = booking_answers::count_answers_of_user($userid);
        }

        if (core_component::get_plugin_directory('tool', 'certificate')) {
            $data['profile']['certificates'] = $DB->count_records('tool_certificate_issues', ['userid' => $userid]);
        }

        $data['profile']['chart'] = shortcodes::myassignments(
            '',
            [
                'active' => 1,
                'chart' => 1,
                'userid' => $userid,
            ],
            null,
            $env,
            $next
        );
        $this->data = $data;
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
