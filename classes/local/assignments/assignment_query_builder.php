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
 * Assignemnt query builder with builder pattern.
 *
 * @package local_taskflow
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\assignments;

use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Class unit
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_query_builder {
     /** @var array $where , stores the where-sql parameters. */
    private array $where = [];

     /** @var array $parameters , stores the parameters for data injection. */
    private array $parameters = [];

    /**
     * Adds the activity state for the sql-query.
     * @param ?int $active
     * @return self
     */
    public function where_active(?int $active): self {
        if ($active === 0 || $active === 1) {
            $this->where[] = 'active = :active';
            $this->parameters['active'] = $active;
        }
        return $this;
    }

    /**
     * Adds the clarification setup for the sql-query.
     * @param bool $toclarify
     * @return self
     */
    public function where_toclarify_supervisor(bool $toclarify): self {
        if ($toclarify) {
            $this->where[] = 'status = :status_overdue';
            $this->parameters['status_overdue'] = assignment_status_facade::get_status_identifier('overdue');
            $this->where[] = 'overduecounter <= 1';
            $this->where[] = 'prolongedcounter <= 2';
        }
        return $this;
    }

    /**
     * Adds the clarification setup for the sql-query.
     * @param bool $toclarify
     * @return self
     */
    public function where_toclarify_assignment(bool $toclarify): self {
        if ($toclarify) {
            $this->where[] = '(status >= :statusoverdue ) AND (status < :statuscompleted )';
            $this->parameters['statusoverdue'] = assignment_status_facade::get_status_identifier('overdue');
            $this->parameters['statuscompleted'] = assignment_status_facade::get_status_identifier('completed');
        }
        return $this;
    }

    /**
     * Add assignment status to sql query generic.
     * @param ?string $statuses
     * @return self
     */
    public function where_assignmentstatus(?string $statuses): self {
        if (!empty($statuses)) {
            $statuses = explode(',', $statuses);
            $availableassignmentstatus = assignment_status_facade::get_all_labels();
            $validassignmentstatus = array_unique(array_intersect($statuses, $availableassignmentstatus));
            $orwhere = [];
            foreach ($validassignmentstatus as $validstatus) {
                if (!isset($this->parameters[$validstatus])) {
                    $orwhere[] = 'status = :' . $validstatus;
                    $this->parameters[$validstatus] = assignment_status_facade::get_status_identifier($validstatus);
                }
            }
            if (!empty($orwhere)) {
                $this->where[] = '(' . implode(' OR ', $orwhere) . ')';
            }
        }
        return $this;
    }

    /**
     * Add assignment counters to sql query generic.
     * @param ?string $counters
     * @return self
     */
    public function where_assignmentcounter(?string $counters): self {
        if (!empty($counters)) {
            $assignmentcounters = explode(',', $counters);
            $availableassignmentstatus = assignment_status_facade::get_all_labels();
            foreach ($assignmentcounters as $key => $assignmentcounter) {
                $counteroperators = explode(';', html_entity_decode($assignmentcounter));
                if (
                    count($counteroperators) == 3 &&
                    in_array($counteroperators[0], $availableassignmentstatus) &&
                    $this->is_valid_operation($counteroperators[1]) &&
                    is_number($counteroperators[2])
                ) {
                    $label = $counteroperators[0] . 'counter';
                    $this->where[] = $label . $counteroperators[1] . ':' . $label  . $key;
                    $this->parameters[$label  . $key] = $counteroperators[2];
                }
            }
        }
        return $this;
    }

    /**
     * Check if it is a valid comparison operation.
     * @param string $operation
     * @return bool
     */
    private function is_valid_operation($operation): bool {
        $validoperations = ['=', '>', '<', '>=', '<=', '<>', '!='];
        return in_array($operation, $validoperations, true);
    }

    /**
     * Add assignment id to sql query generic.
     * @param ?string $assignmentid
     * @return self
     */
    public function where_assignmentid(?string $assignmentid): self {
        if (!empty($assignmentid)) {
            $this->where[] = "id = :assignmentid";
            $this->parameters['assignmentid'] = $assignmentid;
        }
        return $this;
    }

    /**
     * Add userid to sql query generic.
     * @param ?string $userid
     * @return self
     */
    public function where_userid(?string $userid): self {
        if (!empty($userid)) {
            $this->where[] = "userid = :userid";
            $this->parameters['userid'] = $userid;
        }
        return $this;
    }

    /**
     * Add assignment status to sql query generic.
     * @param ?array $status
     * @return self
     */
    public function where_status(?array $status): self {
        if (!empty($status)) {
            global $DB;
            [$insql, $inparams] = $DB->get_in_or_equal($status, SQL_PARAMS_NAMED, 'st');
            $this->where[] = "status $insql";
            $this->parameters = array_merge($this->parameters, $inparams);
        }
        return $this;
    }

    /**
     * Return the where and parameter sql query.
     * @return array
     */
    public function get_sql(): array {
        return [$this->where, $this->parameters];
    }
}
