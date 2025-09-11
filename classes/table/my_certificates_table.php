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
 * The report that displays the certificates the user has throughout the site.
 *
 * @package    local_taskflow
 * @copyright  2025 Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;

use tool_certificate\certificate;

/**
 * Class for the report that displays the certificates the user has throughout the site.
 *
 * @package    tool_certificate
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class my_certificates_table extends \tool_certificate\my_certificates_table {
    /**
     * Generate the name column.
     *
     * @param \stdClass $certificate
     * @return string
     */
    public function col_name($certificate) {
        global $DB;

        $jsonobj = json_decode($certificate->data);
        if (!empty($jsonobj->bookingoptionname)) {
            return $jsonobj->bookingoptionname;
        }

        $context = \context::instance_by_id($certificate->contextid);
        $name = format_string($certificate->name, true, ['context' => $context]);

        if ($certificate->courseid) {
            // Obtain course directly from DB to allow missing courses.
            if ($course = $DB->get_record('course', ['id' => $certificate->courseid])) {
                $context = \context_course::instance($course->id);
                $name .= " - " . format_string($course->fullname, true, ['context' => $context]);
            }
        }
        return $name;
    }

    /**
     * Query the reader.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        $total = certificate::count_issues_for_user($this->userid);

        $this->pagesize($pagesize, $total);

        $this->rawdata = self::get_issues_for_user(
            $this->userid,
            $this->get_page_start(),
            $this->get_page_size(),
            $this->get_sql_sort()
        );

        // Set initial bars.
        if ($useinitialsbar) {
            $this->initialbars($total > $pagesize);
        }
    }

    /**
     * Get the certificates issues for the given userid.
     *
     * @param int $userid
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $sort
     * @return array
     */
    public static function get_issues_for_user($userid, $limitfrom, $limitnum, $sort = '') {
        global $DB;

        if (empty($sort)) {
            $sort = 'ci.timecreated DESC';
        }

        $sql = "SELECT ci.id, ci.expires, ci.code, ci.timecreated, ci.userid, ci.courseid,
                       t.id as templateid, t.contextid, t.name, ci.data
                  FROM {tool_certificate_templates} t
            INNER JOIN {tool_certificate_issues} ci
                    ON t.id = ci.templateid
                 WHERE ci.userid = :userid
              ORDER BY {$sort}";
            return $DB->get_records_sql($sql, ['userid' => $userid], $limitfrom, $limitnum);
    }
}
