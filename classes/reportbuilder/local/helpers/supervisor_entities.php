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

namespace local_taskflow\reportbuilder\local\helpers;

use core\lang_string;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\filter;
use local_taskflow\reportbuilder\datasource\assignment_datasource;
use local_taskflow\reportbuilder\local\entities\deputy;
use local_taskflow\reportbuilder\local\filters\profile_field_current_user;

/**
 * Adds the supervisor and deputy entities of an assigned user to a datasource.
 *
 * Shared by the datasources whose main table is {local_taskflow_assignment}.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait supervisor_entities {
    /**
     * Add the supervisor user entity, the "Supervisor is current user" condition
     * and the deputy entity, when the active adapter maps a supervisor profile field.
     *
     * @param user $userentity The (already added) entity of the assigned user
     */
    protected function add_supervisor_entities(user $userentity): void {
        global $DB;

        $supervisorfieldid = assignment_datasource::get_supervisor_field_id();
        if ($supervisorfieldid <= 0) {
            return;
        }

        $u = $userentity->get_table_alias('user');
        $sd = database::generate_alias();
        $supervisordatajoin = "LEFT JOIN {user_info_data} {$sd}
                                      ON {$sd}.userid = {$u}.id
                                     AND {$sd}.fieldid = {$supervisorfieldid}";

        $supervisorentity = (new user())
            ->set_entity_name(assignment_datasource::SUPERVISOR_ENTITY)
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
}
