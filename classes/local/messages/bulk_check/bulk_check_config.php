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
 * Per message configuration of the bulk send checker.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages\bulk_check;

use cache;
use stdClass;

/**
 * Configuration of the bulk send checker, one row per message.
 *
 * A message that has no row, or whose row is not enabled, is not bulk checked at all and is
 * sent exactly as before. The rows are written from the configuration page, which is the only
 * thing that should ever touch the table directly: every write goes through set_settings() so
 * that the cached copy can never go stale.
 *
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_config {
    /** @var string */
    public const TABLENAME = 'local_taskflow_bulk_config';

    /** @var string The single key the whole table is cached under. */
    private const CACHEKEY = 'all';

    /** @var int Default grace period between scheduling and the burst check. */
    public const DEFAULT_DELAY = 15 * MINSECS;

    /** @var int Default length of the counting window. */
    public const DEFAULT_PERIOD = HOURSECS;

    /** @var int Default number of sends per period that is still considered normal. */
    public const DEFAULT_LIMIT = 50;

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
     * The whole configuration table, keyed by message id.
     *
     * applies() runs once per assignee while a rule is scheduling its messages, so this must
     * not be a query per message. The table holds one row per message, a handful of rows in
     * practice, so a single read serves the entire run.
     *
     * @return array
     */
    private static function get_all(): array {
        global $DB;

        // The static acceleration of the cache is what keeps this cheap inside the loop.
        // Do not add a static property here as well: it would survive a cache purge, and
        // in the test runner it would survive the reset between two tests.
        $cache = cache::make('local_taskflow', 'bulkcheckconfig');
        $configs = $cache->get(self::CACHEKEY);
        if ($configs === false) {
            $configs = $DB->get_records(
                self::TABLENAME,
                null,
                '',
                'messageid, enabled, limitcount'
            );
            $cache->set(self::CACHEKEY, $configs);
        }

        return $configs;
    }

    /**
     * Drops the cached copy of the table.
     *
     * @return void
     */
    private static function invalidate(): void {
        cache::make('local_taskflow', 'bulkcheckconfig')->delete(self::CACHEKEY);
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

        $configs = self::get_all();
        $messageid = (int) ($message->id ?? 0);
        if (empty($configs[$messageid]) || empty($configs[$messageid]->enabled)) {
            return null;
        }

        // Only the limit is set per message. The window and the delay are the same for
        // every message, so that the sending stays regular and nobody has to look up a
        // different value for each one.
        return [
            'limit' => (int) $configs[$messageid]->limitcount,
            'period' => self::get_global_period(),
            'delay' => self::get_global_delay(),
        ];
    }

    /**
     * Length of the counting window, the same for every message.
     *
     * @return int
     */
    public static function get_global_period(): int {
        $period = (int) get_config('local_taskflow', 'bulkcheckperiod');
        return $period > 0 ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * How long sending is postponed, the same for every message.
     *
     * @return int
     */
    public static function get_global_delay(): int {
        $delay = get_config('local_taskflow', 'bulkcheckdelay');
        return $delay === false || $delay === '' ? self::DEFAULT_DELAY : (int) $delay;
    }

    /**
     * Whether the bulk check can ever act on a message of this class.
     *
     * Only standard and onevent messages reach the check at all, so offering the setting
     * on any other type would store something that can never take effect.
     *
     * @param string|null $class
     * @return bool
     */
    public static function is_checkable_class(?string $class): bool {
        return in_array($class ?? '', ['standard', 'onevent'], true);
    }

    /**
     * Returns the stored row of a message, whether it is enabled or not.
     *
     * Used by the configuration page, which has to show the numbers of a message that is
     * currently switched off just as much as of one that is on.
     *
     * @param int $messageid
     * @return stdClass|null
     */
    public static function get_record(int $messageid): ?stdClass {
        $configs = self::get_all();
        return $configs[$messageid] ?? null;
    }

    /**
     * Stores the configuration of one message.
     *
     * @param int $messageid
     * @param bool $enabled
     * @param int $limit
     * @return void
     */
    public static function set_settings(
        int $messageid,
        bool $enabled,
        int $limit = self::DEFAULT_LIMIT
    ): void {
        global $DB, $USER;

        $now = time();
        $record = $DB->get_record(self::TABLENAME, ['messageid' => $messageid]);

        if (empty($record)) {
            $DB->insert_record(self::TABLENAME, (object) [
                'messageid' => $messageid,
                'enabled' => $enabled ? 1 : 0,
                'limitcount' => $limit,
                'usermodified' => $USER->id ?? 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            $DB->update_record(self::TABLENAME, (object) [
                'id' => $record->id,
                'enabled' => $enabled ? 1 : 0,
                'limitcount' => $limit,
                'usermodified' => $USER->id ?? 0,
                'timemodified' => $now,
            ]);
        }

        self::invalidate();
    }

    /**
     * Removes the configuration of a message, for when the message itself is deleted.
     *
     * @param int $messageid
     * @return void
     */
    public static function delete_settings(int $messageid): void {
        global $DB;
        $DB->delete_records(self::TABLENAME, ['messageid' => $messageid]);
        self::invalidate();
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
        $userids = [];
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
