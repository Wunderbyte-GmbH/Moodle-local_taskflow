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
     * Default links of the card, used as setting defaults. {userid} is replaced by the user id.
     */
    public const DEFAULT_LINKS = [
        'userstatslinkfinished' => '/mod/booking/mybookings.php?userid={userid}&completed=1&filter=1&typefilter=1',
        'userstatslinkcertificates' => '/local/taskflow/mycertificates.php?userid={userid}',
        'userstatslinkall' => '/mod/booking/mybookings.php?userid={userid}&filter=1&typefilter=1',
    ];

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

        $data['profile']['entries'] = 0;
        $data['profile']['allentries'] = 0;
        if (core_component::get_plugin_directory('mod', 'booking')) {
            $data['profile']['entries'] = booking_answers::count_answers_of_user($userid);
            $data['profile']['allentries'] = booking_answers::count_allanswers_of_user($userid);
        }

        // Moodle courses assigned via taskflow rules are no booking answers, so count and list them too.
        $courses = self::get_assigned_courses($userid);
        $data['profile']['entries'] += count(array_filter(array_column($courses, 'completed')));
        $data['profile']['allentries'] += count($courses);
        $data['profile']['courses'] = $courses;
        $data['profile']['hascourses'] = !empty($courses);

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
        $data['profile']['finishedurl'] = self::get_link('userstatslinkfinished', $userid);
        $data['profile']['certificatesurl'] = self::get_link('userstatslinkcertificates', $userid);
        $data['profile']['allurl'] = self::get_link('userstatslinkall', $userid);
        $data['userid'] = $userid;
        $this->data = $data;
    }

    /**
     * Returns the URL of a link in the card, from the plugin settings.
     *
     * {userid} in the setting is replaced by the user id. Without a user id,
     * the userid parameter is removed. An empty setting means no link.
     *
     * @param string $setting Name of the setting.
     * @param int $userid
     * @return string
     */
    private static function get_link(string $setting, int $userid): string {
        $url = get_config('local_taskflow', $setting);
        if ($url === false) {
            // Setting not saved yet (no upgrade since it was added): use the default.
            $url = self::DEFAULT_LINKS[$setting] ?? '';
        }
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (!empty($userid)) {
            return str_replace('{userid}', (string)$userid, $url);
        }
        $url = preg_replace('/([?&])userid=\{userid\}(&|$)/', '$1', $url);
        return rtrim(str_replace('{userid}', '', $url), '?&');
    }

    /**
     * Returns the Moodle course targets of the user's active assignments (from the rules).
     *
     * A course that is a target of several assignments is listed once and counts
     * as completed if it is completed in any of them. Booking options are left out,
     * they are already counted via booking answers.
     *
     * @param int $userid
     * @return array List of ['name', 'url', 'completed', 'status'], sorted: open courses first.
     */
    private static function get_assigned_courses(int $userid): array {
        global $DB;

        $records = $DB->get_records('local_taskflow_assignment', ['userid' => $userid, 'active' => 1], '', 'id, targets');
        $completion = [];
        foreach ($records as $record) {
            foreach (json_decode($record->targets ?? '') ?: [] as $target) {
                if (($target->targettype ?? '') !== 'moodlecourse' || empty($target->targetid)) {
                    continue;
                }
                $completed = !empty($target->completionstatus) && $target->completionstatus == 1;
                $completion[$target->targetid] = ($completion[$target->targetid] ?? false) || $completed;
            }
        }
        if (empty($completion)) {
            return [];
        }

        // Courses that were deleted in the meantime are skipped.
        $dbcourses = $DB->get_records_list('course', 'id', array_keys($completion), '', 'id, fullname');
        $courses = [];
        foreach ($dbcourses as $course) {
            $completed = $completion[$course->id];
            $courses[] = [
                'name' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
                'url' => (new \core\url('/course/view.php', ['id' => $course->id]))->out(false),
                'completed' => $completed,
                'status' => get_string($completed ? 'completed' : 'notcompleted', 'local_taskflow'),
            ];
        }
        usort($courses, fn($a, $b) => [$a['completed'], $a['name']] <=> [$b['completed'], $b['name']]);
        return $courses;
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
