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

declare(strict_types=1);

namespace local_taskflow\reportbuilder\datasource;

use core\lang_string;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\reportbuilder\local\entities\assignment;
use local_taskflow\reportbuilder\local\entities\assignment_target;
use local_taskflow\reportbuilder\local\entities\rule;
use local_taskflow\reportbuilder\local\helpers\supervisor_entities;
use local_taskflow\reportbuilder\local\helpers\target_sql;
use mod_booking\reportbuilder\local\entities\booking_options;

/**
 * Assignment targets datasource for Report Builder.
 *
 * One row per competency target of a taskflow assignment and per booking
 * option that carries the competency (an assignment with a competency target
 * that no booking option carries still gives one row with an empty option).
 * Joined with the assigned user, the rule, the supervisor (see the assignment
 * datasource) and the booking option incl. its custom fields.
 *
 * The "Booked" flag tells whether the assigned user has an answer (booked or
 * on the waiting list) for the booking option of the row, "Booked any option
 * of the competency" whether the user has such an answer for any booking
 * option carrying the competency. Set as condition to "No", the latter lists
 * the targets the user still has to book.
 *
 * Only competency targets are listed; the targets are stored as JSON on the
 * assignment and matched with LIKE patterns (see helpers\target_sql), which
 * scans all assignments and competencies. The default conditions (active,
 * not completed) narrow the assignment side of that scan.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_target_datasource extends datasource {
    use supervisor_entities;

    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:assignmenttarget', 'local_taskflow');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $assignmententity = new assignment();
        $as = $assignmententity->get_table_alias('local_taskflow_assignment');
        $this->set_main_table('local_taskflow_assignment', $as);
        $this->add_entity($assignmententity);

        // Rule the assignment was created from. Left join, so assignments of
        // deleted rules are still listed.
        $ruleentity = new rule();
        $r = $ruleentity->get_table_alias('local_taskflow_rules');
        $this->add_entity($ruleentity
            ->add_join("LEFT JOIN {local_taskflow_rules} {$r} ON {$r}.id = {$as}.ruleid"));

        // Assigned user.
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join("LEFT JOIN {user} {$u} ON {$u}.id = {$as}.userid AND {$u}.deleted = 0"));

        // Competency targets of the assignment: one row per competency target.
        // Joined on report level, so the row grain does not depend on the
        // columns chosen in the report.
        $targetentity = (new assignment_target())->set_table_alias('local_taskflow_assignment', $as);
        $c = $targetentity->get_table_alias('competency');
        $this->add_join(
            "JOIN {competency} {$c} ON " . target_sql::competency_target_match("{$as}.targets", "{$c}.id")
        );
        $this->add_entity($targetentity);

        // Booking options carrying the competency: one row per option.
        $optionentity = new booking_options();
        $bo = $optionentity->get_table_alias('booking_options');
        $this->add_join(
            "LEFT JOIN {booking_options} {$bo} ON " . target_sql::csv_contains("{$bo}.competencies", "{$c}.id")
        );
        $this->add_entity($optionentity);

        // Booked flags: answers below "reserved" are booked or on the waiting list.
        $reserved = (int) MOD_BOOKING_STATUSPARAM_RESERVED;
        $targetentityname = $targetentity->get_entity_name();

        $ba = database::generate_alias();
        $bookedsql = "CASE WHEN EXISTS (
                          SELECT 1
                            FROM {booking_answers} {$ba}
                           WHERE {$ba}.optionid = {$bo}.id
                             AND {$ba}.userid = {$as}.userid
                             AND {$ba}.waitinglist < {$reserved})
                      THEN 1 ELSE 0 END";
        $this->add_booked_flag('booked', new lang_string('booked', 'local_taskflow'), $targetentityname, $bookedsql);

        $bo2 = database::generate_alias();
        $ba2 = database::generate_alias();
        $bookedanysql = "CASE WHEN EXISTS (
                             SELECT 1
                               FROM {booking_options} {$bo2}
                               JOIN {booking_answers} {$ba2} ON {$ba2}.optionid = {$bo2}.id
                              WHERE {$ba2}.userid = {$as}.userid
                                AND {$ba2}.waitinglist < {$reserved}
                                AND " . target_sql::csv_contains("{$bo2}.competencies", "{$c}.id") . ")
                         THEN 1 ELSE 0 END";
        $this->add_booked_flag('bookedany', new lang_string('bookedany', 'local_taskflow'), $targetentityname, $bookedanysql);

        // Supervisor and deputies of the assigned user (when the adapter maps a supervisor profile field).
        $this->add_supervisor_entities($userentity);

        $this->add_all_from_entities();
    }

    /**
     * Add a boolean column, filter and condition based on a "CASE WHEN ... THEN 1 ELSE 0 END" expression.
     *
     * @param string $name
     * @param lang_string $title
     * @param string $entityname
     * @param string $sql
     */
    private function add_booked_flag(string $name, lang_string $title, string $entityname, string $sql): void {
        $this->add_column(
            (new column($name, $title, $entityname))
                ->set_type(column::TYPE_BOOLEAN)
                ->add_field($sql, $name)
                ->set_is_sortable(false)
                ->add_callback([format::class, 'boolean_as_text'])
        );

        $filter = new filter(boolean_select::class, $name, $title, $entityname, $sql);
        $this->add_filter($filter);
        $this->add_condition($filter);
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'assignment_target:targetname',
            'booking_options:text',
            'assignment:assigneddate',
            'assignment:duedate',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'assignment:duedate' => SORT_ASC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'assignment:assigneddate',
            'assignment:duedate',
            'assignment:status',
            'assignment_target:name',
            'booking_options:text',
            'assignment_target:booked',
        ];
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'assignment:active',
            'assignment:status',
            'assignment_target:bookedany',
        ];
    }

    /**
     * Default condition values: active, not completed assignments whose
     * competency the user has not booked on any option.
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        return [
            'assignment:active_operator' => boolean_select::CHECKED,
            'assignment:status_operator' => select::NOT_EQUAL_TO,
            'assignment:status_value' => assignment_status_facade::get_status_identifier('completed'),
            'assignment_target:bookedany_operator' => boolean_select::NOT_CHECKED,
        ];
    }
}
