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

namespace local_taskflow\local\external_adapter;

use local_taskflow\event\upload_error;
use local_taskflow\plugininfo\taskflowadapter;
use function PHPUnit\Framework\isEmpty;

/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_error_logger {
    /** @var bool Stores the external user data. */
    protected bool $usererror;

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $label
     * @param string $translatedvalue
     * @return void
     */
    protected function value_validation($label, $translatedvalue) {

        $triggerevent = false;

        // First check if we need to validate at all.
        $functionname = $this->return_function_by_jsonkey($label);
        if (empty($functionname)) {
            return;
        }

        switch ($functionname) {
            case taskflowadapter::TRANSLATOR_USER_FIRSTNAME:
            case taskflowadapter::TRANSLATOR_USER_LASTNAME:
                if (
                    empty($translatedvalue)
                    || !is_string($translatedvalue)
                ) {
                    $triggerevent = true;
                } else {
                    $this->string_validation($translatedvalue);
                }
                break;
            case taskflowadapter::TRANSLATOR_USER_EMAIL:
                if (
                    empty($translatedvalue)
                    || !is_string($translatedvalue)
                ) {
                    $triggerevent = true;
                }
                break;
            case taskflowadapter::TRANSLATOR_USER_SUPERVISOR:
                if (
                    !empty($translatedvalue)
                    && !is_numeric($translatedvalue)
                ) {
                    $triggerevent = true;
                }
                break;
            case taskflowadapter::TRANSLATOR_USER_LONG_LEAVE:
                if (
                    !empty($translatedvalue)
                    && !is_bool($translatedvalue)
                ) {
                    $triggerevent = true;
                }
                break;
        }
        if (
            $triggerevent
        ) {
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Invalid mandatory value: '$label'",
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param string $translatedstring
     * @return void
     */
    protected function string_validation($translatedstring) {
        if (
            !mb_check_encoding($translatedstring, 'UTF-8') ||
            mb_strpos($translatedstring, '�') !== false
        ) {
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Broken UTF-8 string: '$translatedstring'",
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param mixed $units
     * @return void
     */
    protected function units_validation($units) {
        if (
            !is_array($units) &&
            !empty($units)
        ) {
            $label = json_encode($units);
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Invalid mandatory string: '$label'",
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param mixed $date
     * @param string $datestring
     * @return void
     */
    protected function dates_validation($date, $datestring) {
        if (!$date) {
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Invalid date format: " . $datestring,
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param mixed $boolvalue
     * @return bool
     */
    protected function bool_validation($boolvalue) {
        if (!is_bool($boolvalue)) {
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Invalid boolean format: " . $boolvalue,
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return filter_var($boolvalue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param mixed $supervisorid
     * @return void
     */
    protected function supervisor_validation($supervisorid) {
        if (!is_number($supervisorid)) {
            $event = upload_error::create([
                'objectid' => 400,
                'context'  => \context_system::instance(),
                'other'    => [
                    'message' => "Invalid supervisor format: " . $supervisorid,
                ],
            ]);
            $event->trigger();
            $this->usererror = false;
        }
        return;
    }
}
