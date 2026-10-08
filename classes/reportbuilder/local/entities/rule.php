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
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Taskflow rules entity for Report Builder.
 *
 * Defines columns and filters from the {local_taskflow_rules} table.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'local_taskflow_rules',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('rule', 'local_taskflow');
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
        $r = $this->get_table_alias('local_taskflow_rules');
        $columns = [];

        // ID.
        $columns[] = (new column(
            'id',
            new lang_string('ruleid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$r}.id")
            ->set_is_sortable(true);

        // Rule name.
        $columns[] = (new column(
            'rulename',
            new lang_string('rulename', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$r}.rulename")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return format_string((string) $value);
            });

        // Unit ID.
        $columns[] = (new column(
            'unitid',
            new lang_string('unitid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$r}.unitid")
            ->set_is_sortable(true);

        // Is active.
        $columns[] = (new column(
            'isactive',
            new lang_string('isactive', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$r}.isactive")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $r = $this->get_table_alias('local_taskflow_rules');
        $filters = [];

        // ID filter.
        $filters[] = (new filter(
            number::class,
            'id',
            new lang_string('ruleid', 'local_taskflow'),
            $this->get_entity_name(),
            "{$r}.id"
        ))
            ->add_joins($this->get_joins());

        // Rule name filter.
        $filters[] = (new filter(
            text::class,
            'rulename',
            new lang_string('rulename', 'local_taskflow'),
            $this->get_entity_name(),
            "{$r}.rulename"
        ))
            ->add_joins($this->get_joins());

        // Unit ID filter.
        $filters[] = (new filter(
            number::class,
            'unitid',
            new lang_string('unitid', 'local_taskflow'),
            $this->get_entity_name(),
            "{$r}.unitid"
        ))
            ->add_joins($this->get_joins());

        // Is active filter.
        $filters[] = (new filter(
            boolean_select::class,
            'isactive',
            new lang_string('isactive', 'local_taskflow'),
            $this->get_entity_name(),
            "{$r}.isactive"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
