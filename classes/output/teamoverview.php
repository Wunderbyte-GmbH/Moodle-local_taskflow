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

namespace local_taskflow\output;

use context_system;
use core_component;
use core_user;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\personnotes\person_notes_service;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\shortcodes;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Team overview for supervisors: counters, one tile per team member, team assignments, requests,
 * bookings to approve and the latest notes. Built from the existing shortcodes and services.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teamoverview implements adapter_view_interface, renderable, templatable {
    /** @var int Notes shown in the "latest notes" card. */
    private const NOTES_SHOWN = 5;

    /** @var moodle_url */
    private moodle_url $pageurl;

    /** @var int */
    private int $supervisorid;

    /**
     * Constructor.
     *
     * @param moodle_url $pageurl
     * @param int $supervisorid
     */
    public function __construct(moodle_url $pageurl, int $supervisorid) {
        $this->pageurl = $pageurl;
        $this->supervisorid = $supervisorid;
    }

    /**
     * Whether the viewer may open the team overview.
     *
     * @param int $viewerid
     * @return bool
     */
    public static function can_view(int $viewerid): bool {
        return has_capability('local/taskflow:issupervisor', context_system::instance(), $viewerid);
    }

    /**
     * The template of this view; adapters override this to swap the design.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/dashboardpage';
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $env = new stdClass();
        $next = fn($a) => $a;
        $team = array_map('intval', supervisor::get_visible_subordinate_ids($this->supervisorid));
        $assignments = $this->load_team_assignments($team);

        $approvals = '';
        if (
            core_component::get_plugin_directory('mod', 'booking')
            && class_exists('\\bookingextension_confirmation_supervisor\\local\\confirmbooking')
            && get_config('bookingextension_confirmation_supervisor', 'confirmationsupervisorenabled')
        ) {
            $approvals = \mod_booking\shortcodes::listtoapprove('', ['reduced' => 1], null, $env, $next) ?: '';
        }

        return [
            'isteam' => true,
            'kpis' => $this->export_kpis($team, $assignments),
            'team' => $this->export_team($team, $assignments),
            'hasteam' => !empty($team),
            'teamcount' => count($team),
            'assignmentstable' => shortcodes::supervisorassignments('', [
                'columns' => 'fullname,rulename,targets,duedate,statussortkey,actions',
                'filter' => 'status,completed',
                'deputyselect' => 1,
                'noheading' => 1,
                'bulkactions' => 1,
                'statusbadges' => 1,
                'toolbartemplate' => 1,
            ], null, $env, $next),
            'chart' => shortcodes::supervisorassignments(
                '',
                ['chart' => 1, 'noheading' => 1, 'chartlegend' => 'right'],
                null,
                $env,
                $next
            ),
            'requeststable' => shortcodes::requests(
                '',
                ['noheader' => 1, 'bulkactions' => 1, 'toolbartemplate' => 1],
                null,
                $env,
                $next
            ),
            'approvals' => $approvals,
            'hasapprovals' => $approvals !== '',
            'notes' => $this->export_notes($team),
            'organisationurl' => has_capability('local/taskflow:vieworganisation', context_system::instance())
                ? (new moodle_url('/local/taskflow/units.php'))->out(false)
                : '',
        ];
    }

    /**
     * All assignments of the team members, grouped by user.
     *
     * @param int[] $team
     * @return array userid => assignment records
     */
    private function load_team_assignments(array $team): array {
        global $DB;
        if (empty($team)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($team, SQL_PARAMS_NAMED, 'u');
        $grouped = array_fill_keys($team, []);
        foreach ($DB->get_records_select('local_taskflow_assignment', "userid $insql", $params) as $assignment) {
            $grouped[(int)$assignment->userid][] = $assignment;
        }
        return $grouped;
    }

    /**
     * Counters over the team.
     *
     * @param int[] $team
     * @param array $assignments
     * @return array
     */
    private function export_kpis(array $team, array $assignments): array {
        global $DB;
        $completed = assignment_status_facade::get_status_identifier('completed');
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $done = 0;
        $open = 0;
        $late = 0;
        foreach ($assignments as $list) {
            foreach ($list as $assignment) {
                if ((int)$assignment->status === $completed) {
                    $done++;
                } else if (!empty($assignment->active)) {
                    $open++;
                    if ((int)$assignment->status === $overdue) {
                        $late++;
                    }
                }
            }
        }
        $requests = 0;
        if (!empty($team)) {
            [$insql, $params] = $DB->get_in_or_equal($team, SQL_PARAMS_NAMED, 'u');
            // The column "status" holds the request type; "treated" is the processing state (0 = open).
            $requests = (int)$DB->count_records_select('local_taskflow_requests', "treated = 0 AND userid $insql", $params);
        }
        return [
            self::kpi(count($team), taskflow_stringmanager::get_string('kpi_teammembers'), 'fa-users', 'text-primary'),
            self::kpi($done, taskflow_stringmanager::get_string('kpi_completed'), 'fa-check-circle', 'text-success'),
            self::kpi($open, taskflow_stringmanager::get_string('kpi_open'), 'fa-tasks', 'text-primary'),
            self::kpi(
                $late,
                taskflow_stringmanager::get_string('kpi_overdue'),
                'fa-exclamation-triangle',
                $late ? 'text-danger' : 'text-muted'
            ),
            self::kpi($requests, taskflow_stringmanager::get_string('kpi_openrequests'), 'fa-envelope-open-text', 'text-warning'),
        ];
    }

    /**
     * One counter tile.
     *
     * @param int $value
     * @param string $label
     * @param string $icon Font Awesome class.
     * @param string $class Colour class of the icon.
     * @param string $url Optional link.
     * @return array
     */
    private static function kpi(int $value, string $label, string $icon, string $class, string $url = ''): array {
        return ['value' => $value, 'label' => $label, 'icon' => $icon, 'class' => $class, 'url' => $url];
    }

    /**
     * One tile per team member with their assignment state.
     *
     * @param int[] $team
     * @param array $assignments
     * @return array
     */
    private function export_team(array $team, array $assignments): array {
        global $OUTPUT;
        $completed = assignment_status_facade::get_status_identifier('completed');
        $overdue = assignment_status_facade::get_status_identifier('overdue');
        $tiles = [];
        foreach ($team as $userid) {
            $user = core_user::get_user($userid);
            if (!$user) {
                continue;
            }
            $total = 0;
            $done = 0;
            $late = 0;
            foreach ($assignments[$userid] ?? [] as $assignment) {
                if (empty($assignment->active) && (int)$assignment->status !== $completed) {
                    continue;
                }
                $total++;
                if ((int)$assignment->status === $completed) {
                    $done++;
                } else if ((int)$assignment->status === $overdue) {
                    $late++;
                }
            }
            if ($late) {
                $state = 'danger';
                $statetext = taskflow_stringmanager::get_string('teamtile_overdue', $late);
            } else if ($total && $done === $total) {
                $state = 'success';
                $statetext = taskflow_stringmanager::get_string('teamtile_alldone');
            } else if ($total) {
                $state = 'primary';
                $statetext = taskflow_stringmanager::get_string('teamtile_progress', (object)['done' => $done, 'total' => $total]);
            } else {
                $state = 'secondary';
                $statetext = taskflow_stringmanager::get_string('teamtile_none');
            }
            $tiles[] = [
                'userid' => $userid,
                'fullname' => fullname($user),
                'picture' => $OUTPUT->user_picture($user, ['size' => 44, 'link' => false]),
                'state' => $state,
                'statetext' => $statetext,
                'percent' => $total ? (int)round($done * 100 / $total) : 0,
                'url' => (new moodle_url('/local/taskflow/person.php', ['id' => $userid]))->out(false),
            ];
        }
        // Overdue persons first, then by progress, then by name.
        usort($tiles, function (array $a, array $b): int {
            return [$a['state'] !== 'danger', $a['percent'], $a['fullname']]
                <=> [$b['state'] !== 'danger', $b['percent'], $b['fullname']];
        });
        return $tiles;
    }

    /**
     * The latest notes about team members the viewer may read.
     *
     * @param int[] $team
     * @return array
     */
    private function export_notes(array $team): array {
        global $DB;
        $rows = [];
        if (empty($team)) {
            return $rows;
        }
        $readable = array_values(array_filter($team, fn($userid) => person_notes_service::can_view($this->supervisorid, $userid)));
        if (empty($readable)) {
            return $rows;
        }
        [$insql, $params] = $DB->get_in_or_equal($readable, SQL_PARAMS_NAMED, 'u');
        $notes = $DB->get_records_select(
            'local_taskflow_person_notes',
            "userid $insql",
            $params,
            'timecreated DESC, id DESC',
            '*',
            0,
            self::NOTES_SHOWN
        );
        foreach ($notes as $note) {
            $person = core_user::get_user((int)$note->userid);
            $author = core_user::get_user((int)$note->usermodified);
            $rows[] = [
                'person' => $person ? fullname($person) : '',
                'personurl' => (new moodle_url('/local/taskflow/person.php', ['id' => $note->userid]))->out(false),
                'text' => format_text($note->note, (int)$note->noteformat, ['context' => context_system::instance()]),
                'author' => $author ? fullname($author) : '',
                'date' => userdate((int)$note->timecreated, get_string('strftimedatetime', 'langconfig')),
            ];
        }
        return $rows;
    }
}
