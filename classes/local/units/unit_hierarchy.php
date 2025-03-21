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
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\units;

use cache;
/**
 * Class unit
 *
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_hierarchy {
    /** @var array Cached hierarchy */
    private $hierarchy;

    /**
     * Private constructor to prevent direct instantiation.
     */
    public function __construct() {
        $this->hierarchy = self::get_hierarchy();
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @return array
     */
    private function get_hierarchy(): array {
        $cache = cache::make('local_taskflow', 'unit_hierarchy');

        $cached = $cache->get('full_hierarchy');
        if ($cached !== false) {
            return $cached;
        }

        $hierarchy = $this->build_hierarchy();
        $cache->set('full_hierarchy', $hierarchy);
        return $hierarchy;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @return array
     */
    private function build_hierarchy() {
        global $DB;
        $hierarchy = [];
        $unitrelations = unit_relations::get_all_active_unit_relations();
        foreach ($unitrelations as $childid => $data) {
            $parentid = $data->parentid;
            $pathtoou = [$childid, $parentid];
            while (isset($unitrelations[$parentid])) {
                $parentou = $unitrelations[$parentid];
                if (in_array($parentou->parentid, $pathtoou)) {
                    break;
                }
                $pathtoou[] = $parentou->parentid;
                self::check_and_set_master($parentou->parentid, $hierarchy);
                $parentid = $parentou->parentid;
            }
            $hierarchy[$childid] = [
                'depth' => count($pathtoou),
                'pathtoou' => implode('/', array_reverse($pathtoou)),
            ];
        }
        return $hierarchy;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $parentid
     * @param array $hierarchy
     */
    private function check_and_set_master($parentid, &$hierarchy): void {
        if (
            !isset($unitrelations[$parentid]) &&
            !isset($hierarchy[$parentid])
        ) {
            $hierarchy[$parentid] = [
                'depth' => 1,
                'pathtoou' => $parentid,
            ];
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @return array
     */
    public function get(): array {
        return $this->hierarchy;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @return array
     */
    public function get_organisational_unit($ouid): array {
        return $this->hierarchy[$ouid];
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    public static function invalidate_cache(): void {
        $cache = cache::make('local_taskflow', 'unit_hierarchy');
        $cache->delete('full_hierarchy');
    }
}
