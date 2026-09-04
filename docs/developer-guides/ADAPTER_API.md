[Back to documentation index](../README.md)

# Taskflow Adapters — Full Developer API

This is the complete developer reference for building a `taskflowadapter_*` subplugin of `local_taskflow`.
An adapter is the customer-specific layer of Taskflow: it understands the external HR data format, maps it to
Moodle users, profile fields, units/cohorts and supervisors, and may override strings, dashboards, tables,
edit forms and settings. Three adapters ship in-tree and serve as reference implementations:
`standard` (generic flat HR export), `ksw` (Kantonsspital Winterthur) and `tuines` (TU Wien INES data
warehouse). For the admin view see [Adapters](../user/adapters/README.md).

---

## Quick setup path

1. Create `local/taskflow/taskflowadapter/<name>/` with `version.php`, `lang/en/taskflowadapter_<name>.php`,
   `classes/taskflowadapter_<name>.php` and `classes/adapter.php` (section 2).
2. Implement `load_settings()` (section 4) and `process_incoming_data()` (section 5).
3. Install, select the adapter under *Site administration › Plugins › Local plugins › Taskflow ›
   External api with user data* (`local_taskflow/external_api_option`), map JSON keys and functions to profile
   fields (section 6).
4. Import a JSON sample via `/local/taskflow/view.php` → *Upload users*, or through your own fetch task.
5. Write PHPUnit use-case tests with the `local_taskflow` generator and a JSON fixture (section 10).

---

## Table of Contents

1. [How core selects and uses an adapter](#1-how-core-selects-and-uses-an-adapter)
2. [Plugin structure](#2-plugin-structure)
3. [Base classes and interfaces](#3-base-classes-and-interfaces)
4. [Settings class: `load_settings()` and `get_supervisor_for_user()`](#4-settings-class-load_settings-and-get_supervisor_for_user)
5. [Import class: `adapter::process_incoming_data()`](#5-import-class-adapterprocess_incoming_data)
6. [Translator and function mapping](#6-translator-and-function-mapping)
7. [String overrides via the string manager](#7-string-overrides-via-the-string-manager)
8. [Output, form and table override hooks](#8-output-form-and-table-override-hooks)
9. [Events to fire and events to observe](#9-events-to-fire-and-events-to-observe)
10. [Testing with the generator and fixtures](#10-testing-with-the-generator-and-fixtures)
11. [Checklist and known limitations](#11-checklist-and-known-limitations)

---

## 1. How core selects and uses an adapter

The subplugin type is declared in `db/subplugins.json` (`taskflowadapter` → `local/taskflow/taskflowadapter`).
Exactly one adapter is **active**, chosen by the admin setting `local_taskflow/external_api_option`
(select; options = all installed adapters; option label = the lang string `$string['<name>']` of the adapter,
e.g. `standard` "Standard API", `tuines` "Ines API").

Core never lists adapters in code. It builds class names from the setting value and falls back to `standard`
or to a core class when yours does not exist:

| Core dispatch point | Class / resource looked up in `taskflowadapter_<active>` | Fallback |
|---|---|---|
| `local\external_adapter\external_api_repository::create($json)` — import entry (`form\uploaduser`, your fetch task) | `\taskflowadapter_<x>\adapter` | `moodle_exception("Invalid external API type")` |
| `local\eventhandlers\core_user_created_updated::handle()` — every core `user_created`/`user_updated` | `\taskflowadapter_<x>\adapter("", …)` → `necessary_customfields_exist()`, `is_allowed_to_react_on_user_events()`, `set_users()`, `process_incoming_data()` | — (skipped entirely when the active adapter is `tuines`) |
| `local\eventhandlers\unit_member_updated::not_on_longleave_or_inactive()` | `\taskflowadapter_<x>\adapter("", …)` → `return_value_for_functionname(TRANSLATOR_USER_LONG_LEAVE, $user)` | — |
| `settings.php` | `\taskflowadapter_<x>\taskflowadapter_<x>::load_settings($ADMIN, 'local_taskflow_settings', $hassiteconfig)` — called for **every installed** adapter, not only the active one | — |
| `local\supervisor\supervisor::get_supervisor_for_user()` | `\taskflowadapter_<x>\taskflowadapter_<x>::get_supervisor_for_user($userid)` | `local_taskflow\plugininfo\taskflowadapter::get_supervisor_for_user()` (uses core setting `supervisor_field`) |
| `taskflow_stringmanager::get_string()` (used by almost all UI code) | string `$identifier` in component `taskflowadapter_<x>` | same key in `local_taskflow` |
| `output\dashboard::set_data()` | `\taskflowadapter_<x>\output\supervisordashboard`, `…\admindashboard` | `taskflowadapter_standard\output\*` |
| `output\assignmentsdashboard::set_table()` | `\taskflowadapter_<x>\table\assignments_table` | `local_taskflow\table\assignments_table` |
| `output\editassignment_template_data_factory::get_data()` | `\taskflowadapter_<x>\output\editassignment_template_data_supervisor` (viewer is supervisor without `local/taskflow:editassignment`) or `…_admin` | `taskflowadapter_standard\output\editassignment_template_data` |
| `output\editassignment` (legacy core renderable) | `\taskflowadapter_<x>\form\editassignment` | `taskflowadapter_standard\form\editassignment` |
| `local\assignment_status\assignment_status_facade` | config `taskflowadapter_<x>/excludestatus` (CSV of status ids) | none |
| `settings.php` internal-communication block | file `taskflowadapter/<x>/classes/form/internal_communication_form.php` exists | block hidden |

Things registered through Moodle's own plugin mechanisms — `db/events.php` observers, `db/shortcodes.php`,
`db/tasks.php`, `lib.php` callbacks such as `<component>_render_navbar_output()` — are active for **every
installed** adapter regardless of the setting. Guard them with
`get_config('local_taskflow', 'external_api_option') === '<name>'` if they must only run when your adapter is
selected (the in-tree adapters do not, see debt item D-39 in the architecture overview).

---

## 2. Plugin structure

```
local/taskflow/taskflowadapter/<name>/
├── version.php                          component = 'taskflowadapter_<name>', dependencies local_taskflow
├── README.md, LICENSE
├── lang/en/taskflowadapter_<name>.php   REQUIRED keys: pluginname, <name>, apisettings (+ overrides, section 7)
├── lang/de/…                            optional
├── classes/
│   ├── taskflowadapter_<name>.php       REQUIRED  \taskflowadapter_<name>\taskflowadapter_<name>
│   │                                              extends \local_taskflow\plugininfo\taskflowadapter
│   ├── adapter.php                      REQUIRED  \taskflowadapter_<name>\adapter
│   │                                              extends external_api_base implements external_api_interface
│   ├── observer.php                     optional  observers declared in db/events.php
│   ├── task/<fetch>.php                 optional  scheduled task pulling the feed (tuines: fetch_dwh_data)
│   ├── event/*.php                      optional  own events (tuines: dwh_fetch_failed)
│   ├── output/
│   │   ├── supervisordashboard.php      optional  hook, section 8.2
│   │   ├── admindashboard.php           optional  hook, section 8.2
│   │   ├── editassignment_template_data_admin.php       optional  hook, section 8.3
│   │   └── editassignment_template_data_supervisor.php  optional  hook, section 8.3
│   ├── form/
│   │   ├── editassignment*.php          optional  dynamic forms used by your template-data classes
│   │   ├── comment_form.php             optional  (tuines)
│   │   └── internal_communication_form.php  optional  presence enables the chat settings block
│   ├── table/assignments_table.php      optional  hook, section 8.1
│   └── shortcodes.php                   optional  with db/shortcodes.php
├── db/
│   ├── events.php                       optional  observers (always active once installed)
│   ├── tasks.php                        optional  scheduled tasks
│   ├── shortcodes.php                   optional  filter_shortcodes registrations
│   └── caches.php                       optional  own MUC definitions (tuines: commenthistorylist)
├── templates/*.mustache                 optional  (tuines: singleassignment; ksw: naventry)
├── lib.php                              optional  Moodle callbacks, e.g. taskflowadapter_<name>_render_navbar_output()
└── tests/                               PHPUnit use cases + JSON fixtures (section 10)
```

Adapters own **no DB tables, capabilities or web services** in the in-tree implementations; all state lives in
`local_taskflow_*` and core tables. Nothing prevents you from adding `db/install.xml` or `db/access.php`, but the
core hooks do not require them.

`version.php` example:

```php
$plugin->component = 'taskflowadapter_<name>';
$plugin->version   = 2026090100;
$plugin->requires  = 2024042200;
$plugin->release   = '1.0.0';
$plugin->maturity  = MATURITY_STABLE;
$plugin->dependencies = ['local_taskflow' => 2026090100];
```

---

## 3. Base classes and interfaces

### 3.1 `local_taskflow\plugininfo\taskflowadapter` (extends `core\plugininfo\base`)

Base of your settings class. Provides the **function constants** (the values stored in the per-field
"function" settings) and settings-building helpers.

| Constant | Value | Label (en) | Semantics |
|---|---|---|---|
| `TRANSLATOR_USER_FIRSTNAME` | `translator_user_firstname` | First name | core user field (JSON key setting, no profile field) |
| `TRANSLATOR_USER_LASTNAME` | `translator_user_lastname` | Last name | core user field |
| `TRANSLATOR_USER_EMAIL` | `translator_user_email` | Email | core user field; also used for user matching |
| `TRANSLATOR_USER_TARGETGROUP` | `translator_user_units` | Target group | profile field holding a JSON array of external unit ids (tuines) |
| `TRANSLATOR_USER_ORGUNIT` | `translator_user_orgunit` | Organisational unit | profile field holding an org path (`A\B\C`, standard/ksw) |
| `TRANSLATOR_USER_SUPERVISOR` | `translator_user_supervisor` | Supervisor | profile field holding the **Moodle user id** of the supervisor; read by core for scope, mails, requests |
| `TRANSLATOR_USER_DEPUTY` | `translator_user_deputy` | Deputy | profile field with a comma-separated list of Moodle user ids; read by core (`deputy`, dashboards, requests) |
| `TRANSLATOR_USER_SUPERVISOR_EXTERNAL` | `translator_user_supervisor_external` | Supervisor (external Moodle id) | external id of the supervisor, resolved by the adapter |
| `TRANSLATOR_USER_LONG_LEAVE` | `translator_user_longleave` | Long Leave | 0/1; read by core `unit_member_updated` handler and by adapters |
| `TRANSLATOR_USER_CONTRACTEND` | `translator_user_contractend` | Contract end | unix timestamp (mapped from a date string) |
| `TRANSLATOR_USER_CONTRACTSTART` | `translator_user_contractstart` | Contract start | unix timestamp; usable in rule filters |
| `TRANSLATOR_USER_EXTERNALID` | `translator_user_externalid` | external ID | the customer's person id; used to find existing users |
| `TRANSLATOR_TARGET_GROUP_NAME` / `_DESCRIPTION` / `_UNITID` / `_PARENT` | `translator_target_group_name` … | Name / Description / Organisational unit / (parent) | JSON keys inside a unit record |

| Method | Visibility | Purpose |
|---|---|---|
| `is_enabled()`, `is_uninstall_allowed()`, `uninstall_cleanup()` | public | plugininfo boilerplate (`true`, `true`, parent) |
| `return_user_label_settings(): array` | protected | `['' => 'No function'] + [constant => label]` — option list for the per-field function select |
| `return_target_label_settings(): array` | protected | labels for the three target-group constants |
| `check_functions_usage(array $usercustomfields, string $componentname, object $settings): void` | protected | adds warning descriptions `lessfunctions` / `manyfunctions` when the number of fields with a function differs from the number of functions |
| `return_setting_special_treatment_fields(object $settings, string $component): void` | protected | adds the description `mappingdescription` and the three `translator_user_firstname/lastname/email` text settings |
| `return_setting_mappingdescription()` | protected | empty (dead) |
| `get_supervisor_for_user(int $userid): stdClass` | public static | default implementation using core setting `local_taskflow/supervisor_field`; override it (section 4.2) |

### 3.2 `local_taskflow\local\external_adapter\external_api_interface`

```php
interface external_api_interface {
    public function process_incoming_data();
}
```

### 3.3 `local_taskflow\local\external_adapter\external_api_base` (abstract, extends `external_api_error_logger`)

Base of your `adapter` class.

```php
public function __construct(
    string $data,                                   // raw JSON (may be "" for the user-event path)
    user_repository_interface $userrepo,            // local\personas\moodle_users\moodle_user_factory
    unit_member_repository_interface $unitmemberrepo, // local\personas\unit_members\moodle_unit_member_facade
    ?organisational_unit_factory $unitrepo = null   // local\units\organisational_unit_factory
);
```

Properties you work with: `protected stdClass $externaldata` (decoded JSON — a top-level JSON *array* becomes
an object with numeric property names), `$userrepo`, `$unitmemberrepo`, `$unitrepo`, `protected array
$unitmapping` (your external unit id → Moodle unit/cohort id map), `public static bool $importing`.

| Method | Vis. | What it does |
|---|---|---|
| `translate_incoming_data(stdClass $record): array` | protected | For every `translator_user_*` setting of the active adapter with a non-empty JSON key: reads `$record->{key}`, runs `value_validation()`, `map_value()`; returns `[internallabel => value]` where the label is `firstname`/`lastname`/`email` or the **profile field shortname**. Note: a `->` path is split but each segment is read from the *top-level* record — nested paths are not descended here (they are in `translate_incoming_target_groups()`). |
| `translate_incoming_target_groups(array $record): array` | protected | Same for `translator_target_group_*` keys; descends `->` paths; `string_validation()`. |
| `return_value_for_functionname(string $fn, stdClass $user): mixed` | public | `$user->profile[<shortname mapped to fn>] ?? ''`. Core calls this on your adapter for `TRANSLATOR_USER_LONG_LEAVE`. |
| `return_shortname_for_functionname(string $fn): string` | public static | profile-field shortname whose function setting equals `$fn` (`''` if unmapped). Used everywhere in core (supervisor, deputy, long leave, external id). |
| `return_jsonkey_for_functionname(string $fn): string` | public static | the configured JSON key for a function. |
| `return_function_by_jsonkey(string $jsonkey)` / `return_shortname_by_jsonkey(string $jsonkey)` | public static | reverse lookups. |
| `create_user_with_customfields(stdClass &$user, array $translated, string $externalidfieldname): void` | public | copies translated values whose key is an existing custom profile field into `$user->profile[...]`, stores the user in the static cache. |
| `save_all_user_infos(array $users): void` | public | `profile_save_custom_fields()` for each cached user (arrays are JSON-encoded). **This is what persists profile fields** — call it once at the end of the import. |
| `set_users(stdClass $user)`, `store_user_in_static(stdClass $user, string $externalid = '')`, `get_user_by_moodle_id()`, `get_user_by_externalid()`, `get_user_by_email()`, `get_user_by_mail()`, `return_static_users()` | public (static) | per-request user cache keyed by external id / Moodle id / email. `store_user_in_static()` falls back to `username` as external id (see D-25). |
| `get_user_from_db_by_externalid(string $externalid): stdClass` | public static | DB lookup through the profile field mapped to `TRANSLATOR_USER_EXTERNALID` (or `username LIKE` when unmapped); throws on more than one match. |
| `trigger_unit_relation_updated_events(array $relationupdate)`, `trigger_unit_member_updated_events(array $unitmembers)` | protected | fire the two core events (payload shapes in section 9). |
| `get_external_data()` | public | decoded data. |
| `teardown()` / `reset_static_caches()` / `destroy_instance()` | public static | clear the static caches (call in tests). |
| `map_value($value, string $jsonkey, array &$user)` | **private** | `TRANSLATOR_USER_LONG_LEAVE` → 0/1; `CONTRACTEND`/`CONTRACTSTART` → `strtotime()` (false if invalid, clamped to 9999999999). Being private it **cannot be overridden** for `translate_incoming_data()`; if you need extra mapping (e.g. splitting an org path) call your own method after translation, as `standard\adapter::translate_users()` does. |

Validation helpers from `external_api_error_logger` (all `protected`, all fire `\local_taskflow\event\upload_error`
with `other.message` instead of throwing): `value_validation($label, $value)`, `string_validation($s)`,
`units_validation($units)`, `dates_validation($date, $datestring)`, `bool_validation($b): bool`,
`supervisor_validation($id)`.

### 3.4 Repositories handed to the constructor

| Object | Method | Effect |
|---|---|---|
| `moodle_user_factory` (`user_repository_interface`) | `update_or_create(array $translated): stdClass\|false` | finds the user by external id → username → email, creates one otherwise (`local_taskflow/defaultauth`, random password, transliterated `firstname.lastname` username); updates firstname/lastname/email/phone when changed. Fires core `user_created`/`user_updated`. |
| | `inactivate_moodle_users(array $persons)`, `activate_moodle_users(array $persons)` | suspend / un-suspend (skips admins) |
| `organisational_unit_factory` | `::create_unit(stdClass $data)` | `unit` mode: `{name, parent?}`; `cohort` mode: `{name, description?, idnumber?, unitid?, parent?, parentunitid?}`. Returns a `unit_relations` object when a **new** parent relation was created, otherwise the unit/cohort object — check `instanceof unit_relations` and use `get_childid()`. |
| | `::instance(int $id)` | load a unit/cohort |
| `moodle_unit_member_facade` (`unit_member_repository_interface`) | `update_or_create($user, int $unitid): ?unit_member` | inserts the `local_taskflow_unit_members` row; returns `null` when the membership already exists (so a non-null result means "new membership") |
| | `remove($userid, $unitid)` | deletes the membership row |

Core helpers you will typically call from an adapter:

| Helper | Purpose |
|---|---|
| `local\supervisor\supervisor($supervisorid, $userid)->set_supervisor_for_user($supervisorid, $shortname, $user, $users)` | writes the supervisor's Moodle id into the supervisor profile field (in memory) and assigns the role `local_taskflow/supervisorrole` in system context |
| `local\assignments\assignments_facade::set_user_units_assignments_inactive($userid, $unitids)` | user lost units → assignments of those units `droppedout`, memberships inactive |
| `assignments_facade::set_user_units_assignments_active($userid, $unitids)` | user regained units → `assigned` (completed stays), memberships active |
| `assignments_facade::set_all_assignments_inactive($userid)` | contract end / long leave → all assignments `paused` |
| `assignments_facade::set_all_paused_assignments_active($userid)` | back from leave → paused ones `assigned`, counters reset, sent messages removed |
| `assignments_facade::set_all_assignments_of_user_to_status($userid, $status)` | e.g. missing person → `droppedout` |
| `local\assignment_process\longleave_facade::longleave_activation($userid)` / `longleave_deactivation($userid)` | memberships inactive + paused / reactivate + re-run rules for the user |
| `local\personas\unit_members\types\unit_member::inactivate_all_active_units_of_user()`, `activate_all_inactive_units_of_user()` | membership `active` flag |
| `cohort_add_member()`, `cohort_remove_member()`, `cohort_is_member()` (core `cohort/lib.php`) | in cohort mode keep core cohort membership in sync; `observer::cohort_member_added` mirrors it into `local_taskflow_unit_members` only when `local_taskflow/cohortenrollment` is on and fires `unit_member_updated` |
| `\core\task\manager::reschedule_or_queue_adhoc_task(new \local_taskflow\task\check_supervisor())` | revoke the supervisor role from users nobody references any more |

---

## 4. Settings class: `load_settings()` and `get_supervisor_for_user()`

File `classes/taskflowadapter_<name>.php`:

```php
namespace taskflowadapter_<name>;

use admin_setting_configcheckbox;
use admin_setting_configmultiselect;
use admin_setting_configselect;
use admin_setting_configtext;
use admin_setting_heading;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\taskflow_stringmanager;

class taskflowadapter_<name> extends taskflowadapter {
    private const COMPONENTNAME = 'taskflowadapter_<name>';

    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        if (!$hassiteconfig) {
            return;
        }
        $settings = $adminroot->locate($parentnodename);        // the local_taskflow settings page

        $usercustomfields = [];
        foreach (profile_get_custom_fields() as $field) {
            $usercustomfields[$field->shortname] = $field->name;
        }

        $settings->add(new admin_setting_heading(
            self::COMPONENTNAME . '_api_settings',
            get_string('apisettings', self::COMPONENTNAME),
            taskflow_stringmanager::get_string('apisettings_desc')
        ));

        // Warning alerts + the three fixed JSON-key settings for firstname/lastname/email.
        parent::check_functions_usage($usercustomfields, self::COMPONENTNAME, $settings);
        parent::return_setting_special_treatment_fields($settings, self::COMPONENTNAME);

        // Per custom profile field: JSON key + function.
        foreach ($usercustomfields as $shortname => $label) {
            $settings->add(new admin_setting_configtext(
                self::COMPONENTNAME . '/translator_user_' . $shortname,
                taskflow_stringmanager::get_string('jsonkey') . $label,
                taskflow_stringmanager::get_string('enter_value'), '', PARAM_TEXT
            ));
            $settings->add(new admin_setting_configselect(
                self::COMPONENTNAME . '/' . $shortname,
                taskflow_stringmanager::get_string('function') . $label,
                taskflow_stringmanager::get_string('set:function'), '',
                parent::return_user_label_settings()
            ));
        }

        // JSON keys inside a unit / target-group record.
        foreach (parent::return_target_label_settings() as $key => $label) {
            $settings->add(new admin_setting_configtext(
                self::COMPONENTNAME . '/' . $key,
                taskflow_stringmanager::get_string('jsonkey') . $label,
                taskflow_stringmanager::get_string('enter_value'), '', PARAM_TEXT
            ));
        }

        // Optional: profile fields that must be filled before a Moodle user event re-syncs the user.
        if (adapter::is_allowed_to_react_on_user_events()) {
            $settings->add(new admin_setting_configmultiselect(
                self::COMPONENTNAME . '/necessaryuserprofilefields',
                taskflow_stringmanager::get_string('necessaryuserprofilefields'),
                taskflow_stringmanager::get_string('necessaryuserprofilefieldsdesc'), [], $usercustomfields
            ));
        }

        // Optional but recommended: statuses this customer does not use (read by core for every adapter).
        $settings->add(new admin_setting_configmultiselect(
            self::COMPONENTNAME . '/excludestatus',
            taskflow_stringmanager::get_string('excludestatus'),
            taskflow_stringmanager::get_string('excludestatus_desc'), [],
            assignment_status_facade::get_all_names()
        ));

        // Your own settings (feed URL, feature flags, …).
        $settings->add(new admin_setting_configtext(
            self::COMPONENTNAME . '/feedurl', get_string('feedurl', self::COMPONENTNAME),
            get_string('feedurl_desc', self::COMPONENTNAME), '', PARAM_URL
        ));
    }

    /**
     * Supervisor = user whose id is stored in the profile field mapped to the "Supervisor" function.
     */
    public static function get_supervisor_for_user(int $userid) {
        global $DB;
        $shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        if ($shortname === '') {
            return (object)[];
        }
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
        $value = $fieldid ? $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]) : '';
        if (is_numeric($value) && ($user = $DB->get_record('user', ['id' => $value, 'deleted' => 0]))) {
            return $user;
        }
        return (object)[];
    }
}
```

Notes:

- The settings page renders the sections of **all** installed adapters. Prefix every key with your component
  and read them with `get_config('taskflowadapter_<name>', …)`.
- `get_supervisor_for_user()` must return a user record or an **empty object** (never null/false): callers use
  `$supervisor->id ?? …`. The base implementation reads the core setting `local_taskflow/supervisor_field`, which
  is only offered in the UI when *no* adapter is installed — override it as shown (ksw and tuines do).
- Settings core reads from your component: `excludestatus` (CSV/array of status ids; hides them from status
  selects and makes `assignment_status_facade::change_status()` a no-op for them), `necessaryuserprofilefields`
  (only through your own `necessary_customfields_exist()`). `usingprolongedstate` is read by core **only from
  component `taskflowadapter_tuines`** (D-15) — defining it in your adapter has no effect until that is fixed.
- If you declare `classes/form/internal_communication_form.php`, `settings.php` shows the "Internal communication
  settings" block (`allowinternalcommunication`, `internalcommunicationpreviewlength`).

---

## 5. Import class: `adapter::process_incoming_data()`

File `classes/adapter.php`:

```php
namespace taskflowadapter_<name>;

use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_interface;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\units\unit_relations;
use local_taskflow\plugininfo\taskflowadapter;
use stdClass;

class adapter extends external_api_base implements external_api_interface {

    public function process_incoming_data() {
        $relationupdates = [];
        $newmembers = [];

        if (!empty(get_object_vars($this->externaldata))) {
            external_api_base::$importing = true;           // silence core_user_created_updated during the import
            // 1. Units first, so $this->unitmapping[external id] = moodle id exists.
            foreach ($this->externaldata->units ?? [] as $record) {
                $unit = $this->translate_incoming_target_groups((array)$record);
                $created = $this->unitrepo::create_unit((object)[
                    'name' => $unit['name'], 'description' => $unit['description'] ?? '',
                    'unitid' => $unit['unitid'], 'parent' => $unit['parent'] ?? null,
                ]);
                if ($created instanceof unit_relations) {
                    $relationupdates[][] = ['child' => $created->get_childid(), 'parent' => $created->get_parentid()];
                    $this->unitmapping[$unit['unitid']] = $created->get_childid();
                } else {
                    $this->unitmapping[$unit['unitid']] = $created->get_id();
                }
            }
            // 2. Persons.
            foreach ($this->externaldata->persons ?? [] as $record) {
                $translated = $this->translate_incoming_data($record);
                $user = $this->userrepo->update_or_create($translated);   // core user
                if (!$user) {
                    continue;
                }
                $this->create_user_with_customfields($user, $translated, '');   // profile values in memory + cache
                // 3. Memberships (+ cohort_add_member() in cohort mode).
                $unitsfield = self::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_TARGETGROUP);
                foreach ((array)($translated[$unitsfield] ?? []) as $externalunitid) {
                    $moodleunitid = $this->unitmapping[$externalunitid] ?? null;
                    if ($moodleunitid && $this->unitmemberrepo->update_or_create($user, $moodleunitid)) {
                        $newmembers[$user->id][] = ['unit' => $moodleunitid];
                    }
                }
                // 4. Lifecycle: contract end / long leave → pause; lost units → droppedout; etc.
                if ($this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user) == 1) {
                    assignments_facade::set_all_assignments_inactive($user->id);
                }
            }
        }
        // 5. Supervisors (needs all users in the static cache).
        $shortname = self::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        foreach ($this->return_static_users() as $user) {
            $supervisorid = $user->profile[$shortname] ?? '';
            if ($supervisorid !== '') {
                (new supervisor((string)$supervisorid, $user->id))
                    ->set_supervisor_for_user((string)$supervisorid, $shortname, $user, $this->return_static_users());
            }
        }
        // 6. Persist profile fields once.
        $this->save_all_user_infos($this->return_static_users());
        // 7. Let core create the assignments.
        self::trigger_unit_relation_updated_events($relationupdates);
        self::trigger_unit_member_updated_events($newmembers);
        external_api_base::$importing = false;
    }

    /** Called by core on every Moodle user_created/user_updated event (if allowed). */
    public function necessary_customfields_exist(stdClass $user): bool {
        $config = get_config('taskflowadapter_<name>', 'necessaryuserprofilefields');
        foreach (array_filter(explode(',', (string)$config)) as $shortname) {
            if (empty($user->profile[$shortname] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** Return false if the adapter must never re-sync a single user from Moodle user events. */
    public static function is_allowed_to_react_on_user_events(): bool {
        return true;
    }
}
```

Contract details:

| Member | Required? | Called by | Notes |
|---|---|---|---|
| `process_incoming_data()` | yes (interface) | `external_api_repository::create($json)->process_incoming_data()` from `form\uploaduser`, your fetch task, and — with **empty** data — from `core_user_created_updated` after `set_users($user)`. Support both: when `$externaldata` is empty, process only the users in the static cache. | Set `external_api_base::$importing = true` while creating/updating users and reset it at the end; otherwise every `user_updated` re-enters your adapter (and, if you forget the reset, core stops reacting to user events for the rest of the process). |
| `necessary_customfields_exist(stdClass $user): bool` | yes if you react to user events | `core_user_created_updated::handle()` | `$user` has `->profile` loaded. Return `true` to sync. Config `necessaryuserprofilefields` is stored as a comma-separated string for multiselects. |
| `is_allowed_to_react_on_user_events(): bool` (static) | yes | `core_user_created_updated::handle()` via `method_exists`, and by your `load_settings()` | `false` = imports only through the feed (tuines). Core additionally skips the handler entirely when `external_api_option == 'tuines'` — that shortcut is hard-coded and does **not** apply to your adapter; use this method. |
| `set_users(stdClass $user)`, `return_value_for_functionname()` | inherited | core handlers | do not remove/override incompatibly |
| `get_supervisor_for_user()` | on the settings class | see section 4 | |

Data flow reminder (what core does after your events):

```
unit_relation_updated → eventhandlers\unit_relation_updated → preprocessor(all users of child unit × its rules)
unit_member_updated   → eventhandlers\unit_member_updated   → (skips suspended / long-leave users)
                                                             → preprocessor(this user × unit rules incl. recursive parent rules)
unit_updated          → eventhandlers\unit_updated → adhoc task unit_updated → preprocessor(unit users × unit rules)
unit_member_removed / unit_removed → unassignment (memberships removed, assignments droppedout)
```

Lifecycle conventions the in-tree adapters follow (documented for admins in
[Adapters](../user/adapters/README.md)): contract end in the past or long leave → `set_all_assignments_inactive()`
(**paused**), contract end additionally suspends the Moodle account; unit lost → `set_user_units_assignments_inactive()`
(**droppedout**); unit regained → `set_user_units_assignments_active()` (**assigned**, completed stays); person
missing from the feed (tuines) → suspend + `set_all_assignments_of_user_to_status(droppedout)`; leave ended →
`longleave_facade::longleave_deactivation()` (paused → assigned, counters reset, rules re-run).

---

## 6. Translator and function mapping

All mapping lives in the config of your component and is edited on the settings page:

| Setting key (`taskflowadapter_<name>/…`) | Type | Meaning |
|---|---|---|
| `translator_user_firstname`, `translator_user_lastname`, `translator_user_email` | text | JSON key of the core user field in a person record |
| `translator_user_<shortname>` (one per custom profile field) | text | JSON key whose value is written into profile field `<shortname>`; empty = not imported |
| `<shortname>` (one per custom profile field) | select | the **function** of the field: one of the `TRANSLATOR_USER_*` constants or `''` ("No function"). Each function should be mapped to exactly one field. |
| `translator_target_group_name`, `_description`, `_unitid`, (`_parent`) | text | JSON keys inside a unit record |

Reading the mapping in code:

```php
// Which profile field plays the supervisor role?
$shortname = external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
// Which JSON key feeds it?
$jsonkey   = external_api_base::return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
// Value of a function for a loaded user (profile_load_custom_fields() must have run):
$onleave   = $adapter->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user);
```

`translate_incoming_data($record)` returns `['firstname' => …, 'lastname' => …, 'email' => …, '<shortname>' => …]`.
Values with function `CONTRACTEND`/`CONTRACTSTART` are already unix timestamps (`strtotime()`), `LONG_LEAVE`
is `0`/`1`. Everything else is passed through as-is; arrays (e.g. a list of unit ids) are JSON-encoded when
persisted by `save_all_user_infos()`.

The reference configuration used by the test generator (`tests/generator/lib.php::set_config_values()`):

| Adapter | firstname / lastname / email | Profile fields → function (JSON key) |
|---|---|---|
| `tuines` | `firstName` / `lastName` / `eMailAddress` | `units` → TARGETGROUP (`targetGroup`), `organisation` → ORGUNIT (`orgUnit`), `supervisor` → SUPERVISOR (`directSupervisor`), `longleave` → LONG_LEAVE (`currentlyOnLongLeave`), `externalid` → EXTERNALID (`tissId`), `contractend`/`contractstart` (`contractEnd`/`contractStart`), `deputy` → DEPUTY; target groups `displayNameDE`/`descriptionDE`/`number`; plus `usingprolongedstate = 1`, `excludestatus = '3,7'` |
| `standard`, `ksw` | `Firstname` / `LastName` / `DefaultEmailAddress` | `orgunit` → ORGUNIT (`Organisation`, split on `\` into `Org1..N`), `externalid` → EXTERNALID (`userID`), `contractend` (`ExitDate`), `contractstart` (`EntryDate`), `supervisor_external` → SUPERVISOR_EXTERNAL (`Manager_Id`), `supervisor` → SUPERVISOR, `deputy` → DEPUTY |

---

## 7. String overrides via the string manager

Nearly all Taskflow UI text goes through

```php
\local_taskflow\taskflow_stringmanager::get_string(string $identifier, mixed $a = null, ?string $lang = null): string
```

which returns the string from component `taskflowadapter_<active>` **if it exists there**, else from
`local_taskflow`. Consequently your `lang/en/taskflowadapter_<name>.php` can override any core string
simply by defining the same key — no code needed. Strings in core that are fetched with plain
`get_string(..., 'local_taskflow')` (a minority, e.g. some form labels) cannot be overridden this way.

Required keys in your lang file:

| Key | Used for |
|---|---|
| `pluginname` | plugin name |
| `<name>` (your plugin name as key) | option label in the `external_api_option` select |
| `apisettings` | heading of your settings section |
| any own setting labels (`feedurl`, `feedurl_desc`, …) | fetched with `get_string(..., 'taskflowadapter_<name>')` |

Commonly overridden core keys (see tuines/ksw for examples): `supervisor` ("Supervisor Overview"),
`assignmentsavailablemy` / `assignmentsavailablesupervisor` (banners of the `assignmentsavailability` shortcode,
`{$a}` = URL), `training`, `edit`, `submitcomment`, `changestatus`, `changereason`, `denytext`, the chat digest
mail parts `notificationmessageheading`, `notificationmessagepreamble`, `notificationmessageintro`,
`notificationmessageoutro`, `notificationmessagepost`, and the status names `status<label>` (e.g. `statusassigned`).

Inside your own adapter code prefer `taskflow_stringmanager::get_string()` for shared vocabulary (so the
fallback chain applies) and `get_string($key, 'taskflowadapter_<name>')` for keys that exist only in your plugin.
When the adapter is **not** selected, `taskflow_stringmanager` will not look into your component — components
that ship always-on code (navbar callback, observers) must therefore use their own component explicitly for
their own keys (ksw `lib.php` does this for `quickaccess`).

---

## 8. Output, form and table override hooks

### 8.1 Assignments table — `\taskflowadapter_<name>\table\assignments_table`

Extend `\local_taskflow\table\assignments_table` (a `local_wunderbyte_table\wunderbyte_table`). Instantiated by
`output\assignmentsdashboard::set_table()` for **every** assignments table (my assignments, supervisor and admin
dashboards, shortcodes). Constructor `($uniqueid)`. Core decides the column set (`customize_columns()`:
`id, fullname, targets, rulename, supervisor, status, statussortkey, active, usermodified, usermodified_fullname,
timecreated, timemodified, actions, comment, testmoodleid, info, duedate, lastinternalcomment` + `custom_<field>`
from setting `assignment_fields`, reduced by the shortcode argument `columns`), so override `col_<column>($values)`
methods only. Typical overrides (tuines): `col_actions` (custom "Go to training"/"Edit" links; respect
`$this->is_downloading()`), `col_fullname` (external-system link), `col_targets`, `col_comment`. Keep
`$PAGE->url`/`returnurl` + `taskflow_multiblock` parameters in links so block_multiblock tabs re-open correctly.

### 8.2 Dashboards — `\taskflowadapter_<name>\output\supervisordashboard` and `admindashboard`

Plain classes implementing `renderable, templatable` with constructor `(int $userid = 0, array $arguments = [])`
and `export_for_template(renderer_base $output): array`. Core renders them with its own templates, so return
the context they expect:

| Class | Template (core) | Context keys |
|---|---|---|
| `supervisordashboard` | `local_taskflow/dashboards/supervisordashboard` | `approvals` (html, card "Pending Approvals"), `requests` (html, "Applications"), `chart` (html, "Overview"), `supervisorteam` (html, "Bookings of your Teams"), `supervisorassignments` (html, "Detailview") |
| `admindashboard` | `local_taskflow/dashboards/admindashboard` | `assignments` (html, "Detailview"), `approvals` (html, "Applications"), `chart` (html, "Overview") |

Build the fragments with the core shortcode callbacks (`\local_taskflow\shortcodes::supervisorassignments('', [
'columns' => 'fullname,targets,duedate,statussortkey,actions', 'deputyselect' => 1], '', $env, $next)`,
`::requests('', ['noheader' => 1], …)`, `::assignmentsdashboard('', ['chart' => 1], …)`) and, if `mod_booking` is
present, `\mod_booking\shortcodes::listtoapprove()` / `supervisorteam()`. The supervisor tab is only shown to
users with `local/taskflow:issupervisor`, the admin tab to users with `local/taskflow:editassignment` or listed in
`bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers`. Shortcode arguments are documented in
[Shortcodes](../user/shortcodes/README.md).

### 8.3 Edit-assignment page — `\taskflowadapter_<name>\output\editassignment_template_data_admin` / `_supervisor`

Implement `\local_taskflow\output\editassignment_template_data_interface` (`__construct(array $data)`,
`export_for_template(renderer_base $output)`). `$data['id']` is the assignment id; throw
`moodle_exception('invalidassignmentid', 'local_taskflow')` if missing. The factory picks `_supervisor` when the
viewer is the assignee's supervisor and lacks `local/taskflow:editassignment`, otherwise `_admin`; if your class
is missing both fall back to `taskflowadapter_standard\output\editassignment_template_data` (which renders
`taskflowadapter_standard\form\editassignment`). Core renders `local_taskflow/editassignment` with this context:

| Key | Type | Meaning |
|---|---|---|
| `id` | int | assignment id |
| `returnurl` | string | back link (read `optional_param('returnurl', …)`, append `#taskflow_multiblock` when present) |
| `assignmentdata` | `[{label, value}]` | facts card "Assignment data" |
| `editassignmentform` | html | rendered dynamic form (`$form->render()` after `set_data_for_dynamic_submission()`) |
| `adapter` | string | fully qualified class name of that form, passed to AMD `local_taskflow/editassignmentform` for AJAX submit (escape backslashes as `\\`) |
| `hascommentform`, `commentform` | bool, html | optional comment form |
| `hasinternalcommunication`, `internalcommunicationform` | bool, html | optional chat form (only when `allowinternalcommunication`) |
| `hashistory`, `historylist` | bool, html | history table (`(new \local_taskflow\output\history($assignmentid))` rendered with `render_history()`) |

Limitation: the template's JS initialisation for the comment form and the chat form hard-codes
`taskflowadapter_tuines\form\comment_form` / `internal_communication_form` (D-38). If you ship your own classes
with those names in your namespace they will not be used by the AJAX handlers; either reuse the tuines classes
(if installed) or override the template as well.

Your dynamic forms (`core_form\dynamic_form`): follow `taskflowadapter_tuines\form\editassignment_admin` —
`process_dynamic_submission()` should log via `\local_taskflow\local\history\history::log($id, $userid,
history::TYPE_MANUAL_CHANGE, ['action' => 'updated', 'data' => $data], $USER->id, $comment)` and persist through
`\local_taskflow\local\assignments\assignment::get_instance($id)->add_or_update_assignment((array)$data,
history::TYPE_MANUAL_CHANGE, true)` (the `true` marks a manual update so `keepchanges` does not block the due
date). Set `keepchanges = 1` in the data if the change must survive imports. **Implement a real
`check_access_for_dynamic_submission()`** (capability or supervisor check) — the in-tree forms only call
`require_login()` (D-01).

### 8.4 Single-assignment page template

`renderer::render_singleassignment()` uses `taskflowadapter_tuines/singleassignment` when the active adapter is
`tuines` and `local_taskflow/singleassignment` otherwise — there is no generic per-adapter hook (D-38). To change
this page for your adapter you currently have to patch the renderer or override the core template via a theme.
The context is produced by `\local_taskflow\output\singleassignment` (keys listed in the tuines template).

### 8.5 Other hooks

| Hook | How |
|---|---|
| Navbar entries | `lib.php` → `function taskflowadapter_<name>_render_navbar_output(\renderer_base $renderer): string` (ksw `naventry.mustache` as example). Always active once installed. |
| Shortcodes | `db/shortcodes.php` + `classes/shortcodes.php` (ksw `bookingoptiondescription`). |
| Scheduled feed import | `db/tasks.php` + `classes/task/<fetch>.php`; call `external_api_repository::create($json)->process_incoming_data()`; fire an own event on failure (tuines `event\dwh_fetch_failed`). Note `form\importdwh` (the "Trigger DWH import" button on `view.php`) is hard-wired to `taskflowadapter_tuines\task\fetch_dwh_data`. |
| Own caches | `db/caches.php` (tuines `commenthistorylist`, invalidated by core event `changesinhistorylist`). |
| Internal-communication settings | ship `classes/form/internal_communication_form.php` (see 4). |
| Reacting to profile-field deletion | observe `\core\event\user_info_field_deleted` and `unset_config($shortname, 'taskflowadapter_<name>')` **and** `unset_config('translator_user_' . $shortname, …)` (tuines forgets the second). |

---

## 9. Events to fire and events to observe

### 9.1 Fire these from your adapter

| Event (`\local_taskflow\event\…`) | Payload | Core reaction |
|---|---|---|
| `unit_relation_updated` | `objectid` = child unit id, `other = ['parent' => int, 'child' => int]` (helper `trigger_unit_relation_updated_events([[['child' => …, 'parent' => …]]])`) | re-evaluates all users of the child unit against its rules |
| `unit_member_updated` | `objectid`/`userid` = user id, `other = ['unitid' => moodle unit id, 'unitmemberid' => user id]` (helper `trigger_unit_member_updated_events([userid => [['unit' => unitid], …]])`) | creates/updates assignments of that user for the unit's rules (skipped for suspended / long-leave users) |
| `unit_updated` | `objectid` = unit id, `other = ['unitid' => unit id]` | adhoc `task\unit_updated` → re-evaluates the whole unit (tuines fires one per imported unit) |
| `unit_member_removed` | `other = ['unitid' => unit id, 'unitmemberid' => [userid]]` (array!) | unassignment: membership removed, assignments droppedout |
| `unit_removed` | `other = ['unitid' => unit id]` | unassignment for all members |
| `upload_error` | `objectid` 400, `other = ['message' => string]` — via the `external_api_error_logger` helpers | logged only (visible in the log report) |

Fire events **after** `save_all_user_infos()` so the handlers see the persisted profile values (supervisor,
long leave). Alternatively rely on core: in cohort mode `cohort_add_member()` triggers
`observer::cohort_member_added`, which writes the membership row and fires `unit_member_updated` for you when
`local_taskflow/cohortenrollment` is on.

### 9.2 Observe these (optional)

`\local_taskflow\event\assignment_created`, `assignment_completed` (standard adapter issues a certificate),
`assignment_status_changed` (currently never fired, D-19), `assignment_seen`, `new_assignment_message`,
`request_created`, `request_treated`, `rule_created_updated`; core `\core\event\user_updated` (ksw cancels future
bookings of suspended users), `\core\event\user_info_field_deleted` (tuines cleans its mapping config).

---

## 10. Testing with the generator and fixtures

The `local_taskflow` PHPUnit generator (`tests/generator/lib.php`, `local_taskflow_generator`) is the shared test
kit; the adapter suites (`taskflowadapter/*/tests`) are the behavioural specification of each adapter and the best
templates for your own.

| Generator method | Purpose |
|---|---|
| `create_custom_profile_fields(array $shortnames): array` | creates missing `text` custom profile fields, returns `[shortname => fieldid]` |
| `set_config_values(string $type = 'standard', array $override = [], array $overridesubplugin = [])` | sets `local_taskflow` core settings (`organisational_unit_option = cohort`, `supervisor_field = supervisor`, `external_api_option = $type`, `supervisorrole`) and the adapter mapping for `tuines` / `ksw` / `standard` (section 6). For your own adapter pass `$type = '<name>'` — the `default` branch writes the standard mapping into `taskflowadapter_<name>`; override keys via `$overridesubplugin` or call `set_config()` yourself. |
| `create_supervisorrole(): int` | idempotent role `supervisor` with `local/taskflow:issupervisor` |
| `create_rule(array $options = []): int` | bare rule row (`rulejson = '{}'`) |
| `create_user_assignment(int $userid, int $ruleid)` | assignment via `add_or_update_assignment()` |
| `create_competencies(advanced_testcase $tc, int $number = 1): array` | booking `usecompetencies`, framework + competencies |
| `create_booking_options($tc, $courseid, $user, $number = 1, $instancedata = [], $optiondata = [])` | booking instance + options |
| `runtaskswithintime($cronlock, $lock, $mocktime)` | executes every `task_adhoc` row with `nextruntime <= $mocktime` (one pass) |
| `teardown()` | resets **all** static singletons (`external_api_base`, `rules`, `unit_rules`, `unit_relations`, `unit`, `cohort`, `standard_assignment`, `unit_member`, target types, `assignment`, `singleton_service`, `mod_booking\singleton_service`) — call it in `tearDown()` and between imports that mutate the same users in one test |

Skeleton of an adapter use-case test (pattern of `taskflowadapter/ksw/tests/usecases/competencies/betty_best_test.php`):

```php
namespace taskflowadapter_<name>;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\event\rule_created_updated;
use tool_mocktesttime\time_mock;

final class import_creates_assignment_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        $this->preventResetByRollback();                 // adhoc tasks commit
        $gen = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $gen->create_custom_profile_fields(['supervisor', 'units', 'externalid', 'longleave', 'contractend', 'deputy']);
        $gen->set_config_values('<name>');               // or 'standard' + set_config() for your keys
    }

    protected function tearDown(): void {
        self::getDataGenerator()->get_plugin_generator('local_taskflow')->teardown();
        parent::tearDown();
    }

    public function test_import_and_rule(): void {
        global $DB;
        // 1. Import the fixture.
        $json = file_get_contents(__DIR__ . '/external_json/persons.json');
        external_api_repository::create($json)->process_incoming_data();
        $this->runAdhocTasks();
        $cohort = $DB->get_record('cohort', ['idnumber' => '101']);

        // 2. Insert a rule and activate it (see RULE_JSON_FORMAT.md §10).
        $ruleid = $DB->insert_record('local_taskflow_rules', (object)[
            'unitid' => $cohort->id, 'rulename' => 'Test', 'isactive' => 1, 'userid' => 0,
            'rulejson' => json_encode(['rulejson' => ['rule' => [
                'name' => 'Test', 'description' => '', 'type' => 'taskflow', 'enabled' => 1,
                'duedatetype' => 'duration', 'duration' => 10 * DAYSECS, 'extensionperiod' => 5 * DAYSECS,
                'cyclicvalidation' => '0', 'activationdelay' => 0, 'recursive' => 1, 'inheritance' => 0,
                'filter' => [],
                'actions' => [['targets' => [[
                    'targettype' => 'moodlecourse', 'targetid' => $this->getDataGenerator()->create_course()->id,
                    'targetname' => 'c', 'sortorder' => 2, 'actiontype' => 'enroll', 'completebeforenext' => 0,
                ]], 'messages' => []]],
            ]]]),
        ]);
        rule_created_updated::create(['objectid' => $ruleid, 'context' => \context_system::instance(),
            'other' => ['ruledata' => $DB->get_record('local_taskflow_rules', ['id' => $ruleid])]])->trigger();
        $this->runAdhocTasks();

        // 3. Assert.
        $assignments = $DB->get_records('local_taskflow_assignment', ['ruleid' => $ruleid]);
        $this->assertCount(2, $assignments);
        foreach ($assignments as $a) {
            $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $a->status);
        }

        // 4. Travel in time and let the due-date check run (locks are mocked, as in the in-tree suites).
        time_mock::set_mock_time(time() + 11 * DAYSECS);
        $gen = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $cronlock = $this->createMock(\core\lock\lock::class);
        $lock = $this->createMock(\core\lock\lock::class);
        $gen->runtaskswithintime($cronlock, $lock, time());
        $this->assertEquals(assignment_status_facade::get_status_identifier('overdue'),
            $DB->get_field('local_taskflow_assignment', 'status', ['id' => array_key_first($assignments)]));
    }
}
```

Conventions and gotchas from the existing suites:

- Fixtures are JSON files next to the tests (`tests/usecases/external_json/*.json`); personas Berta Boss, Betty
  Best, Chris Change, Garry Gone, Lucy Lazy, Sara Sick, … carry scenario meaning (see inventories of the in-tree
  suites). Message templates are loaded from `tests/mock/messages/*.json` rows into `local_taskflow_messages`.
- Rule activation and completion processing happen in adhoc tasks: always `$this->runAdhocTasks()` (or
  `runtaskswithintime()` after moving `time_mock`). `runtaskswithintime()` is a single pass — run it twice when a
  status must traverse two steps (assigned → prolonged → overdue).
- Statuses are asserted via `assignment_status_facade::get_status_identifier('<label>')`, not literals.
- The core `user_created/updated` handler is disabled under PHPUnit unless `set_config('enableeventhandlersinphpunit', 1, 'local_taskflow')`.
- Between two imports of *different* data for the same users call `generator->teardown()` (or
  `external_api_base::destroy_instance(); external_api_base::$importing = false;`), otherwise the static user
  cache serves stale profile values.
- Tests touching `mod_booking` singletons may need `@runInSeparateProcess`; several core tests skip themselves when
  booking classes are already loaded.
- `excludestatus` changes what your assertions can expect (tuines excludes `3,7`, so "partially completed"
  scenarios assert `assigned`).
- CI: the in-tree adapters use the Wunderbyte `catalyst-moodle-workflows` reusable workflow and install
  `local_wunderbyte_table`, `local_multistepform`, `filter_shortcodes`, `tool_mocktesttime`, `mod_booking` and a
  specific `local_taskflow` branch (`.github/workflows/moodle-plugin-ci.yml`).

---

## 11. Checklist and known limitations

Checklist for a new adapter:

- [ ] `version.php`, lang keys `pluginname`, `<name>`, `apisettings`
- [ ] `classes/taskflowadapter_<name>.php` with `load_settings()` (mapping settings + `excludestatus`) and `get_supervisor_for_user()`
- [ ] `classes/adapter.php` with `process_incoming_data()` handling both a full feed and the empty-data/`set_users()` path, `$importing` set and reset, `necessary_customfields_exist()`, `is_allowed_to_react_on_user_events()`
- [ ] units → `organisational_unit_factory::create_unit()`, memberships → `unitmemberrepo->update_or_create()` (+ `cohort_add_member()` in cohort mode), supervisors → `supervisor::set_supervisor_for_user()`, profile fields → `save_all_user_infos()`
- [ ] events fired after persisting (`unit_member_updated` at least) so assignments are created
- [ ] lifecycle: paused on contract end / long leave, droppedout on lost units, re-activation on return
- [ ] optional hooks guarded by `external_api_option === '<name>'` where they must not run for other adapters
- [ ] dynamic forms with real access checks
- [ ] PHPUnit use cases with fixtures; `teardown()` in `tearDown()`

Known limitations of the hook layer (tracked in the [technical debt list](ARCHITECTURE_OVERVIEW.md#16-known-technical-debt)):
`map_value()` not overridable (D-25); `->` paths not descended for person records (D-25); `usingprolongedstate`
read only from `taskflowadapter_tuines` (D-15); single-assignment template, comment/chat form JS init and the
DWH import button hard-wired to tuines (D-38); observers/shortcodes/navbar of all installed adapters always
active (D-39); `supervisor_field` fallback only configurable without adapters (D-29).
