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

namespace local_taskflow\local\personnotes;

use context_system;
use local_taskflow\local\supervisor\supervisor;
use stdClass;

/**
 * Notes HR and supervisors keep about a person on the person page.
 *
 * Every right is a capability AND a scope check: managers (viewreports) reach everybody,
 * supervisors and deputies only the members of their team. The person themselves never sees the notes.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_notes_service {
    /**
     * Whether the viewer is in charge of the person: manager, or supervisor/deputy of the person.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function is_in_scope(int $viewerid, int $userid): bool {
        if (has_capability('local/taskflow:viewreports', context_system::instance(), $viewerid)) {
            return true;
        }
        return in_array($userid, array_map('intval', supervisor::get_visible_subordinate_ids($viewerid)), true);
    }

    /**
     * Whether the viewer may read the notes about a person.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_view(int $viewerid, int $userid): bool {
        if ($viewerid === $userid) {
            return false;
        }
        return has_capability('local/taskflow:viewpersonnotes', context_system::instance(), $viewerid)
            && self::is_in_scope($viewerid, $userid);
    }

    /**
     * Whether the viewer may add notes about a person.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_create(int $viewerid, int $userid): bool {
        if ($viewerid === $userid) {
            return false;
        }
        return has_capability('local/taskflow:createpersonnotes', context_system::instance(), $viewerid)
            && self::is_in_scope($viewerid, $userid);
    }

    /**
     * Whether the viewer may delete a note.
     *
     * Own notes: deletepersonnotes, and only within the configured time window after writing
     * (setting personnotesdeletewindow, 0 = no limit). Notes of others: deleteotherspersonnotes.
     * Both always within the viewer's scope (own team, or everybody as manager).
     *
     * @param int $viewerid
     * @param stdClass $note
     * @return bool
     */
    public static function can_delete(int $viewerid, stdClass $note): bool {
        if ($viewerid === (int)$note->userid || !self::is_in_scope($viewerid, (int)$note->userid)) {
            return false;
        }
        $context = context_system::instance();
        if ((int)$note->usermodified === $viewerid) {
            if (!has_capability('local/taskflow:deletepersonnotes', $context, $viewerid)) {
                return false;
            }
            $window = (int)get_config('local_taskflow', 'personnotesdeletewindow');
            return $window <= 0 || (time() - (int)$note->timecreated) <= $window;
        }
        return has_capability('local/taskflow:deleteotherspersonnotes', $context, $viewerid);
    }

    /**
     * Adds a note.
     *
     * @param int $userid The person the note is about.
     * @param string $note
     * @param int $authorid
     * @return int The note id.
     * @throws \required_capability_exception
     */
    public function add(int $userid, string $note, int $authorid): int {
        global $DB;
        if (!self::can_create($authorid, $userid)) {
            throw new \required_capability_exception(
                context_system::instance(),
                'local/taskflow:createpersonnotes',
                'nopermissions',
                ''
            );
        }
        $now = time();
        return $DB->insert_record('local_taskflow_person_notes', (object)[
            'userid' => $userid,
            'note' => trim($note),
            'noteformat' => FORMAT_PLAIN,
            'usermodified' => $authorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Deletes a note.
     *
     * @param int $noteid
     * @param int $viewerid
     * @return void
     * @throws \required_capability_exception
     */
    public function delete(int $noteid, int $viewerid): void {
        global $DB;
        $note = $DB->get_record('local_taskflow_person_notes', ['id' => $noteid], '*', MUST_EXIST);
        if (!self::can_delete($viewerid, $note)) {
            throw new \required_capability_exception(
                context_system::instance(),
                'local/taskflow:deletepersonnotes',
                'nopermissions',
                ''
            );
        }
        $DB->delete_records('local_taskflow_person_notes', ['id' => $noteid]);
    }

    /**
     * The notes about a person, newest first.
     *
     * @param int $userid
     * @return stdClass[]
     */
    public static function get_notes(int $userid): array {
        global $DB;
        return $DB->get_records('local_taskflow_person_notes', ['userid' => $userid], 'timecreated DESC, id DESC');
    }
}
