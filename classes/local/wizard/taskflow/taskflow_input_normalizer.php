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

use local_taskflow\local\wizard\engine\skill_input_normalizer_interface;
use local_taskflow\local\wizard\skill_provider;

/**
 * Provider-owned input normalizer for local_taskflow skills.
 *
 * Pure type coercion (never semantic or lexical interpretation): trims strings, casts
 * numeric strings of id/limit fields to int, coerces boolean flags, wraps scalar values of
 * list fields into arrays and drops empty optional scalars. Status labels are NOT mapped
 * here; skills resolve them against assignment_status_facade (engine state).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_input_normalizer implements skill_input_normalizer_interface {
    /** @var string[] Fields carrying integer ids or counts. */
    public const INT_FIELDS = [
        'assignmentid', 'ruleid', 'userid', 'unitid', 'messageid', 'requestid', 'supervisorid',
        'targetid', 'parentid', 'assgincompid', 'limit', 'historylimit', 'treated',
        'duration', 'extensionperiod', 'activationdelay', 'cyclicduration', 'extenddays',
    ];

    /** @var string[] Fields carrying boolean flags. */
    public const BOOL_FIELDS = [
        'isactive', 'activeonly', 'overdueonly', 'includeadapter', 'all', 'dryrun', 'keepchanges',
        'cyclicvalidation', 'enabled', 'recursive', 'inheritance', 'completebeforenext',
    ];

    /** @var string[] Fields that are always lists. */
    public const LIST_FIELDS = [
        'status', 'assignmentids', 'messageids', 'addmessageids', 'removemessageids', 'addtargets',
        'removetargetids', 'filters', 'targets', 'override',
    ];

    /**
     * Normalize the input of a taskflow skill; foreign skills pass through untouched.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    public function normalize(string $skillname, array $input): array {
        if (strpos($skillname, skill_provider::SKILL_NAMESPACE . '.') !== 0) {
            return $input;
        }

        $normalized = [];
        foreach ($input as $key => $value) {
            $key = (string)$key;

            if (is_string($value)) {
                $value = trim($value);
            }

            if (in_array($key, self::INT_FIELDS, true)) {
                $int = self::to_int($value);
                if ($int === null) {
                    continue;
                }
                $normalized[$key] = $int;
                continue;
            }

            if (in_array($key, self::BOOL_FIELDS, true)) {
                $bool = self::to_bool($value);
                if ($bool === null) {
                    continue;
                }
                $normalized[$key] = $bool;
                continue;
            }

            if (in_array($key, self::LIST_FIELDS, true)) {
                $list = self::to_list($value);
                if ($list === null) {
                    continue;
                }
                $normalized[$key] = $list;
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Integer coercion: ints, floats without fraction and numeric strings; null otherwise.
     *
     * @param mixed $value
     * @return int|null
     */
    public static function to_int($value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value) || $value === null || $value === '') {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int)$value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int)trim($value);
        }
        return null;
    }

    /**
     * Boolean coercion of JSON-typed values (bool, 0/1, "0"/"1", "true"/"false"); null otherwise.
     *
     * @param mixed $value
     * @return bool|null
     */
    public static function to_bool($value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if ($text === '1' || $text === 'true') {
                return true;
            }
            if ($text === '0' || $text === 'false') {
                return false;
            }
        }
        return null;
    }

    /**
     * List coercion: arrays are re-indexed, scalars wrapped, empty values dropped.
     *
     * @param mixed $value
     * @return array|null Null when nothing remains.
     */
    public static function to_list($value): ?array {
        if ($value === null || $value === '') {
            return null;
        }
        $items = is_array($value) ? $value : [$value];
        $list = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                $int = self::to_int($item);
                if ($int !== null) {
                    $item = $int;
                }
            }
            if ($item === null) {
                continue;
            }
            $list[] = $item;
        }
        return empty($list) ? null : $list;
    }
}
