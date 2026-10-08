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

namespace local_taskflow\reportbuilder\local\filters;

use lang_string;
use MoodleQuickForm;
use core_reportbuilder\local\filters\base;
use core_reportbuilder\local\helpers\database;

/**
 * Filter for text fields holding a comma separated list of user IDs (e.g. the deputy profile field).
 *
 * The field SQL must resolve to the stored list. The list is wrapped in commas
 * and matched with LIKE against ",<id>,", which is portable across databases and
 * cannot match an ID inside a longer ID.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_in_list extends base {
    /** @var int No filter applied. */
    public const ANYVALUE = 0;

    /** @var int List contains the current user's ID. */
    public const CURRENT_USER = 1;

    /** @var int List contains a given user ID. */
    public const IS_USER = 2;

    /**
     * Return available operators.
     *
     * @return lang_string[]
     */
    private function get_operators(): array {
        $operators = [
            self::ANYVALUE => new lang_string('filterisanyvalue', 'core_reportbuilder'),
            self::CURRENT_USER => new lang_string('condition:profilefieldcurrentuser', 'local_taskflow'),
            self::IS_USER => new lang_string('filter:isuserwithid', 'local_taskflow'),
        ];

        return $this->filter->restrict_limited_operators($operators);
    }

    /**
     * Add form elements for this filter.
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        $operatorlabel = get_string('filterfieldoperator', 'core_reportbuilder', $this->get_header());
        $mform->addElement('select', "{$this->name}_operator", $operatorlabel, $this->get_operators())
            ->setHiddenLabel(true);
        $mform->setType("{$this->name}_operator", PARAM_INT);
        $mform->setDefault("{$this->name}_operator", self::ANYVALUE);

        $valuelabel = get_string('filterfieldvalue', 'core_reportbuilder', $this->get_header());
        $mform->addElement('text', "{$this->name}_value", $valuelabel, ['size' => 6]);
        $mform->setType("{$this->name}_value", PARAM_INT);
        $mform->hideIf("{$this->name}_value", "{$this->name}_operator", 'neq', self::IS_USER);
    }

    /**
     * Return filter SQL.
     *
     * @param array $values
     * @return array [$sql, [...$params]]
     */
    public function get_sql_filter(array $values): array {
        global $USER;

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANYVALUE);
        switch ($operator) {
            case self::CURRENT_USER:
                $userid = (int) $USER->id;
                break;
            case self::IS_USER:
                $userid = (int) ($values["{$this->name}_value"] ?? 0);
                break;
            default:
                return ['', []];
        }
        if ($userid <= 0) {
            return ['', []];
        }

        $paramname = database::generate_param_name();
        $sql = self::get_contains_user_sql($this->filter->get_field_sql(), ":{$paramname}");
        $params = $this->filter->get_field_params();
        $params[$paramname] = '%,' . $userid . ',%';

        return [$sql, $params];
    }

    /**
     * Return SQL matching a comma separated list field against a ",<id>," pattern.
     *
     * @param string $fieldsql SQL of the list field
     * @param string $patternsql SQL of the pattern, a placeholder or an expression yielding "%,<id>,%"
     * @return string
     */
    public static function get_contains_user_sql(string $fieldsql, string $patternsql): string {
        global $DB;

        $wrapped = $DB->sql_concat("','", "COALESCE({$fieldsql}, '')", "','");
        if (preg_match('/^[:?]/', $patternsql)) {
            return $DB->sql_like($wrapped, $patternsql);
        }
        // Expression pattern (e.g. built from a user ID column): sql_like() only accepts bound parameters.
        return "{$wrapped} LIKE {$patternsql}";
    }

    /**
     * Return sample filter values.
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            "{$this->name}_operator" => self::CURRENT_USER,
        ];
    }
}
