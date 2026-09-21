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

namespace local_taskflow\local\wizard\taskflow\preview;

/**
 * Maps preview types to renderer classes for taskflow_skill_base::get_result_preview().
 *
 * The MAP is static so a coverage test can assert that every type used by a skill has a
 * renderer class and a template. A missing class (renderer not yet written) yields null,
 * so a skill result without a renderer simply has no side-pane preview.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_preview_renderer_factory {
    /** Preview type constants (concept §1). */
    public const TYPE_ASSIGNMENT = 'taskflow_assignment';
    /** Assignment list preview. */
    public const TYPE_ASSIGNMENT_LIST = 'taskflow_assignment_list';
    /** Single rule preview. */
    public const TYPE_RULE = 'taskflow_rule';
    /** Rule list preview. */
    public const TYPE_RULE_LIST = 'taskflow_rule_list';
    /** User taskflow profile preview. */
    public const TYPE_USER_PROFILE = 'taskflow_user_profile';
    /** Diagnostic checklist preview. */
    public const TYPE_DIAGNOSTIC_CHECKLIST = 'taskflow_diagnostic_checklist';
    /** Rendered message preview. */
    public const TYPE_MESSAGE_PREVIEW = 'taskflow_message_preview';
    /** Message template list preview. */
    public const TYPE_MESSAGE_TEMPLATE_LIST = 'taskflow_message_template_list';
    /** Request list preview. */
    public const TYPE_REQUEST_LIST = 'taskflow_request_list';
    /** Units tree preview. */
    public const TYPE_UNITS_TREE = 'taskflow_units_tree';
    /** Import report preview. */
    public const TYPE_IMPORT_REPORT = 'taskflow_import_report';
    /** Catalog (settings / properties) preview. */
    public const TYPE_CATALOG = 'taskflow_catalog';
    /** Supervisor overview preview. */
    public const TYPE_SUPERVISOR_OVERVIEW = 'taskflow_supervisor_overview';

    /**
     * Preview type => renderer class name (classes are provided by the skill authors).
     *
     * @var array<string,string>
     */
    public const MAP = [
        self::TYPE_ASSIGNMENT => __NAMESPACE__ . '\\taskflow_assignment_preview_renderer',
        self::TYPE_ASSIGNMENT_LIST => __NAMESPACE__ . '\\taskflow_assignment_list_preview_renderer',
        self::TYPE_RULE => __NAMESPACE__ . '\\taskflow_rule_preview_renderer',
        self::TYPE_RULE_LIST => __NAMESPACE__ . '\\taskflow_rule_list_preview_renderer',
        self::TYPE_USER_PROFILE => __NAMESPACE__ . '\\taskflow_user_profile_preview_renderer',
        self::TYPE_DIAGNOSTIC_CHECKLIST => __NAMESPACE__ . '\\taskflow_diagnostic_checklist_preview_renderer',
        self::TYPE_MESSAGE_PREVIEW => __NAMESPACE__ . '\\taskflow_message_preview_preview_renderer',
        self::TYPE_MESSAGE_TEMPLATE_LIST => __NAMESPACE__ . '\\taskflow_message_template_list_preview_renderer',
        self::TYPE_REQUEST_LIST => __NAMESPACE__ . '\\taskflow_request_list_preview_renderer',
        self::TYPE_UNITS_TREE => __NAMESPACE__ . '\\taskflow_units_tree_preview_renderer',
        self::TYPE_IMPORT_REPORT => __NAMESPACE__ . '\\taskflow_import_report_preview_renderer',
        self::TYPE_CATALOG => __NAMESPACE__ . '\\taskflow_catalog_preview_renderer',
        self::TYPE_SUPERVISOR_OVERVIEW => __NAMESPACE__ . '\\taskflow_supervisor_overview_preview_renderer',
    ];

    /**
     * Renderer instance for a preview type, or null when unknown or not (yet) implemented.
     *
     * @param string $type
     * @return taskflow_preview_renderer_base|null
     */
    public static function for_type(string $type): ?taskflow_preview_renderer_base {
        $type = trim($type);
        $class = self::MAP[$type] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }
        if (!is_subclass_of($class, taskflow_preview_renderer_base::class)) {
            return null;
        }
        try {
            $renderer = new $class();
        } catch (\Throwable $e) {
            return null;
        }
        return $renderer;
    }

    /**
     * Whether a type is a known taskflow preview type.
     *
     * @param string $type
     * @return bool
     */
    public static function is_known_type(string $type): bool {
        return isset(self::MAP[trim($type)]);
    }
}
