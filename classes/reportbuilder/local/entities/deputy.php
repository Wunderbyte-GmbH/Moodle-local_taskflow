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

namespace local_taskflow\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_user\fields;
use html_writer;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\reportbuilder\datasource\assignment_datasource;
use local_taskflow\reportbuilder\local\filters\user_in_list;
use moodle_url;
use stdClass;

/**
 * Deputies entity for Report Builder.
 *
 * Deputies are stored on the supervisor's own profile, in the custom profile
 * field the active taskflow adapter maps to the deputy, as a comma separated
 * list of user IDs. The entity expects a {user} alias for the supervisor and a
 * {user_info_data} alias for that supervisor's deputy field row; the datasource
 * provides the join.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deputy extends base {
    /** @var stdClass[] Per-request cache of loaded deputy users, keyed by ID. */
    private static array $usercache = [];

    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'user_info_data',
            'user',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:deputy', 'local_taskflow');
    }

    /**
     * Return the ID of the custom profile field holding the deputies, 0 if not configured.
     *
     * @return int
     */
    public static function get_deputy_field_id(): int {
        global $DB;

        $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_DEPUTY);
        if (empty($shortname)) {
            return 0;
        }
        return (int) $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter);
            $this->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        global $DB;

        $dd = $this->get_table_alias('user_info_data');
        $columns = [];

        // Deputy names, comma separated.
        $columns[] = (new column(
            'deputies',
            new lang_string('deputies', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$dd}.data")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $names = [];
                foreach (self::get_deputy_users($value) as $user) {
                    $names[] = fullname($user);
                }
                return s(implode(', ', $names));
            });

        // Deputy names linked to their profiles.
        $columns[] = (new column(
            'deputieswithlink',
            new lang_string('deputieswithlink', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$dd}.data")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $links = [];
                foreach (self::get_deputy_users($value) as $user) {
                    $url = new moodle_url('/user/profile.php', ['id' => $user->id]);
                    $links[] = html_writer::link($url, s(fullname($user)));
                }
                return implode(', ', $links);
            });

        // Raw stored deputy IDs.
        $columns[] = (new column(
            'deputyids',
            new lang_string('deputyids', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$dd}.data")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                return s((string) $value);
            });

        // Number of (existing, non deleted) deputies, counted in SQL so it can be sorted and aggregated.
        $du = database::generate_alias();
        $pattern = $DB->sql_concat("'%,'", $DB->sql_cast_to_char("{$du}.id"), "',%'");
        $contains = user_in_list::get_contains_user_sql("{$dd}.data", $pattern);
        $columns[] = (new column(
            'deputycount',
            new lang_string('deputycount', 'local_taskflow'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("(SELECT COUNT({$du}.id) FROM {user} {$du} WHERE {$du}.deleted = 0 AND {$contains})", 'deputycount')
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        global $DB;

        $dd = $this->get_table_alias('user_info_data');
        $u = $this->get_table_alias('user');
        $filters = [];

        // Has at least one deputy entry.
        $notempty = $DB->sql_isnotempty('user_info_data', "{$dd}.data", true, true);
        $filters[] = (new filter(
            boolean_select::class,
            'hasdeputies',
            new lang_string('hasdeputies', 'local_taskflow'),
            $this->get_entity_name(),
            "CASE WHEN {$dd}.data IS NOT NULL AND {$notempty} THEN 1 ELSE 0 END"
        ))
            ->add_joins($this->get_joins());

        // Deputy is a given user / the current user.
        $filters[] = (new filter(
            user_in_list::class,
            'deputy',
            new lang_string('filter:deputy', 'local_taskflow'),
            $this->get_entity_name(),
            "{$dd}.data"
        ))
            ->add_joins($this->get_joins());

        // Is supervisor of at least one user (somebody's supervisor field points at this user).
        $supervisorfieldid = assignment_datasource::get_supervisor_field_id();
        if ($supervisorfieldid > 0) {
            $sx = database::generate_alias();
            $uid = $DB->sql_cast_to_char("{$u}.id");
            $filters[] = (new filter(
                boolean_select::class,
                'issupervisor',
                new lang_string('issupervisor', 'local_taskflow'),
                $this->get_entity_name(),
                "CASE WHEN EXISTS (SELECT 1 FROM {user_info_data} {$sx}
                                    WHERE {$sx}.fieldid = {$supervisorfieldid} AND {$sx}.data = {$uid})
                      THEN 1 ELSE 0 END"
            ))
                ->add_joins($this->get_joins());
        }

        return $filters;
    }

    /**
     * Resolve a stored comma separated list of user IDs to user records, in stored
     * order, skipping unknown and deleted users.
     *
     * @param mixed $value Raw field value
     * @return stdClass[]
     */
    public static function get_deputy_users($value): array {
        global $DB;

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $value) as $id) {
            $id = trim($id);
            if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_unique($ids);
        if (empty($ids)) {
            return [];
        }

        $missing = array_diff($ids, array_keys(self::$usercache));
        if (!empty($missing)) {
            $fieldlist = 'id, deleted, ' . implode(', ', fields::get_name_fields());
            $records = $DB->get_records_list('user', 'id', $missing, '', $fieldlist);
            foreach ($missing as $id) {
                self::$usercache[$id] = $records[$id] ?? null;
            }
        }

        $users = [];
        foreach ($ids as $id) {
            $user = self::$usercache[$id];
            if (!empty($user) && empty($user->deleted)) {
                $users[] = $user;
            }
        }
        return $users;
    }

    /**
     * Clear the per-request user cache (for tests).
     */
    public static function reset_cache(): void {
        self::$usercache = [];
    }
}
