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
 * Interface for supervisor assignment data providers used in the dashboard table.
 *
 * @package    local_taskflow
 * @copyright  2025 Wunderbyte Gmbh <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

namespace local_taskflow\output\assignmentsdashboard;

use local_taskflow\local\assignments\assignment;
use local_taskflow\output\assignmentsdashboard\assignmentdataprovider;

/**
 * Display this element
 * @package local_taskflow
 *
 */
class supervisorassignmentsprovider implements assignmentdataprovider {
    /**
     * User ID of the user whose assignments are to be shown.
     * @var int
     */
    private int $userid;

    /**
     * Filter arguments passed to the dashboard.
     * @var array
     */
    private array $arguments;

    /**
     * Per-request memo of get_table_data() results, keyed by provider class, user and arguments.
     *
     * The dashboards build the same provider several times per page (e.g. once for the table and
     * once for the chart), and building the SQL costs several lookups (profile field ids,
     * subordinates, deputies). Not used under PHPUnit: the SQL embeds ids read from the database,
     * which would go stale between tests.
     *
     * @var array
     */
    private static array $tabledatamemo = [];

    /**
     * Constructor.
     * @param int $userid
     * @param array $arguments
     */
    public function __construct(int $userid, array $arguments) {
        $this->userid = $userid;
        $this->arguments = $arguments;
    }

    /**
     * Returns true if the provider query yields at least one row.
     * @return bool
     */
    public function has_records(): bool {
        global $DB;
        $data = $this->get_table_data();
        $sql = "SELECT 1 FROM {$data['from']} WHERE {$data['where']}";
        return $DB->record_exists_sql($sql, $data['params']);
    }

    /**
     * Get SQL-Parameters for table data.
     * @return array An array containing 'select', 'from', 'where', and 'params'
     */
    public function get_table_data(): array {
        $memokey = static::class . '_' . $this->userid . '_' . json_encode($this->arguments);
        $usememo = !(defined('PHPUNIT_TEST') && PHPUNIT_TEST);
        if ($usememo && isset(self::$tabledatamemo[$memokey])) {
            return self::$tabledatamemo[$memokey];
        }
        $assignments = assignment::get_instance();
        [$select, $from, $where, $params] = $assignments->return_supervisor_assignments_sql(
            $this->userid,
            $this->arguments
        );
        $data = compact('select', 'from', 'where', 'params');
        if ($usememo) {
            self::$tabledatamemo[$memokey] = $data;
        }
        return $data;
    }
}
