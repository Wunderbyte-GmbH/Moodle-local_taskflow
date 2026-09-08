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

declare(strict_types=1);

namespace local_taskflow\reportbuilder\local\helpers;

/**
 * SQL helpers to relate the targets stored as JSON on an assignment to other tables.
 *
 * The targets of an assignment are stored as a JSON array in the text column
 * {local_taskflow_assignment}.targets. There is no normalised target table, so
 * the only portable way to join a target to another table is a LIKE pattern on
 * the JSON text. The predicates built here are used in report builder joins,
 * which cannot carry bound parameters. All literals are fixed JSON fragments and
 * the only variable part is a numeric ID column of the joined table.
 *
 * $DB->sql_like() is deliberately not used: it emits a debugging() notice when
 * the pattern contains a wildcard, because it expects a bound parameter.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_sql {
    /**
     * SQL predicate: the targets JSON contains a competency target with the given competency ID.
     *
     * The JSON is written by json_encode() with "targettype" directly followed by
     * "targetid" (see form/targets/target.php). The reverse key order is matched as
     * well, and the target ID may be stored as a JSON string or as a JSON number.
     * Invalid JSON simply matches nothing.
     *
     * @param string $targetsfield SQL of the targets column, e.g. "a.targets"
     * @param string $competencyidfield SQL of the competency ID column, e.g. "c.id"
     * @return string
     */
    public static function competency_target_match(string $targetsfield, string $competencyidfield): string {
        global $DB;

        $id = $DB->sql_cast_to_char($competencyidfield);
        $type = '"targettype":"competency"';
        $patterns = [
            // Form order, target ID as string: "targettype":"competency","targetid":"5".
            ["'%{$type},\"targetid\":\"'", $id, "'\"%'"],
            // Form order, target ID as number followed by the next key: "targetid":5,.
            ["'%{$type},\"targetid\":'", $id, "',%'"],
            // Form order, target ID as number as the last key: "targetid":5}.
            ["'%{$type},\"targetid\":'", $id, "'}%'"],
            // Reverse order, target ID as string: "targetid":"5","targettype":"competency".
            ["'%\"targetid\":\"'", $id, "'\",{$type}%'"],
            // Reverse order, target ID as number: "targetid":5,"targettype":"competency".
            ["'%\"targetid\":'", $id, "',{$type}%'"],
        ];

        $likes = [];
        foreach ($patterns as $parts) {
            $likes[] = "{$targetsfield} LIKE (" . $DB->sql_concat(...$parts) . ")";
        }
        return '(' . implode(' OR ', $likes) . ')';
    }

    /**
     * SQL predicate: a comma separated list of IDs contains the given ID.
     *
     * Used for {booking_options}.competencies, which holds the IDs of the
     * competencies acquired with the option as "1,5,12" (no spaces).
     *
     * @param string $csvfield SQL of the comma separated column, e.g. "bo.competencies"
     * @param string $idfield SQL of the ID column, e.g. "c.id"
     * @return string
     */
    public static function csv_contains(string $csvfield, string $idfield): string {
        global $DB;

        $haystack = $DB->sql_concat("','", "COALESCE({$csvfield}, '')", "','");
        $needle = $DB->sql_concat("'%,'", $DB->sql_cast_to_char($idfield), "',%'");
        return "({$haystack}) LIKE ({$needle})";
    }
}
