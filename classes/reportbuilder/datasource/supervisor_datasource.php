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

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\helpers\database;
use local_taskflow\reportbuilder\local\entities\deputy;

/**
 * Supervisor datasource for Report Builder.
 *
 * One row per user, with the deputies stored in the user's deputy profile
 * field. By default restricted to users who have at least one deputy, which
 * lists all supervisors that delegated to somebody.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supervisor_datasource extends datasource {
    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:supervisor', 'local_taskflow');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        global $CFG;

        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->set_main_table('user', $u);
        $this->add_entity($userentity);

        $paramguest = database::generate_param_name();
        $this->add_base_condition_sql("{$u}.id != :{$paramguest} AND {$u}.deleted = 0", [
            $paramguest => $CFG->siteguest,
        ]);

        $deputyfieldid = deputy::get_deputy_field_id();
        if ($deputyfieldid > 0) {
            $deputyentity = (new deputy())->set_table_alias('user', $u);
            $dd = $deputyentity->get_table_alias('user_info_data');
            $this->add_entity($deputyentity
                ->add_join("LEFT JOIN {user_info_data} {$dd}
                                   ON {$dd}.userid = {$u}.id
                                  AND {$dd}.fieldid = {$deputyfieldid}"));
        }

        $this->add_all_from_entities();
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        $columns = [
            'user:fullnamewithlink',
            'user:email',
        ];
        if (deputy::get_deputy_field_id() > 0) {
            $columns[] = 'deputy:deputies';
            $columns[] = 'deputy:deputycount';
        }
        return $columns;
    }

    /**
     * Default column sorting.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'user:fullnamewithlink' => SORT_ASC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        $filters = [
            'user:fullname',
        ];
        if (deputy::get_deputy_field_id() > 0) {
            $filters[] = 'deputy:deputy';
        }
        return $filters;
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        $conditions = [];
        if (deputy::get_deputy_field_id() > 0) {
            $conditions[] = 'deputy:hasdeputies';
            if (assignment_datasource::get_supervisor_field_id() > 0) {
                $conditions[] = 'deputy:issupervisor';
            }
        }
        return $conditions;
    }

    /**
     * Default condition values: only users with deputies.
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        if (deputy::get_deputy_field_id() > 0) {
            return [
                'deputy:hasdeputies_operator' => boolean_select::CHECKED,
            ];
        }
        return [];
    }
}
