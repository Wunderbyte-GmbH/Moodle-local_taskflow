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
 * Shortcodes handler
 *
 * @package local_taskflow
 * @subpackage db
 * @since Moodle 4.1
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow;

use context_system;

/**
 * Deals with local_shortcodes regarding taskflow.
 */
class shortcodes_handler {
    /**
     * Checks if the conditions for the shortcode are valid.
     *
     * @param mixed $shortcode
     * @param mixed $args
     * @param array $requiredcapabilities
     * @param array $requiredargs
     *
     * @return array
     *
     */
    public static function validatecondition($shortcode, $args, $requiredcapabilities = [], $requiredargs = []) {
        global $CFG;

        $answerarray = [
            'error' => 0,
            'message' => "",
        ];
        $answerarray = self::shortcodes_capabilitiescheck($shortcode, $answerarray, $requiredcapabilities);
        if ($answerarray['error'] == 1) {
            // When not in debug mode, return empty string.
            if (!debugging()) {
                $answerarray['message'] = '';
            }
            return $answerarray;
        }

        $answerarray = self::shortcodes_passwordcheck($shortcode, $answerarray, $args);
        if ($answerarray['error'] == 1) {
            return $answerarray;
        }

        $answerarray = self::requires_args($shortcode, $answerarray, $args, $requiredargs);

        return $answerarray;
    }

    /**
     * Check if shortcodes passwort is valid.
     * If no password is set, no error is thrown.
     *
     * @param string $shortcode
     * @param array $answerarray
     * @param array $args
     *
     * @return array
     */
    private static function shortcodes_passwordcheck($shortcode, &$answerarray, $args) {

        $password = get_config('local_taskflow', 'shortcodespassword');
        if (empty($password)) {
            return $answerarray;
        }
        // If the password matches, proceed.
        if (($args['password'] ?? '') == $password) {
            return $answerarray;
        }

        $answerarray['error'] = 1;
        $answerarray['message'] = "<div class='alert alert-warning'>" .
            get_string('shortcodesispasswordprotected', 'local_taskflow', $shortcode) .
            "</div>";
        return $answerarray;
    }

    /**
     * Checks if all required arguments are in the shortcode.
     *
     * @param mixed $shortcode
     * @param array $answerarray
     * @param array $args
     * @param array $requiredargs
     *
     * @return array
     *
     */
    private static function requires_args($shortcode, &$answerarray, $args, $requiredargs) {
        foreach ($requiredargs as $arg) {
            if (empty($args[$arg])) {
                $answerarray['error'] = 1;
                $missingarg = $arg;
                break;
            }
        }
        if (!empty($missingarg)) {
            $answerarray['message'] = get_string('shortcodeargumentismissing', 'local_taskflow', $missingarg);
        } else {
            $answerarray['error'] = 0;
        }
        return $answerarray;
    }

    /**
     * Check if shortcodes passwort is valid.
     * If no password is set, no error is thrown.
     *
     * @param string $shortcode
     * @param array $answerarray
     * @param array $requiredcapabilities
     *
     * @return array
     */
    private static function shortcodes_capabilitiescheck($shortcode, &$answerarray, $requiredcapabilities) {

        if (empty($requiredcapabilities)) {
            return $answerarray;
        }

        $missingcap = [];
        foreach ($requiredcapabilities as $capability) {
            if (!has_capability($capability, context_system::instance())) {
                $missingcap[] = $capability;
            }
        }

        if (empty($missingcap)) {
            return $answerarray;
        }
        $capstring = implode(', ', $missingcap);

        $answerarray['error'] = 1;
        $answerarray['message'] = "<div class='alert alert-warning'>" .
            get_string('shortcodesmissingcapability', 'local_taskflow', $capstring) .
            "</div>";
        return $answerarray;
    }
}
