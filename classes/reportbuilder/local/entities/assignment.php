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
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use html_writer;
use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\reportbuilder\local\filters\timestamp_years_past;
use local_taskflow\taskflow_stringmanager;

/**
 * Taskflow assignments entity for Report Builder.
 *
 * Defines columns and filters from the {local_taskflow_assignment} table,
 * including the targets stored as JSON on the assignment.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'local_taskflow_assignment',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('assignment', 'local_taskflow');
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
        $ta = $this->get_table_alias('local_taskflow_assignment');
        $columns = [];

        // Assignment ID.
        $columns[] = (new column(
            'id',
            new lang_string('assignmentid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ta}.id")
            ->set_is_sortable(true);

        // User ID.
        $columns[] = (new column(
            'userid',
            new lang_string('userid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ta}.userid")
            ->set_is_sortable(true);

        // Rule ID.
        $columns[] = (new column(
            'ruleid',
            new lang_string('ruleid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ta}.ruleid")
            ->set_is_sortable(true);

        // Unit ID.
        $columns[] = (new column(
            'unitid',
            new lang_string('unitid', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ta}.unitid")
            ->set_is_sortable(true);

        // Status.
        $columns[] = (new column(
            'status',
            new lang_string('status', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ta}.status")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null) {
                    return '';
                }
                return assignment_status_facade::get_specific_names((int) $value);
            });

        // Active.
        $columns[] = (new column(
            'active',
            new lang_string('active', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$ta}.active")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        // Keep changes on import.
        $columns[] = (new column(
            'keepchanges',
            new lang_string('keepchanges', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$ta}.keepchanges")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        // Timestamp columns.
        $timestamps = [
            'duedate' => new lang_string('duedate', 'local_taskflow'),
            'assigneddate' => new lang_string('assigneddate', 'local_taskflow'),
            'completeddate' => new lang_string('completeddate', 'local_taskflow'),
            'timecreated' => new lang_string('timecreated', 'local_taskflow'),
            'timemodified' => new lang_string('timemodified', 'local_taskflow'),
        ];
        foreach ($timestamps as $field => $title) {
            $columns[] = (new column(
                $field,
                $title,
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_TIMESTAMP)
                ->add_field("{$ta}.{$field}")
                ->set_is_sortable(true)
                ->add_callback([format::class, 'userdate']);
        }

        // Counters.
        $counters = [
            'overduecounter' => new lang_string('overduecounter', 'local_taskflow'),
            'prolongedcounter' => new lang_string('prolongedcounter', 'local_taskflow'),
        ];
        foreach ($counters as $field => $title) {
            $columns[] = (new column(
                $field,
                $title,
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_INTEGER)
                ->add_field("{$ta}.{$field}")
                ->set_is_sortable(true);
        }

        // Targets, rendered one per line with type and completion status.
        $columns[] = (new column(
            'targets',
            new lang_string('targets', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$ta}.targets")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $lines = [];
                foreach (self::decode_targets($value) as $target) {
                    $type = $target->targettype;
                    if (get_string_manager()->string_exists($type, 'local_taskflow')) {
                        $type = taskflow_stringmanager::get_string($type);
                    }
                    $completion = taskflow_stringmanager::get_string(
                        !empty($target->completionstatus) ? 'completed' : 'notcompleted'
                    );
                    $lines[] = html_writer::tag('b', s($type) . ':') . ' '
                        . s(self::get_target_name($target)) . ' (' . s($completion) . ')';
                }
                return implode(html_writer::empty_tag('br'), $lines);
            });

        // Plain comma separated list of target names, suitable for exports.
        $columns[] = (new column(
            'targetnames',
            new lang_string('targetnames', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$ta}.targets")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $names = [];
                foreach (self::decode_targets($value) as $target) {
                    $names[] = self::get_target_name($target);
                }
                return s(implode(', ', array_filter($names)));
            });

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $ta = $this->get_table_alias('local_taskflow_assignment');
        $filters = [];

        // Numeric ID filters.
        $numbers = [
            'id' => new lang_string('assignmentid', 'local_taskflow'),
            'userid' => new lang_string('userid', 'local_taskflow'),
            'ruleid' => new lang_string('ruleid', 'local_taskflow'),
            'unitid' => new lang_string('unitid', 'local_taskflow'),
            'overduecounter' => new lang_string('overduecounter', 'local_taskflow'),
            'prolongedcounter' => new lang_string('prolongedcounter', 'local_taskflow'),
        ];
        foreach ($numbers as $field => $title) {
            $filters[] = (new filter(
                number::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$ta}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Status filter, offering the named statuses of the plugin.
        $filters[] = (new filter(
            select::class,
            'status',
            new lang_string('status', 'local_taskflow'),
            $this->get_entity_name(),
            "{$ta}.status"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return assignment_status_facade::get_all_names();
            });

        // Boolean filters.
        $booleans = [
            'active' => new lang_string('active', 'local_taskflow'),
            'keepchanges' => new lang_string('keepchanges', 'local_taskflow'),
        ];
        foreach ($booleans as $field => $title) {
            $filters[] = (new filter(
                boolean_select::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$ta}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Date filters.
        $dates = [
            'duedate' => new lang_string('duedate', 'local_taskflow'),
            'assigneddate' => new lang_string('assigneddate', 'local_taskflow'),
            'completeddate' => new lang_string('completeddate', 'local_taskflow'),
            'timecreated' => new lang_string('timecreated', 'local_taskflow'),
            'timemodified' => new lang_string('timemodified', 'local_taskflow'),
        ];
        foreach ($dates as $field => $title) {
            $filters[] = (new filter(
                date::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$ta}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Filters for timestamps within the past X years.
        $years = [
            'assigneddateyears' => ['assigneddate', new lang_string('filter:assigneddateyears', 'local_taskflow')],
            'completeddateyears' => ['completeddate', new lang_string('filter:completeddateyears', 'local_taskflow')],
            'duedateyears' => ['duedate', new lang_string('filter:duedateyears', 'local_taskflow')],
        ];
        foreach ($years as $name => [$field, $title]) {
            $filters[] = (new filter(
                timestamp_years_past::class,
                $name,
                $title,
                $this->get_entity_name(),
                "{$ta}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Targets filter (text search in the stored target JSON, e.g. by target name or type).
        $filters[] = (new filter(
            text::class,
            'targets',
            new lang_string('targets', 'local_taskflow'),
            $this->get_entity_name(),
            "{$ta}.targets"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }

    /**
     * Decode the targets JSON stored on an assignment.
     *
     * @param mixed $value Raw column value
     * @return \stdClass[]
     */
    public static function decode_targets($value): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $targets = json_decode($value);
        if (!is_array($targets)) {
            return [];
        }
        return array_values(array_filter($targets, static function ($target): bool {
            return is_object($target) && !empty($target->targettype);
        }));
    }

    /**
     * Return the display name of a target, falling back to the target type class if no name was stored.
     *
     * @param \stdClass $target
     * @return string
     */
    public static function get_target_name(\stdClass $target): string {
        if (!empty($target->targetname)) {
            return (string) $target->targetname;
        }
        if (!empty($target->targetid)) {
            return (string) targets_factory::get_name($target->targettype, $target->targetid);
        }
        return '';
    }
}
