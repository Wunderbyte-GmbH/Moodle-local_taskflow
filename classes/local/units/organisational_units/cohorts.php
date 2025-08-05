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
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\units\organisational_units;

use context_system;
use local_taskflow\local\units\organisational_units_interface;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohorts implements organisational_units_interface {
    /** @var string */
    private const TABLENAME = 'local_taskflow_unit_rel';

    /** @var array $cohortsrelation The user ID who last modified the unit. */
    private $cohortsrelation;

    /** @var array $cohorts The user ID who last modified the unit. */
    private $cohorts;

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function __construct() {
        global $DB;
        $this->cohortsrelation = $DB->get_records(self::TABLENAME, ['active' => 1], '', 'childid, parentid');
        $context = context_system::instance();
        $cohortsdata = cohort_get_cohorts($context->id, 0, 0);
        $this->cohorts = $cohortsdata['cohorts'];
    }

    /**
     * Update the current unit.
     * @return array
     */
    public function get_units() {
        $cohortoptions = [];
        foreach ($this->cohorts as $cohort) {
            $cohortoptions[$cohort->id] = $this->set_cohort_name($cohort->id);
        }
        return $cohortoptions;
    }

    /**
     * Update the current unit.
     * @param int $cohortid
     * @return string
     */
    private function set_cohort_name($cohortid) {
        $cohortids[] = $cohortid;
        while (
            isset($this->cohortsrelation[$cohortid])
        ) {
            $cohortid = $this->cohortsrelation[$cohortid]->parentid;
            if (in_array($cohortid, $cohortids)) {
                break;
            }
            $cohortids[] = $cohortid;
        }
        $cohortids = array_reverse($cohortids);
        $cohortpathname = [];
        foreach ($cohortids as $cohortid) {
            $cohortpathname[] = $this->cohorts[$cohortid]->name;
        }
        return implode('/', $cohortpathname);
    }
}
