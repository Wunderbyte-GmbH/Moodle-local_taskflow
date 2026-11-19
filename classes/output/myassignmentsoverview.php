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
 * Dashboard view of the assignments of one user.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\output;

use core\url;
use local_taskflow\local\actions\targets\targets_factory;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\taskflow_stringmanager;
use renderable;
use renderer_base;
use templatable;

/**
 * Dashboard view of the assignments of one user: key figures, next due date and one card per assignment.
 *
 * @package local_taskflow
 */
class myassignmentsoverview implements renderable, templatable {
    /** State of an assignment that is overdue (also reprimand and sanction). */
    public const STATE_OVERDUE = 'overdue';

    /** State of an assignment that still has to be done. */
    public const STATE_OPEN = 'open';

    /** State of an assignment that is paused. */
    public const STATE_PAUSED = 'paused';

    /** State of a completed assignment. */
    public const STATE_COMPLETED = 'completed';

    /** Sort order of the states in the card list. */
    private const STATE_ORDER = [
        self::STATE_OVERDUE => 0,
        self::STATE_OPEN => 1,
        self::STATE_PAUSED => 2,
        self::STATE_COMPLETED => 3,
    ];

    /**
     * Data for the template.
     *
     * @var array
     */
    private $data = [];

    /**
     * Prepare the data for the dashboard.
     *
     * @param int $userid
     * @param array $arguments Shortcode arguments (headline, description, hidecompleted).
     */
    public function __construct(int $userid, array $arguments = []) {
        $assignments = self::get_assignments($userid);

        $counts = array_fill_keys(array_keys(self::STATE_ORDER), 0);
        $targetstotal = 0;
        $targetscompleted = 0;
        $nextdue = null;
        foreach ($assignments as $assignment) {
            $counts[$assignment['state']]++;
            $targetstotal += $assignment['targetstotal'];
            $targetscompleted += $assignment['targetscompleted'];
            if (
                $assignment['hasdue']
                && in_array($assignment['state'], [self::STATE_OVERDUE, self::STATE_OPEN])
                && ($nextdue === null || $assignment['duedatetimestamp'] < $nextdue['duedatetimestamp'])
            ) {
                $nextdue = $assignment;
            }
        }

        // Without targets, the progress is the share of completed assignments.
        if ($targetstotal > 0) {
            $progress = (int)round($targetscompleted / $targetstotal * 100);
        } else {
            $progress = empty($assignments) ? 0 : (int)round($counts[self::STATE_COMPLETED] / count($assignments) * 100);
        }

        $this->data = [
            'uniqid' => uniqid(),
            'headline' => $arguments['headline'] ?? taskflow_stringmanager::get_string('myassignments'),
            'description' => $arguments['description'] ?? taskflow_stringmanager::get_string('mydashboard:description'),
            'hasassignments' => !empty($assignments),
            'progress' => $progress,
            'kpis' => [
                [
                    'key' => self::STATE_OPEN,
                    'icon' => 'fa-hourglass-half',
                    'value' => $counts[self::STATE_OPEN] + $counts[self::STATE_PAUSED],
                    'label' => taskflow_stringmanager::get_string('mydashboard:open'),
                ],
                [
                    'key' => self::STATE_OVERDUE,
                    'icon' => 'fa-exclamation-triangle',
                    'value' => $counts[self::STATE_OVERDUE],
                    'label' => taskflow_stringmanager::get_string('statusoverdue'),
                ],
                [
                    'key' => self::STATE_COMPLETED,
                    'icon' => 'fa-check',
                    'value' => $counts[self::STATE_COMPLETED],
                    'label' => taskflow_stringmanager::get_string('statuscompleted'),
                ],
            ],
            'hidecompleted' => !empty($arguments['hidecompleted']),
            'hasnextdue' => $nextdue !== null,
            'nextdue' => $nextdue,
            'assignments' => $assignments,
        ];
    }

    /**
     * Returns the active assignments of the user, prepared for the template and sorted:
     * overdue first, then open by due date, then paused and completed.
     *
     * @param int $userid
     * @return array
     */
    private static function get_assignments(int $userid): array {
        global $DB;

        // Planned and not relevant assignments have a negative status and are not shown.
        $sql = "SELECT ta.id, ta.status, ta.duedate, ta.completeddate, ta.targets, tr.rulename
                  FROM {local_taskflow_assignment} ta
             LEFT JOIN {local_taskflow_rules} tr ON tr.id = ta.ruleid
                 WHERE ta.userid = :userid
                   AND ta.active = 1
                   AND ta.status >= 0";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);

        $assignments = [];
        foreach ($records as $record) {
            $assignments[] = self::prepare_assignment($record);
        }

        usort($assignments, function ($a, $b) {
            return [self::STATE_ORDER[$a['state']], $a['hasdue'] ? 0 : 1, $a['duedatetimestamp'], $a['name']]
                <=> [self::STATE_ORDER[$b['state']], $b['hasdue'] ? 0 : 1, $b['duedatetimestamp'], $b['name']];
        });
        return $assignments;
    }

    /**
     * Prepares one assignment record for the template.
     *
     * @param \stdClass $record
     * @return array
     */
    private static function prepare_assignment(\stdClass $record): array {
        $state = self::get_state((int)$record->status);

        $targets = [];
        foreach (json_decode($record->targets ?? '') ?: [] as $target) {
            $name = $target->targetname ?? '';
            if ($name === '' && !empty($target->targettype) && !empty($target->targetid)) {
                $name = targets_factory::get_name($target->targettype, $target->targetid);
            }
            $targets[] = [
                'name' => format_string($name),
                'completed' => !empty($target->completionstatus) && $target->completionstatus == 1,
            ];
        }
        $completedtargets = count(array_filter(array_column($targets, 'completed')));
        $totaltargets = count($targets);
        if ($totaltargets > 0) {
            $percent = (int)round($completedtargets / $totaltargets * 100);
        } else {
            $percent = $state === self::STATE_COMPLETED ? 100 : 0;
        }

        $hasdue = !empty($record->duedate);
        $name = format_string($record->rulename ?? '');
        return [
            'id' => (int)$record->id,
            'name' => $name,
            // Lower case text the search field (amd/src/myassignmentsdashboard.js) matches against.
            'searchtext' => \core_text::strtolower(
                strip_tags(implode(' ', array_merge([$name], array_column($targets, 'name'))))
            ),
            'url' => (new url('/local/taskflow/assignment.php', ['id' => $record->id]))->out(false),
            'state' => $state,
            'isoverdue' => $state === self::STATE_OVERDUE,
            'iscompleted' => $state === self::STATE_COMPLETED,
            'statuslabel' => assignment_status_facade::get_specific_names($record->status),
            'hasdue' => $hasdue,
            'duedatetimestamp' => $hasdue ? (int)$record->duedate : 0,
            'duedate' => $hasdue ? userdate($record->duedate, get_string('strftimedate', 'langconfig')) : '',
            'duerelative' => self::get_relative_due($state, $record),
            'targets' => $targets,
            'hastargets' => $totaltargets > 0,
            'targetstotal' => $totaltargets,
            'targetscompleted' => $completedtargets,
            'targetsprogress' => taskflow_stringmanager::get_string(
                'mydashboard:completedof',
                (object)['completed' => $completedtargets, 'total' => $totaltargets]
            ),
            'percent' => $percent,
        ];
    }

    /**
     * Maps an assignment status to one of the dashboard states.
     *
     * @param int $status
     * @return string
     */
    private static function get_state(int $status): string {
        $overdue = [
            assignment_status_facade::get_status_identifier('overdue'),
            assignment_status_facade::get_status_identifier('reprimand'),
            assignment_status_facade::get_status_identifier('sanction'),
        ];
        $paused = [
            assignment_status_facade::get_status_identifier('paused'),
            assignment_status_facade::get_status_identifier('droppedout'),
        ];
        if (in_array($status, $overdue)) {
            return self::STATE_OVERDUE;
        }
        if (in_array($status, $paused)) {
            return self::STATE_PAUSED;
        }
        if ($status == assignment_status_facade::get_status_identifier('completed')) {
            return self::STATE_COMPLETED;
        }
        return self::STATE_OPEN;
    }

    /**
     * Returns a short text relative to today, e.g. "Due in 5 days" or "Completed on 3 May 2026".
     *
     * @param string $state
     * @param \stdClass $record
     * @return string
     */
    private static function get_relative_due(string $state, \stdClass $record): string {
        if ($state === self::STATE_COMPLETED) {
            if (empty($record->completeddate)) {
                return '';
            }
            return taskflow_stringmanager::get_string(
                'mydashboard:completedon',
                userdate($record->completeddate, get_string('strftimedate', 'langconfig'))
            );
        }
        if (empty($record->duedate)) {
            return taskflow_stringmanager::get_string('mydashboard:nodue');
        }

        $days = (int)floor((usergetmidnight($record->duedate) - usergetmidnight(time())) / DAYSECS);
        if ($days > 1) {
            return taskflow_stringmanager::get_string('mydashboard:duein', $days);
        }
        if ($days === 1) {
            return taskflow_stringmanager::get_string('mydashboard:duetomorrow');
        }
        if ($days === 0) {
            return taskflow_stringmanager::get_string('mydashboard:duetoday');
        }
        if ($days === -1) {
            return taskflow_stringmanager::get_string('mydashboard:overdueyesterday');
        }
        return taskflow_stringmanager::get_string('mydashboard:overdueby', abs($days));
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return $this->data;
    }
}
