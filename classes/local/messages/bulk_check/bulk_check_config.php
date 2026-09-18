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
 * Hardcoded configuration of the bulk send checker.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages\bulk_check;

use stdClass;

/**
 * Configuration of the bulk send checker.
 *
 * This is the single seam that the admin interface (part 2) will replace. Until then the
 * configuration lives here, keyed by the name of the message record. Message ids are
 * auto increment and differ between environments, so keying on them would be meaningless
 * on a production site and impossible to set up in a test.
 *
 * A message that has no entry is not bulk checked at all and is sent exactly as before.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_config {
    /** @var int Default grace period between scheduling and the burst check. */
    public const DEFAULT_DELAY = 15 * MINSECS;

    /** @var int Default length of the counting window. */
    public const DEFAULT_PERIOD = HOURSECS;

    /** @var int Default number of sends per period that is still considered normal. */
    public const DEFAULT_LIMIT = 50;

    /**
     * Bulk checked messages, keyed by the name of the local_taskflow_messages record.
     *
     * Each entry accepts:
     *  - limit:  how many sends of that message and rule are allowed within the period
     *  - period: length of the counting window in seconds
     *  - delay:  how long the sending is postponed so the burst can accumulate
     *
     * @var array
     */
    private const LIMITS = [
        // Empty on purpose, so that nothing is bulk checked until it is switched on.
    ];

    /**
     * Users who are informed when a burst was blocked. Empty means all site admins.
     *
     * @var array
     */
    private const NOTIFYUSERIDS = [];

    /**
     * Returns the whole configuration, an admin setting taking precedence over the constant.
     *
     * The setting exists so that a site, and the test suite, can switch the checker on
     * without a code change. It holds the same structure as self::LIMITS, json encoded.
     *
     * @return array
     */
    private static function get_config(): array {
        $json = get_config('local_taskflow', 'bulkcheckconfig');
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return self::LIMITS;
    }

    /**
     * Whether the bulk send checker is switched on for this site at all.
     *
     * While it is off no sending is postponed, nothing is recorded and nothing is blocked,
     * so the messages behave exactly as they did before the checker existed. Switching it
     * off with a burst already queued lets that burst go out on its normal schedule.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('local_taskflow', 'bulkcheckenabled'));
    }

    /**
     * Returns the configuration entry of a message, or null when it is not bulk checked.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return array|null
     */
    public static function get_settings(stdClass $message): ?array {
        if (!self::is_enabled()) {
            return null;
        }
        $config = self::get_config();
        $name = $message->name ?? '';
        if ($name === '' || !isset($config[$name]) || !is_array($config[$name])) {
            return null;
        }
        return $config[$name];
    }

    /**
     * Whether the given message has to pass the bulk check before it is sent.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return bool
     */
    public static function is_bulk_checked(stdClass $message): bool {
        return self::get_settings($message) !== null;
    }

    /**
     * How many sends of this message and rule are allowed within the period.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return int
     */
    public static function get_limit(stdClass $message): int {
        $settings = self::get_settings($message);
        return (int) ($settings['limit'] ?? self::DEFAULT_LIMIT);
    }

    /**
     * Length of the counting window in seconds.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return int
     */
    public static function get_period(stdClass $message): int {
        $settings = self::get_settings($message);
        return (int) ($settings['period'] ?? self::DEFAULT_PERIOD);
    }

    /**
     * How long sending is postponed so that the burst can accumulate in the queue.
     *
     * @param stdClass $message The local_taskflow_messages record.
     * @return int
     */
    public static function get_delay(stdClass $message): int {
        $settings = self::get_settings($message);
        return (int) ($settings['delay'] ?? self::DEFAULT_DELAY);
    }

    /**
     * Users to inform about a blocked burst. Falls back to all site admins.
     *
     * @return array Array of user ids.
     */
    public static function get_notify_userids(): array {
        $userids = self::NOTIFYUSERIDS;
        $setting = get_config('local_taskflow', 'bulkchecknotifyusers');
        if (!empty($setting)) {
            $userids = array_filter(array_map('intval', explode(',', $setting)));
        }
        if (empty($userids)) {
            $userids = array_keys(get_admins());
        }
        return array_values(array_unique(array_map('intval', $userids)));
    }
}
