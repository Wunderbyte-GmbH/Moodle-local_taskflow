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

namespace local_taskflow\local\dashboard;

use context_system;
use core_collator;
use core_user;
use local_taskflow\local\supervisor\supervisor;

/**
 * The persons a viewer has opened as tabs on the dashboard.
 *
 * Stored as a user preference, so the tabs survive a new login. Every read applies the person page scope again
 * (managers with viewreports: everybody; otherwise the own visible team), so a tab disappears as soon as the
 * viewer is no longer in charge of that person.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_tabs {
    /** @var string User preference with the open person tabs as comma separated user ids, in opening order. */
    public const PREFERENCE = 'local_taskflow_persontabs';

    /** @var int Upper limit of open person tabs. */
    public const MAX_TABS = 15;

    /**
     * The open tabs the viewer may still see, in opening order.
     *
     * @param int $viewerid
     * @return int[]
     */
    public static function get(int $viewerid): array {
        return self::in_scope($viewerid, self::load($viewerid));
    }

    /**
     * Opens a tab for a person. The viewer themselves has the "Me" tab and gets no person tab.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool True when the person is open as a tab afterwards.
     */
    public static function open(int $viewerid, int $userid): bool {
        if ($userid === $viewerid || !self::in_scope($viewerid, [$userid])) {
            return false;
        }
        $tabs = self::get($viewerid);
        if (in_array($userid, $tabs, true)) {
            return true;
        }
        if (count($tabs) >= self::MAX_TABS) {
            return false;
        }
        $tabs[] = $userid;
        self::save($viewerid, $tabs);
        return true;
    }

    /**
     * Opens a tab for every member of the viewer's visible team, sorted by name, up to the limit.
     *
     * @param int $viewerid
     * @return array With keys open (team members open as tabs afterwards) and skipped (not opened, limit reached).
     */
    public static function open_team(int $viewerid): array {
        $names = [];
        foreach (array_map('intval', supervisor::get_visible_subordinate_ids($viewerid)) as $userid) {
            $user = core_user::get_user($userid);
            if ($user && empty($user->deleted) && $userid !== $viewerid) {
                $names[$userid] = fullname($user);
            }
        }
        core_collator::asort($names);

        $tabs = self::get($viewerid);
        $skipped = 0;
        foreach (array_keys($names) as $userid) {
            if (in_array($userid, $tabs, true)) {
                continue;
            }
            if (count($tabs) >= self::MAX_TABS) {
                $skipped++;
                continue;
            }
            $tabs[] = $userid;
        }
        self::save($viewerid, $tabs);
        return [
            'open' => count(array_intersect(array_keys($names), $tabs)),
            'skipped' => $skipped,
        ];
    }

    /**
     * Closes the tab of one person.
     *
     * @param int $viewerid
     * @param int $userid
     * @return void
     */
    public static function close(int $viewerid, int $userid): void {
        self::save($viewerid, array_values(array_diff(self::load($viewerid), [$userid])));
    }

    /**
     * Closes all person tabs.
     *
     * @param int $viewerid
     * @return void
     */
    public static function close_all(int $viewerid): void {
        unset_user_preference(self::PREFERENCE, $viewerid);
    }

    /**
     * The persons of a list the viewer may see on the person page: same rule as person_notes_service::is_in_scope(),
     * evaluated once for the whole list.
     *
     * @param int $viewerid
     * @param int[] $userids
     * @return int[]
     */
    private static function in_scope(int $viewerid, array $userids): array {
        $canviewall = has_capability('local/taskflow:viewreports', context_system::instance(), $viewerid);
        $team = null;
        $visible = [];
        foreach ($userids as $userid) {
            $user = core_user::get_user($userid);
            if (!$user || !empty($user->deleted)) {
                continue;
            }
            if (!$canviewall) {
                $team ??= array_map('intval', supervisor::get_visible_subordinate_ids($viewerid));
                if (!in_array($userid, $team, true)) {
                    continue;
                }
            }
            $visible[] = $userid;
        }
        return $visible;
    }

    /**
     * Stored tab list, unfiltered.
     *
     * @param int $viewerid
     * @return int[]
     */
    private static function load(int $viewerid): array {
        $value = (string)get_user_preferences(self::PREFERENCE, '', $viewerid);
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
    }

    /**
     * Stores the tab list.
     *
     * @param int $viewerid
     * @param int[] $userids
     * @return void
     */
    private static function save(int $viewerid, array $userids): void {
        if (empty($userids)) {
            unset_user_preference(self::PREFERENCE, $viewerid);
            return;
        }
        set_user_preference(self::PREFERENCE, implode(',', array_map('intval', $userids)), $viewerid);
    }
}
