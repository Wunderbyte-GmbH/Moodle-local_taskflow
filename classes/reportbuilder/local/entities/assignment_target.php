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

namespace local_taskflow\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use stdClass;

/**
 * Competency target of a taskflow assignment for Report Builder.
 *
 * One target is one element of the targets JSON of an assignment with the
 * target type "competency". The datasource joins {competency} to the JSON (see
 * helpers\target_sql) and hands over the alias of {local_taskflow_assignment}
 * via set_table_alias(), so the name stored on the target can be resolved.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_target extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'competency',
            'local_taskflow_assignment',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:assignmenttarget', 'local_taskflow');
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this->add_filter($filter);
            $this->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $c = $this->get_table_alias('competency');
        $as = $this->get_table_alias('local_taskflow_assignment');
        $columns = [];

        // Competency ID.
        $columns[] = (new column(
            'competencyid',
            new lang_string('competencyid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$c}.id")
            ->set_is_sortable(true);

        // Competency name (short name).
        $columns[] = (new column(
            'name',
            new lang_string('competency', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$c}.shortname")
            ->set_is_sortable(true);

        // Competency ID number.
        $columns[] = (new column(
            'idnumber',
            new lang_string('idnumber'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$c}.idnumber")
            ->set_is_sortable(true);

        // Target name as stored on the assignment when it was created, falling
        // back to the current competency short name.
        $columns[] = (new column(
            'targetname',
            new lang_string('targetname', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$as}.targets", 'targets')
            ->add_field("{$c}.id", 'competencyid')
            ->add_field("{$c}.shortname", 'shortname')
            ->set_is_sortable(false)
            ->set_disabled_aggregation_all()
            ->add_callback(static function ($value, stdClass $row): string {
                foreach (assignment::decode_targets($value) as $target) {
                    if (
                        ($target->targettype ?? '') === 'competency'
                        && (string) ($target->targetid ?? '') === (string) ($row->competencyid ?? '')
                    ) {
                        return s(assignment::get_target_name($target));
                    }
                }
                return s((string) ($row->shortname ?? ''));
            });

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $c = $this->get_table_alias('competency');
        $filters = [];

        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('competency', 'local_taskflow'),
            $this->get_entity_name(),
            "{$c}.shortname"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'idnumber',
            new lang_string('idnumber'),
            $this->get_entity_name(),
            "{$c}.idnumber"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
