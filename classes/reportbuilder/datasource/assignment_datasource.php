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
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\filter;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\reportbuilder\local\entities\assignment;
use local_taskflow\reportbuilder\local\entities\deputy;
use local_taskflow\reportbuilder\local\entities\rule;
use local_taskflow\reportbuilder\local\filters\profile_field_current_user;

/**
 * Assignment datasource for Report Builder.
 *
 * One row per taskflow assignment, joined with the assigned user, the rule
 * the assignment was created from and (when the adapter maps a supervisor
 * profile field) the user's supervisor.
 *
 * The "Supervisor is current user" condition restricts the report to the
 * assignments of users whose supervisor profile field holds the ID of the
 * user viewing the report (or receiving the schedule). The deputy entity's
 * "Deputy is current user" condition does the same for deputies of the
 * supervisor.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_datasource extends datasource {
    /** @var string Entity name of the supervisor user entity. */
    public const SUPERVISOR_ENTITY = 'supervisor';

    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:assignment', 'local_taskflow');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        global $DB;

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

        // Supervisor of the assigned user, resolved through the profile field
        // the active taskflow adapter maps to the supervisor.
        $supervisorfieldid = self::get_supervisor_field_id();
        if ($supervisorfieldid > 0) {
            $sd = database::generate_alias();
            $supervisordatajoin = "LEFT JOIN {user_info_data} {$sd}
                                          ON {$sd}.userid = {$u}.id
                                         AND {$sd}.fieldid = {$supervisorfieldid}";

            $supervisorentity = (new user())
                ->set_entity_name(self::SUPERVISOR_ENTITY)
                ->set_entity_title(new lang_string('entity:supervisor', 'local_taskflow'));
            $sv = $supervisorentity->get_table_alias('user');
            $svid = $DB->sql_cast_to_char("{$sv}.id");
            $this->add_entity($supervisorentity
                ->add_joins($userentity->get_joins())
                ->add_join($supervisordatajoin)
                ->add_join("LEFT JOIN {user} {$sv} ON {$svid} = {$sd}.data AND {$sv}.deleted = 0"));

            $this->add_condition(
                (new filter(
                    profile_field_current_user::class,
                    'supervisor',
                    new lang_string('condition:supervisor', 'local_taskflow'),
                    $userentity->get_entity_name(),
                    "{$sd}.data"
                ))
                ->add_joins($userentity->get_joins())
                ->add_join($supervisordatajoin)
            );

            // Deputies of the supervisor. The "deputy is current user" condition
            // gives deputies the assignments of the supervisors they stand in for.
            $deputyfieldid = deputy::get_deputy_field_id();
            if ($deputyfieldid > 0) {
                $deputyentity = (new deputy())->set_table_alias('user', $sv);
                $dd = $deputyentity->get_table_alias('user_info_data');
                $this->add_entity($deputyentity
                    ->add_joins($supervisorentity->get_joins())
                    ->add_join("LEFT JOIN {user_info_data} {$dd}
                                       ON {$dd}.userid = {$sv}.id
                                      AND {$dd}.fieldid = {$deputyfieldid}"));
            }
        }

        $this->add_all_from_entities();
    }

    /**
     * Return the ID of the custom profile field holding the supervisor, 0 if not configured.
     *
     * @return int
     */
    public static function get_supervisor_field_id(): int {
        global $DB;

        $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        if (empty($shortname)) {
            return 0;
        }
        return (int) $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'rule:rulename',
            'assignment:targetnames',
            'assignment:status',
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
            'assignment:duedate' => SORT_DESC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'assignment:status',
            'assignment:assigneddate',
            'assignment:duedate',
            'rule:rulename',
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
            'assignment:assigneddate',
            'assignment:duedate',
        ];
    }

    /**
     * Default condition values: only active assignments.
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        return [
            'assignment:active_operator' => boolean_select::CHECKED,
        ];
    }
}
