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

use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\organisational_units_factory;
use local_taskflow\local\units\unit_hierarchy;

/**
 * Resolves organisational units for the wizard skills: name lookup and membership.
 *
 * Works on the active backend (own unit tables or cohorts) through the plugin's own
 * factories, so the answers match list_units and the rule engine. Membership of a unit
 * includes the members of every unit below it in the hierarchy, because a rule attached
 * to a parent unit applies to the members of its sub-units as well.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_unit_resolver {
    /** @var array<int,string>|null Unit names by id (memoized per request). */
    private ?array $units = null;

    /**
     * All units of the active backend: id => name.
     *
     * @return array<int,string>
     */
    public function all_units(): array {
        if ($this->units !== null) {
            return $this->units;
        }
        try {
            $units = (array)organisational_units_factory::instance()->get_units();
        } catch (\Throwable $e) {
            $units = [];
        }
        $this->units = [];
        foreach ($units as $unitid => $name) {
            $this->units[(int)$unitid] = (string)$name;
        }
        return $this->units;
    }

    /**
     * Whether a unit id exists in the active backend.
     *
     * @param int $unitid
     * @return bool
     */
    public function exists(int $unitid): bool {
        return $unitid > 0 && array_key_exists($unitid, $this->all_units());
    }

    /**
     * Name of a unit ('' when unknown).
     *
     * @param int $unitid
     * @return string
     */
    public function name(int $unitid): string {
        return (string)($this->all_units()[$unitid] ?? '');
    }

    /**
     * Own name of a unit: the cohort backend lists units as "Parent/Child" paths, so the
     * last path segment is the unit's own name (used for matching and display).
     *
     * @param string $name
     * @return string
     */
    public static function own_name(string $name): string {
        $parts = explode('/', $name);
        return trim((string)end($parts));
    }

    /**
     * Units whose own name contains the query (case-insensitive). An exact own-name match
     * wins alone; the full path is only searched when no own name matches, so "Facility"
     * finds the department and not every team below it.
     *
     * @param string $query
     * @return array<int,string> id => name; empty when nothing matches.
     */
    public function candidates(string $query): array {
        $needle = \core_text::strtolower(trim($query));
        if ($needle === '') {
            return [];
        }
        $exact = [];
        $partial = [];
        $bypath = [];
        foreach ($this->all_units() as $unitid => $name) {
            $own = \core_text::strtolower(self::own_name($name));
            if ($own === $needle) {
                $exact[$unitid] = $name;
            } else if (\core_text::strpos($own, $needle) !== false) {
                $partial[$unitid] = $name;
            } else if (\core_text::strpos(\core_text::strtolower($name), $needle) !== false) {
                $bypath[$unitid] = $name;
            }
        }
        if (count($exact) === 1) {
            return $exact;
        }
        $matches = $exact + $partial;
        return !empty($matches) ? $matches : $bypath;
    }

    /**
     * Resolve a unit name to its id (0 when nothing or several units match).
     *
     * @param string $query
     * @return int
     */
    public function resolve(string $query): int {
        $candidates = $this->candidates($query);
        return count($candidates) === 1 ? (int)array_key_first($candidates) : 0;
    }

    /**
     * Ids of the units below a unit in the hierarchy (all depths).
     *
     * @param int $unitid
     * @return int[]
     */
    public function descendants(int $unitid): array {
        try {
            $children = (new unit_hierarchy())->get_all_childerns($unitid);
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_unique(array_map('intval', (array)$children)));
    }

    /**
     * User ids of the members of a unit, optionally including its sub-units.
     *
     * @param int $unitid
     * @param bool $includedescendants
     * @return int[] Sorted, unique; empty when the unit has no members.
     */
    public function member_userids(int $unitid, bool $includedescendants = true): array {
        $unitids = [$unitid];
        if ($includedescendants) {
            $unitids = array_merge($unitids, $this->descendants($unitid));
        }
        $members = [];
        foreach (array_unique($unitids) as $id) {
            try {
                $unit = organisational_unit_factory::instance((int)$id);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_object($unit) || !method_exists($unit, 'get_members')) {
                continue;
            }
            try {
                foreach ((array)$unit->get_members() as $userid) {
                    $members[(int)$userid] = true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        $userids = array_map('intval', array_keys($members));
        sort($userids);
        return $userids;
    }
}
