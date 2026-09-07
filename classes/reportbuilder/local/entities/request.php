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
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_taskflow\local\requests;
use local_taskflow\local\requests\request_receivers\receivers\hr_receiver;
use local_taskflow\local\requests\request_receivers\receivers\supervisor_receiver;
use local_taskflow\local\requests\request_types\requests_manager;

/**
 * Taskflow requests entity for Report Builder.
 *
 * Defines columns and filters from the {local_taskflow_requests} table. The
 * datasource decides which request row is joined (the assignment datasource
 * joins the latest request of an assignment). The number of open requests is
 * aggregated per assignment, so the datasource has to hand over the alias of
 * the {local_taskflow_assignment} table via set_table_alias().
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request extends base {
    /** @var string Alias of the aggregated open requests join. */
    private string $opencountalias = '';

    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'local_taskflow_requests',
            'local_taskflow_assignment',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:request', 'local_taskflow');
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
        $rq = $this->get_table_alias('local_taskflow_requests');
        $oc = $this->get_open_count_alias();
        $columns = [];

        // Request type.
        $columns[] = (new column(
            'type',
            new lang_string('requesttype', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$rq}.request")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null) {
                    return '';
                }
                return requests::resolve_status((int) $value);
            });

        // Processing status (open, confirmed, declined).
        $columns[] = (new column(
            'treated',
            new lang_string('treatedstatus', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$rq}.treated")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null) {
                    return '';
                }
                return requests::resolve_treated((int) $value);
            });

        // Receiver (supervisor or HR).
        $columns[] = (new column(
            'receiver',
            new lang_string('requestreceiver', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$rq}.forhr")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null) {
                    return '';
                }
                return self::get_receiver_options()[(int) $value] ?? '';
            });

        // Comment of the requesting user.
        $columns[] = (new column(
            'comment',
            new lang_string('comment', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$rq}.comment")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                return s((string) $value);
            });

        // Timestamp columns.
        $timestamps = [
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
                ->add_field("{$rq}.{$field}")
                ->set_is_sortable(true)
                ->add_callback([format::class, 'userdate']);
        }

        // Number of open requests of the assignment.
        $columns[] = (new column(
            'openrequests',
            new lang_string('openrequests', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_open_count_join())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("COALESCE({$oc}.openrequests, 0)", 'openrequests')
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $rq = $this->get_table_alias('local_taskflow_requests');
        $oc = $this->get_open_count_alias();
        $filters = [];

        // Request type filter.
        $filters[] = (new filter(
            select::class,
            'type',
            new lang_string('requesttype', 'local_taskflow'),
            $this->get_entity_name(),
            "{$rq}.request"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return self::get_type_options();
            });

        // Processing status filter.
        $filters[] = (new filter(
            select::class,
            'treated',
            new lang_string('treatedstatus', 'local_taskflow'),
            $this->get_entity_name(),
            "{$rq}.treated"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return self::get_treated_options();
            });

        // Receiver filter.
        $filters[] = (new filter(
            select::class,
            'receiver',
            new lang_string('requestreceiver', 'local_taskflow'),
            $this->get_entity_name(),
            "{$rq}.forhr"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return self::get_receiver_options();
            });

        // Comment filter.
        $filters[] = (new filter(
            text::class,
            'comment',
            new lang_string('comment', 'local_taskflow'),
            $this->get_entity_name(),
            "{$rq}.comment"
        ))
            ->add_joins($this->get_joins());

        // Date filters.
        $dates = [
            'timecreated' => new lang_string('timecreated', 'local_taskflow'),
            'timemodified' => new lang_string('timemodified', 'local_taskflow'),
        ];
        foreach ($dates as $field => $title) {
            $filters[] = (new filter(
                date::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$rq}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Number of open requests filter.
        $filters[] = (new filter(
            number::class,
            'openrequests',
            new lang_string('openrequests', 'local_taskflow'),
            $this->get_entity_name(),
            "COALESCE({$oc}.openrequests, 0)"
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_open_count_join());

        return $filters;
    }

    /**
     * Options of the request type filter: request type ID => name.
     *
     * @return array
     */
    public static function get_type_options(): array {
        $options = [];
        $requesttypes = (new requests_manager())->get_request_types_with_ids();
        foreach (array_keys($requesttypes) as $id) {
            $options[(int) $id] = requests::resolve_status((int) $id);
        }
        ksort($options);
        return $options;
    }

    /**
     * Options of the processing status filter: treated status => name.
     *
     * @return array
     */
    public static function get_treated_options(): array {
        $options = [];
        $statuses = [
            requests::TREATED_STATUS_UNTREATED,
            requests::TREATED_STATUS_CONFIRMED,
            requests::TREATED_STATUS_DECLINED,
        ];
        foreach ($statuses as $status) {
            $options[$status] = requests::resolve_treated($status);
        }
        return $options;
    }

    /**
     * Options of the receiver filter: receiver ID => name.
     *
     * @return array
     */
    public static function get_receiver_options(): array {
        return [
            supervisor_receiver::ID => get_string('supervisorreceiver', 'local_taskflow'),
            hr_receiver::ID => get_string('hrreceiver', 'local_taskflow'),
        ];
    }

    /**
     * Alias of the aggregated open requests join.
     *
     * @return string
     */
    private function get_open_count_alias(): string {
        if ($this->opencountalias === '') {
            $this->opencountalias = database::generate_alias();
        }
        return $this->opencountalias;
    }

    /**
     * Join providing the number of open requests per assignment.
     *
     * @return string
     */
    private function get_open_count_join(): string {
        $as = $this->get_table_alias('local_taskflow_assignment');
        $oc = $this->get_open_count_alias();
        // Report builder joins cannot carry bound parameters, so the status is inlined.
        $untreated = requests::TREATED_STATUS_UNTREATED;

        return "LEFT JOIN (
                    SELECT r.assignmentid,
                           COUNT(r.id) AS openrequests
                      FROM {local_taskflow_requests} r
                     WHERE r.treated = {$untreated}
                  GROUP BY r.assignmentid
                ) {$oc} ON {$oc}.assignmentid = {$as}.id";
    }
}
