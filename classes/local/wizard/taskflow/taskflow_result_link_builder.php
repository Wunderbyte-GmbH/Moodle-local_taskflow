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

namespace local_taskflow\local\wizard\taskflow;

use local_taskflow\local\documentation\documentation_viewer;
use moodle_url;

/**
 * Builds page URLs and documentation deep links carried in every taskflow skill result.
 *
 * Every skill result carries 'links' => ['page' => <url|null>, 'docs' => [<url>, ...]].
 * Documentation anchors are symbolic keys mapped to chapter paths below docs/ (the
 * chapter list of docs/user/README.md), so skills never hardcode file paths.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_result_link_builder {
    /**
     * Documentation anchor key => relative path below local/taskflow/docs.
     *
     * @var array<string,string>
     */
    public const DOCS_ANCHORS = [
        'index' => 'user/README.md',
        'getting_started' => 'user/getting_started/README.md',
        'dashboard' => 'user/dashboard/README.md',
        'assignments' => 'user/assignments/README.md',
        'assignments_status_lifecycle' => 'user/assignments/01-status-lifecycle.md',
        'assignments_detail_page' => 'user/assignments/02-assignment-detail-page.md',
        'assignments_edit' => 'user/assignments/03-edit-assignment.md',
        'assignments_history' => 'user/assignments/04-history.md',
        'assignments_due_dates' => 'user/assignments/05-due-dates-prolongation-overdue.md',
        'assignments_cyclic' => 'user/assignments/06-cyclic-assignments.md',
        'rules' => 'user/rules/README.md',
        'rules_rule_step' => 'user/rules/01-rule-step.md',
        'rules_filters' => 'user/rules/02-filters.md',
        'rules_targets' => 'user/rules/03-targets.md',
        'rules_messages_step' => 'user/rules/04-messages-step.md',
        'rules_requests_step' => 'user/rules/05-requests-step.md',
        'messages' => 'user/messages/README.md',
        'messages_templates' => 'user/messages/01-message-templates.md',
        'messages_placeholders' => 'user/messages/02-placeholders.md',
        'messages_internal_communication' => 'user/messages/03-internal-communication.md',
        'requests' => 'user/requests/README.md',
        'units_and_users' => 'user/units_and_users/README.md',
        'competencies_and_certificates' => 'user/competencies_and_certificates/README.md',
        'adapters' => 'user/adapters/README.md',
        'adapters_standard' => 'user/adapters/standard.md',
        'adapters_ksw' => 'user/adapters/ksw.md',
        'adapters_tuines' => 'user/adapters/tuines.md',
        'settings' => 'user/settings/README.md',
        'capabilities' => 'user/capabilities/README.md',
        'scheduled_tasks' => 'user/scheduled_tasks/README.md',
        'shortcodes' => 'user/shortcodes/README.md',
        'dev_architecture' => 'developer-guides/ARCHITECTURE_OVERVIEW.md',
        'dev_adapter_api' => 'developer-guides/ADAPTER_API.md',
        'dev_rule_json' => 'developer-guides/RULE_JSON_FORMAT.md',
    ];

    /**
     * URL of the assignment detail page.
     *
     * @param int $assignmentid
     * @return string
     */
    public static function assignment_url(int $assignmentid): string {
        return (new moodle_url('/local/taskflow/assignment.php', ['id' => $assignmentid]))->out(false);
    }

    /**
     * URL of the assignment edit page.
     *
     * @param int $assignmentid
     * @return string
     */
    public static function edit_assignment_url(int $assignmentid): string {
        return (new moodle_url('/local/taskflow/editassignment.php', ['id' => $assignmentid]))->out(false);
    }

    /**
     * URL of the rule editor (id 0 = new rule).
     *
     * @param int $ruleid
     * @return string
     */
    public static function edit_rule_url(int $ruleid): string {
        return (new moodle_url('/local/taskflow/editrule.php', ['id' => $ruleid]))->out(false);
    }

    /**
     * URL of the taskflow dashboard.
     *
     * @return string
     */
    public static function dashboard_url(): string {
        return (new moodle_url('/local/taskflow/index.php'))->out(false);
    }

    /**
     * Whether a user may open the rules/admin dashboard (the "open rules dashboard" link).
     *
     * Mirrors the gate of local_taskflow\output\dashboard: the admin part of the dashboard is
     * rendered for HR users (confirmation_supervisor_hrusers) and holders of
     * local/taskflow:editassignment or local/taskflow:viewreports. Everybody else only sees
     * their own assignments there, so a "rules dashboard" link must not be offered to them.
     *
     * @param int $userid 0 = current user.
     * @return bool
     */
    public static function can_open_rules_dashboard(int $userid = 0): bool {
        global $USER;
        $userid = $userid > 0 ? $userid : (int)($USER->id ?? 0);
        if ($userid <= 0) {
            return false;
        }
        $context = \context_system::instance();
        if (
            has_capability('local/taskflow:editassignment', $context, $userid)
            || has_capability('local/taskflow:viewreports', $context, $userid)
        ) {
            return true;
        }
        $hrusers = array_filter(array_map('intval', explode(
            ',',
            (string)get_config('bookingextension_confirmation_supervisor', 'confirmation_supervisor_hrusers')
        )));
        return in_array($userid, $hrusers, true);
    }

    /**
     * Rules-dashboard URL for a user, '' when the user may not open it.
     *
     * @param int $userid 0 = current user.
     * @return string
     */
    public static function rules_dashboard_url_for(int $userid = 0): string {
        return self::can_open_rules_dashboard($userid) ? self::dashboard_url() : '';
    }

    /**
     * URL of the message template editor (id 0 = new template).
     *
     * @param int $messageid
     * @return string
     */
    public static function edit_message_url(int $messageid = 0): string {
        $params = $messageid > 0 ? ['id' => $messageid] : [];
        return (new moodle_url('/local/taskflow/message_form/editmessage.php', $params))->out(false);
    }

    /**
     * URL of a user's certificate page.
     *
     * @param int $userid
     * @return string
     */
    public static function my_certificates_url(int $userid): string {
        return (new moodle_url('/local/taskflow/mycertificates.php', ['userid' => $userid]))->out(false);
    }

    /**
     * URL of a user's profile page.
     *
     * @param int $userid
     * @return string
     */
    public static function user_url(int $userid): string {
        return (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
    }

    /**
     * URL of a documentation page by relative path below local/taskflow/docs.
     *
     * The path is validated with documentation_viewer::normalize_path(); invalid or
     * escaping paths yield an empty string so a result never carries a broken link.
     *
     * @param string $relpath e.g. 'user/rules/02-filters.md'
     * @return string Empty when the path is invalid.
     */
    public static function docs_url(string $relpath): string {
        $normalized = documentation_viewer::normalize_path($relpath);
        if ($normalized === null || $normalized === '') {
            return '';
        }
        return (new moodle_url('/local/taskflow/documentation.php', ['file' => $normalized]))->out(false);
    }

    /**
     * Relative docs path for a symbolic anchor key (see DOCS_ANCHORS).
     *
     * A value that already is a relative path (contains '/' or ends with '.md') is
     * normalized and returned as is; unknown keys yield an empty string.
     *
     * @param string $key
     * @return string
     */
    public static function docs_anchor(string $key): string {
        $key = trim($key);
        if (isset(self::DOCS_ANCHORS[$key])) {
            return self::DOCS_ANCHORS[$key];
        }
        if (strpos($key, '/') !== false || substr($key, -3) === '.md') {
            return (string)(documentation_viewer::normalize_path($key) ?? '');
        }
        return '';
    }

    /**
     * Documentation URL for a symbolic anchor key or relative path.
     *
     * @param string $key
     * @return string Empty when unknown.
     */
    public static function docs_link(string $key): string {
        $relpath = self::docs_anchor($key);
        return $relpath === '' ? '' : self::docs_url($relpath);
    }

    /**
     * Build the standard 'links' block of a skill result.
     *
     * @param string|null $page Primary page URL (null when none).
     * @param string[] $docskeys Anchor keys / relative paths; unknown ones are dropped.
     * @param array<string,string> $extra Additional named links (e.g. 'edit' => url).
     * @return array{page:?string,docs:string[]}
     */
    public static function links(?string $page, array $docskeys = [], array $extra = []): array {
        $docs = [];
        foreach ($docskeys as $key) {
            $url = self::docs_link((string)$key);
            if ($url !== '' && !in_array($url, $docs, true)) {
                $docs[] = $url;
            }
        }
        // The dashboard is only a useful landing page for users who may open its rules/admin part.
        if ($page !== null && trim($page) === self::dashboard_url() && !self::can_open_rules_dashboard()) {
            $page = null;
        }
        $links = ['page' => ($page !== null && trim($page) !== '') ? $page : null, 'docs' => $docs];
        foreach ($extra as $name => $url) {
            $url = trim((string)$url);
            if (is_string($name) && $name !== '' && $url !== '' && !isset($links[$name])) {
                $links[$name] = $url;
            }
        }
        return $links;
    }
}
