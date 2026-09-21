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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\personnotes\person_notes_service;
use local_taskflow\local\rules\personal_rule_assignment_service;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\output\assignmentsdashboard\myassignmentsprovider;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\taskflow_stringmanager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The person page: profile facts, training KPIs, assignments, individually assigned curricula,
 * completions, certificates and competencies of one user.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personpage implements adapter_view_interface, renderable, templatable {
    /** @var stdClass */
    private stdClass $user;

    /** @var bool */
    private bool $canassign;

    /** @var moodle_url */
    private moodle_url $pageurl;

    /**
     * Constructor.
     *
     * @param stdClass $user
     * @param bool $canassign
     * @param moodle_url $pageurl
     */
    public function __construct(stdClass $user, bool $canassign, moodle_url $pageurl) {
        $this->user = $user;
        $this->canassign = $canassign;
        $this->pageurl = $pageurl;
    }

    /**
     * Whether a viewer may open the person page of a user.
     *
     * Allowed: managers (viewreports) for everybody, supervisors and deputies for their team. Nobody else,
     * not even the person themselves - employees use their own dashboard.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_view(int $viewerid, int $userid): bool {
        return person_notes_service::is_in_scope($viewerid, $userid);
    }

    /**
     * The template of this view; adapters override this to swap the design.
     *
     * @return string
     */
    public function get_template(): string {
        return 'local_taskflow/personpage';
    }

    /**
     * Prepare data for use in a template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $PAGE, $USER;

        $userid = (int)$this->user->id;
        $assignments = $this->load_assignments($userid);

        $data = [
            'userid' => $userid,
            'canassign' => $this->canassign,
            'user' => $this->export_user($output),
            'facts' => $this->export_facts($userid),
            'kpis' => $this->export_kpis($userid, $assignments),
            'personalrules' => $this->export_personal_rules($userid, $assignments),
            'assignmentstable' => $this->render_assignments_table($userid),
            'completions' => $this->export_completions($userid, $assignments),
            'competencies' => $this->export_competencies($userid),
            'notes' => $this->export_notes($userid),
            'canviewnotes' => person_notes_service::can_view((int)$USER->id, $userid),
            'cancreatenotes' => person_notes_service::can_create((int)$USER->id, $userid),
            'dashboardurl' => (new moodle_url('/local/taskflow/dashboard.php'))->out(false),
            'organisationurl' => has_capability('local/taskflow:vieworganisation', context_system::instance())
                ? (new moodle_url('/local/taskflow/units.php'))->out(false)
                : '',
            'isperson' => true,
        ];
        $data['haspersonalrules'] = !empty($data['personalrules']);
        $data['hascompletions'] = !empty($data['completions']);
        $data['hascompetencies'] = !empty($data['competencies']);
        $data['hasnotes'] = !empty($data['notes']);

        $PAGE->requires->js_call_amd('local_taskflow/personpage', 'init', [$userid]);
        return $data;
    }

    /**
     * Name, picture, contact.
     *
     * @param renderer_base $output
     * @return array
     */
    private function export_user(renderer_base $output): array {
        $user = $this->user;
        return [
            'id' => (int)$user->id,
            'fullname' => fullname($user),
            'email' => $user->email,
            'picture' => $output->user_picture($user, ['size' => 100, 'link' => false]),
            'profileurl' => (new moodle_url('/user/profile.php', ['id' => $user->id]))->out(false),
        ];
    }

    /**
     * Organisational facts: units, supervisor, contract dates, external id.
     *
     * @param int $userid
     * @return array
     */
    private function export_facts(int $userid): array {
        global $DB;

        $facts = [];

        // Units.
        $unitids = $DB->get_fieldset_select(
            'local_taskflow_unit_members',
            'unitid',
            'userid = :userid AND (active = 1 OR active IS NULL)',
            ['userid' => $userid]
        );
        $unitnames = [];
        foreach (array_unique($unitids) as $unitid) {
            if (empty($unitid)) {
                continue;
            }
            if (get_config('local_taskflow', 'organisational_unit_option') === 'cohort') {
                $name = $DB->get_field('cohort', 'name', ['id' => $unitid]);
            } else {
                $name = $DB->get_field('local_taskflow_units', 'name', ['id' => $unitid]);
            }
            if (!empty($name)) {
                $unitnames[] = \html_writer::link(
                    new moodle_url('/local/taskflow/units.php', ['id' => $unitid]),
                    format_string($name)
                );
            }
        }
        $facts[] = [
            'icon' => 'fa-sitemap',
            'label' => taskflow_stringmanager::get_string('units'),
            'value' => !empty($unitnames) ? implode(', ', $unitnames) : '-',
        ];

        // Supervisor.
        $supervisor = supervisor::get_supervisor_for_user($userid);
        if (!empty($supervisor->id)) {
            $url = new moodle_url('/local/taskflow/person.php', ['id' => $supervisor->id]);
            $value = \html_writer::link($url, fullname($supervisor));
        } else {
            $value = taskflow_stringmanager::get_string('nosupervisor');
        }
        $facts[] = [
            'icon' => 'fa-user-tie',
            'label' => taskflow_stringmanager::get_string('personsupervisor'),
            'value' => $value,
        ];

        // Profile fields mapped by the adapter.
        $mapped = [
            [taskflowadapter::TRANSLATOR_USER_EXTERNALID, 'externalid', 'fa-id-badge', false],
            [taskflowadapter::TRANSLATOR_USER_CONTRACTSTART, 'contractstart', 'fa-calendar-check', true],
            [taskflowadapter::TRANSLATOR_USER_CONTRACTEND, 'contractend', 'fa-calendar-times', true],
        ];
        foreach ($mapped as [$function, $stringkey, $icon, $isdate]) {
            $shortname = external_api_base::return_shortname_for_functionname($function);
            if (empty($shortname)) {
                continue;
            }
            $value = $DB->get_field_sql(
                "SELECT uid.data
                   FROM {user_info_data} uid
                   JOIN {user_info_field} uif ON uif.id = uid.fieldid
                  WHERE uid.userid = :userid AND uif.shortname = :shortname",
                ['userid' => $userid, 'shortname' => $shortname]
            );
            if ($value === false || $value === '' || $value === null) {
                continue;
            }
            if ($isdate && is_numeric($value)) {
                // Contract ends far in the future mean "open-ended".
                $value = (int)$value > time() + 50 * YEARSECS
                    ? taskflow_stringmanager::get_string('openended')
                    : userdate((int)$value, get_string('strftimedate', 'langconfig'));
            }
            $facts[] = [
                'icon' => $icon,
                'label' => taskflow_stringmanager::get_string($stringkey),
                'value' => format_string($value),
            ];
        }
        return $facts;
    }

    /**
     * All assignments of the user with the rule name.
     *
     * @param int $userid
     * @return stdClass[]
     */
    private function load_assignments(int $userid): array {
        global $DB;
        $sql = "SELECT a.*, r.rulename
                  FROM {local_taskflow_assignment} a
             LEFT JOIN {local_taskflow_rules} r ON r.id = a.ruleid
                 WHERE a.userid = :userid
              ORDER BY a.timecreated DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Counters shown as tiles.
     *
     * @param int $userid
     * @param stdClass[] $assignments
     * @return array
     */
    private function export_kpis(int $userid, array $assignments): array {
        global $DB;

        $completedid = assignment_status_facade::get_status_identifier('completed');
        $overdueid = assignment_status_facade::get_status_identifier('overdue');
        $completed = 0;
        $open = 0;
        $overdue = 0;
        foreach ($assignments as $assignment) {
            if ((int)$assignment->status === $completedid) {
                $completed++;
            } else if (!empty($assignment->active)) {
                $open++;
                if ((int)$assignment->status === $overdueid) {
                    $overdue++;
                }
            }
        }

        $kpis = [
            [
                'value' => $completed,
                'label' => taskflow_stringmanager::get_string('kpi_completed'),
                'icon' => 'fa-check-circle',
                'class' => 'text-success',
            ],
            [
                'value' => $open,
                'label' => taskflow_stringmanager::get_string('kpi_open'),
                'icon' => 'fa-tasks',
                'class' => 'text-primary',
            ],
            [
                'value' => $overdue,
                'label' => taskflow_stringmanager::get_string('kpi_overdue'),
                'icon' => 'fa-exclamation-triangle',
                'class' => $overdue ? 'text-danger' : 'text-muted',
            ],
        ];
        if (core_component::get_plugin_directory('mod', 'booking')) {
            $kpis[] = [
                'value' => \mod_booking\booking_answers\booking_answers::count_answers_of_user($userid),
                'label' => taskflow_stringmanager::get_string('kpi_bookings'),
                'icon' => 'fa-graduation-cap',
                'class' => 'text-info',
                'url' => (new moodle_url(
                    '/mod/booking/mybookings.php',
                    ['userid' => $userid, 'completed' => 1, 'filter' => 1, 'typefilter' => 1]
                ))->out(false),
            ];
        }
        if (core_component::get_plugin_directory('tool', 'certificate')) {
            $kpis[] = [
                'value' => $DB->count_records('tool_certificate_issues', ['userid' => $userid, 'archived' => 0]),
                'label' => taskflow_stringmanager::get_string('certificates'),
                'icon' => 'fa-certificate',
                'class' => 'text-warning',
                'url' => (new moodle_url('/local/taskflow/mycertificates.php', ['userid' => $userid]))->out(false),
            ];
        }
        return $kpis;
    }

    /**
     * Rules assigned to this person individually, with the state of the resulting assignment.
     *
     * @param int $userid
     * @param stdClass[] $assignments
     * @return array
     */
    private function export_personal_rules(int $userid, array $assignments): array {
        $rows = [];
        foreach (personal_rule_assignment_service::get_personal_rules_of_user($userid) as $rule) {
            $row = [
                'ruleid' => (int)$rule->id,
                'rulename' => format_string($rule->rulename),
                'annotation' => format_text($rule->annotation ?? '', FORMAT_PLAIN),
                'assignedby' => taskflow_stringmanager::get_string('assignedby', (object)[
                    'date' => userdate((int)$rule->timecreated, get_string('strftimedate', 'langconfig')),
                    'name' => fullname(core_user::get_user((int)$rule->usermodified) ?: core_user::get_noreply_user()),
                ]),
                'statusname' => '',
                'statusclass' => 'badge bg-secondary',
                'assignmenturl' => '',
                'unassignurl' => (new moodle_url($this->pageurl, [
                    'action' => 'unassign',
                    'ruleid' => (int)$rule->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
            foreach ($assignments as $assignment) {
                if ((int)$assignment->ruleid !== (int)$rule->id) {
                    continue;
                }
                $row['statusname'] = assignment_status_facade::get_specific_names((int)$assignment->status);
                $row['statusclass'] = self::status_badge_class((int)$assignment->status, (bool)$assignment->active);
                $row['assignmenturl'] = (new moodle_url('/local/taskflow/assignment.php', [
                    'id' => (int)$assignment->id,
                    'returnurl' => $this->pageurl->out_as_local_url(false),
                ]))->out(false);
                break;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Bootstrap badge class for an assignment status.
     *
     * @param int $status
     * @param bool $active
     * @return string
     */
    private static function status_badge_class(int $status, bool $active): string {
        if ($status === assignment_status_facade::get_status_identifier('completed')) {
            return 'badge bg-success';
        }
        if ($status === assignment_status_facade::get_status_identifier('overdue')) {
            return 'badge bg-danger';
        }
        if (!$active) {
            return 'badge bg-secondary';
        }
        return 'badge bg-primary';
    }

    /**
     * The assignments table (all assignments of the user, active and inactive).
     *
     * @param int $userid
     * @return string
     */
    private function render_assignments_table(int $userid): string {
        global $PAGE;
        $arguments = [
            'active' => -1,
            'userid' => $userid,
            'columns' => 'rulename,targets,duedate,statussortkey',
            'filter' => 'status,completed',
            'statusbadges' => 1,
            'toolbartemplate' => 1,
        ];
        $provider = new myassignmentsprovider($userid, $arguments);
        $dashboard = new assignmentsdashboard($provider, $userid, $arguments);
        $dashboard->get_assignmentsdashboard();
        $renderer = $PAGE->get_renderer('local_taskflow');
        return $renderer->render($dashboard);
    }

    /**
     * Completions, evidence and certificates as one timeline, newest first.
     *
     * @param int $userid
     * @param stdClass[] $assignments
     * @return array
     */
    private function export_completions(int $userid, array $assignments): array {
        global $DB;

        $entries = [];
        $completedid = assignment_status_facade::get_status_identifier('completed');
        foreach ($assignments as $assignment) {
            if ((int)$assignment->status !== $completedid) {
                continue;
            }
            $entries[] = [
                'time' => (int)($assignment->completeddate ?: $assignment->timemodified),
                'type' => taskflow_stringmanager::get_string('completiontype_assignment'),
                'icon' => 'fa-clipboard-check',
                'name' => format_string($assignment->rulename ?? ''),
                'url' => (new moodle_url('/local/taskflow/assignment.php', ['id' => $assignment->id]))->out(false),
            ];
        }

        if (core_component::get_plugin_directory('mod', 'booking')) {
            $sql = "SELECT ba.id, ba.optionid, ba.timemodified, bo.text
                      FROM {booking_answers} ba
                      JOIN {booking_options} bo ON bo.id = ba.optionid
                     WHERE ba.userid = :userid AND ba.completed = 1 AND ba.waitinglist = 0";
            foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $answer) {
                $settings = \mod_booking\singleton_service::get_instance_of_booking_option_settings((int)$answer->optionid);
                $entries[] = [
                    'time' => (int)$answer->timemodified,
                    'type' => taskflow_stringmanager::get_string('completiontype_booking'),
                    'icon' => 'fa-graduation-cap',
                    'name' => format_string($answer->text),
                    'url' => !empty($settings->cmid)
                        ? (new moodle_url(
                            '/mod/booking/optionview.php',
                            ['optionid' => $answer->optionid, 'cmid' => $settings->cmid]
                        ))->out(false)
                        : '',
                ];
            }
        }

        if (core_component::get_plugin_directory('tool', 'certificate')) {
            $sql = "SELECT i.id, i.code, i.timecreated, i.expires, i.data, t.name
                      FROM {tool_certificate_issues} i
                      JOIN {tool_certificate_templates} t ON t.id = i.templateid
                     WHERE i.userid = :userid AND i.archived = 0";
            foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $issue) {
                $json = json_decode($issue->data ?? '');
                $name = !empty($json->bookingoptionname) ? $json->bookingoptionname : $issue->name;
                $entries[] = [
                    'time' => (int)$issue->timecreated,
                    'type' => taskflow_stringmanager::get_string('completiontype_certificate'),
                    'icon' => 'fa-certificate',
                    'name' => format_string($name),
                    'url' => (new moodle_url('/admin/tool/certificate/view.php', ['code' => $issue->code]))->out(false),
                    'expires' => !empty($issue->expires)
                        ? userdate((int)$issue->expires, get_string('strftimedate', 'langconfig'))
                        : '',
                ];
            }
        }

        usort($entries, fn($a, $b) => $b['time'] <=> $a['time']);
        foreach ($entries as &$entry) {
            $entry['date'] = userdate($entry['time'], get_string('strftimedate', 'langconfig'));
        }
        return $entries;
    }

    /**
     * Notes about the person, newest first; only filled when the viewer may read them.
     *
     * @param int $userid
     * @return array
     */
    private function export_notes(int $userid): array {
        global $USER;
        if (!person_notes_service::can_view((int)$USER->id, $userid)) {
            return [];
        }
        $rows = [];
        foreach (person_notes_service::get_notes($userid) as $note) {
            $author = core_user::get_user((int)$note->usermodified);
            $rows[] = [
                'id' => (int)$note->id,
                'text' => format_text($note->note, (int)$note->noteformat, ['context' => context_system::instance()]),
                'author' => $author ? fullname($author) : '',
                'date' => userdate((int)$note->timecreated, get_string('strftimedatetime', 'langconfig')),
                'candelete' => person_notes_service::can_delete((int)$USER->id, $note),
                'deleteurl' => (new moodle_url($this->pageurl, [
                    'action' => 'deletenote',
                    'noteid' => (int)$note->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }
        return $rows;
    }

    /**
     * Competencies the user holds (proficient user competencies).
     *
     * @param int $userid
     * @return array
     */
    private function export_competencies(int $userid): array {
        global $DB;
        if (!get_config('core_competency', 'enabled')) {
            return [];
        }
        $sql = "SELECT uc.id, uc.proficiency, uc.timemodified, c.shortname, c.id AS competencyid
                  FROM {competency_usercomp} uc
                  JOIN {competency} c ON c.id = uc.competencyid
                 WHERE uc.userid = :userid
              ORDER BY uc.proficiency DESC, c.shortname";
        $rows = [];
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $record) {
            $rows[] = [
                'name' => format_string($record->shortname),
                'proficient' => !empty($record->proficiency),
                'date' => userdate((int)$record->timemodified, get_string('strftimedate', 'langconfig')),
                'url' => (new moodle_url('/admin/tool/lp/user_competency.php', ['id' => $record->id]))->out(false),
            ];
        }
        return $rows;
    }
}
