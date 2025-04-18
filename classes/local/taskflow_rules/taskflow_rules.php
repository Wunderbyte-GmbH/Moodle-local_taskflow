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
 * Base class for taskflow rules information.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer, Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\taskflow_rules;

use coding_exception;
use context;
use context_module;
use context_system;
use dml_exception;
use local_taskflow\output\ruleslist;
use local_taskflow\singleton_service;
use stdClass;

/**
 * Class to handle display and management of rules.
 *
 * @package local_taskflow
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rules {
    /** @var array $rules */
    public static $rules = [];

    /**
     * Returns the rendered html for a list of rules.
     *
     * @param int $contextid
     * @param bool $enableaddbutton
     * @return string
     */
    public static function get_rendered_list_of_saved_rules($contextid = 1, $enableaddbutton = true) {
        global $PAGE;
        $rules = self::get_list_of_saved_rules();
        $data = [];
        $output = $PAGE->get_renderer('taskflow');
        return $output->render_ruleslist($data);
    }

    /**
     * Returns the saved rules for the right context.
     * @param int $contextid
     * @return mixed
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_list_of_saved_rules(int $contextid = 0) {

        global $DB;

        if (empty(self::$rules)) {
            $rules = $DB->get_records('taskflow_rules', null, 'id');
            self::$rules = $rules;
        }

        if (empty($contextid)) {
            return self::$rules;
        }
        return array_filter(self::$rules, fn($a) => $a->contextid == $contextid);
    }

    /**
     * Get list of saved rules by optionid.
     * @param int $optionid
     * @param string $eventname
     * @return mixed
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_list_of_saved_rules_by_optionid(int $optionid, $eventname = '') {
        if (!empty($optionid)) {
            $settings = new stdClass();
            if (!empty($settings->cmid)) {
                $context = context_module::instance($settings->cmid);
                return self::get_list_of_saved_rules_by_context($context->id, $eventname);
            }
        }
        return self::get_list_of_saved_rules_by_context(1, $eventname);
    }

    /**
     * Returns the saved rules for the right context.
     * @param int $contextid
     * @param string $eventname
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_list_of_saved_rules_by_context(int $contextid = 1, string $eventname = '') {

        $context = context::instance_by_id($contextid);
        $path = $context->path;

        $patharray = explode('/', $path);

        $patharray = array_map(fn($a) => (int)$a, $patharray);

        // We get all rules, because we don't want one context, but the context path.
        $rules = self::get_list_of_saved_rules(0);

        if (empty($eventname)) {
            return array_filter($rules, fn($a) => in_array($a->contextid, $patharray));
        } else {
            return array_filter(
                $rules,
                fn($a) => (in_array($a->contextid, $patharray) && ($a->eventname == $eventname))
            );
        }
    }

    /**
     * Deletes rules for this context and below.
     * @param int $contextid
     */
    public static function delete_rules_by_context(int $contextid) {

        global $DB;

        if ($contextid == context_system::instance()->id) {
            return;
        }

        $rulesofcontext = $DB->get_records('taskflow_rules', ['contextid' => $contextid]);

        foreach ($rulesofcontext as $rule) {
            rules_info::delete_rule($rule->id);
        }
    }
}
