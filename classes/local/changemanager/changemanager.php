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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Thomas Winkler
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\changemanager;

use local_taskflow\local\rules\rules;

/**
 * Class unit
 * @author Thomas Winkler
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Class assignment_competency
 * Handles CRUD operations on the assignment-competency relation.
 */
class changemanager {
    /** @var rules */
    private $rule;

    /** @var array */
    private $ruledata;

    /**
     * Constructor - optionally load from DB by ID.
     * @param int $ruleid
     * @param array $ruledata
     */
    public function __construct($ruleid, $ruledata) {
        $this->rule = rules::instance($ruleid);
        $this->ruledata = $ruledata;
    }

    /**
     * Load from DB.
     * @return array
     */
    public function get_change_management_data(): array {
        $oldruledata = empty($this->rule) ? 0 : json_decode($this->rule->get_rulesjson());
        $oldenabled = $oldruledata->rulejson->rule->enabled ?? 0;
        $oldrecursive = $oldruledata->rulejson->rule->recursive ?? 0;

        $newruledata = json_decode($this->ruledata['rulejson']);
        $newenabled = $newruledata->rulejson->rule->enabled ?? 0;
        $newrecursive = $newruledata->rulejson->rule->recursive ?? 0;

        return [
            'enabled' => $newenabled,
            'enabled_changed' => $oldenabled == $newenabled ? 0 : 1,
            'recursive' => $newrecursive,
            'recursive_changed' => $oldrecursive == $newrecursive ? 0 : 1,
        ];
    }
}
