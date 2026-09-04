[Back to documentation index](../README.md)

# local_taskflow — Architecture Overview

This is the developer map of `local_taskflow` (release 1.3.0, version 2026090100): how an HR record becomes a
Moodle user, how a rule becomes an assignment, how completion is detected, how messages and requests move
through the system, which tables and caches hold the state, where the plugin can be extended, and which
parts of the code base are known technical debt.

Companion developer guides:

- [RULE_JSON_FORMAT.md](RULE_JSON_FORMAT.md) — the `rulejson` schema, filter/target/message JSON.
- [ADAPTER_API.md](ADAPTER_API.md) — building a `taskflowadapter_*` subplugin.

User-facing behaviour (statuses, due dates, dashboards, settings) is described in the
[user documentation](../user/README.md); this page links there rather than repeating it.

---

## Table of Contents

1. [Big picture](#1-big-picture)
2. [Directory layout](#2-directory-layout)
3. [Import and organisational model](#3-import-and-organisational-model)
4. [Assignment creation flow](#4-assignment-creation-flow)
5. [Actions and targets](#5-actions-and-targets)
6. [Completion flow](#6-completion-flow)
7. [Status model, due dates, counters](#7-status-model-due-dates-counters)
8. [Message pipeline](#8-message-pipeline)
9. [Request flow](#9-request-flow)
10. [Internal chat](#10-internal-chat)
11. [Adapter layer](#11-adapter-layer)
12. [Database table map](#12-database-table-map)
13. [Caches](#13-caches)
14. [Tasks, events, observers](#14-tasks-events-observers)
15. [Extension points](#15-extension-points)
16. [Known technical debt](#16-known-technical-debt)

---

## 1. Big picture

```
 external HR feed / JSON upload / Moodle user events
                 │
                 ▼
   taskflowadapter_<x>\adapter::process_incoming_data()       (11)
   users ▸ units/cohorts ▸ unit members ▸ supervisor field
                 │  fires \local_taskflow\event\unit_* events
                 ▼
   observer::call_event_handler  →  local\eventhandlers\<event>   (4)
                 │  queues adhoc tasks (update_rule, unit_updated, …) or calls directly
                 ▼
   assignment_preprocessor  ─►  assignment_controller  ─►  assignments_controller
   (which users × which rules)   (filter gate per pair)    (build/update one assignment row)
                 │                                                   │
                 │                            action_operator → enroll targets, schedule messages,
                 │                            queue check_assignment_status at due date
                 ▼
   local_taskflow_assignment  ◄──  completion_operator  ◄──  core completion / booking / competency events (6)
                 │
                 ├── assignment_status\types\*  (status machine, counters)                     (7)
                 ├── messages\*  → adhoc send_taskflow_message → mail + notification            (8)
                 ├── requests\*  → request_created / request_treated events                     (9)
                 └── internal_messages → int_com + last_seen + daily digest                     (10)
```

Three ideas carry the whole design:

1. **Everything is event-driven.** No cron job scans users. Imports, cohort changes, rule edits and Moodle
   user events fire `\local_taskflow\event\*` events; observers dispatch to event handlers that queue adhoc
   tasks; the tasks end in `assignment_preprocessor::process_assignemnts()` (sic). Only two scheduled tasks
   exist (`reschedule_rules` for time-relative filters, `notification_internal_messages` for the chat digest)
   plus the adapter's own fetch task.
2. **The rule is JSON, the assignment is a snapshot.** `local_taskflow_rules.rulejson` holds the whole rule
   (filters, targets, messages, requests, due-date settings). When a rule is applied to a user the targets and
   message ids are copied into `local_taskflow_assignment.targets` / `.messages`, and per-target completion is
   tracked inside that JSON copy.
3. **The adapter is the customer.** Everything customer-specific (feed format, field mapping, dashboards,
   edit forms, wording) lives in a `taskflowadapter_*` subplugin selected by the setting
   `local_taskflow/external_api_option`. Core resolves adapter classes purely by naming convention.

---

## 2. Directory layout

```
local/taskflow/
├── index.php, view.php, assignment.php, editassignment.php, editrule.php, mycertificates.php
├── settings.php                     admin settings + adapter load_settings() injection
├── db/                              install.xml, access.php, events.php, tasks.php, services.php,
│                                    messages.php, caches.php, shortcodes.php, tag.php, subplugins.json
├── amd/src/*.js                     dashboard, dynamic forms, block_multiblock tab glue
├── templates/                       dashboard, singleassignment, editassignment, history, dashboards/*
├── message_form/                    message template editor pages
├── classes/
│   ├── observer.php                 all db/events.php callbacks
│   ├── shortcodes.php, shortcodes_handler.php
│   ├── taskflow_stringmanager.php   adapter-first get_string()
│   ├── singleton_service.php        per-request user cache
│   ├── plugininfo/taskflowadapter.php   subplugin type + TRANSLATOR_* constants + settings helpers
│   ├── event/                       14 event classes
│   ├── task/                        adhoc + scheduled tasks
│   ├── external/                    3 AJAX web services
│   ├── form/                        rule multistep forms (rules/, filters/, targets/, messages/, requests/),
│   │                                request forms, upload/import forms
│   ├── multistepform/editrulesmanager.php   persists the rule
│   ├── output/                      renderables (dashboard, assignmentsdashboard, singleassignment, …)
│   ├── table/                       wunderbyte_table subclasses
│   └── local/
│       ├── assignment_process/      assignment_preprocessor, assignment_controller, assignments/,
│       │                            filters/, booking_migration, longleave_facade
│       ├── unassignment_process/    unassignment_controller
│       ├── assignments/             assignment entity, standard_assignment repo, assignments_facade,
│       │                            assignment_query_builder, assignment_seen, status/, activity_status/
│       ├── assignment_status/       facade + types/<status>.php
│       ├── assignment_operators/    action_operator, filter_operator, assignment_operator
│       ├── actions/                 actions_factory, types/enroll|unenroll, targets/
│       ├── completion_process/      completion_operator, types/, scheduling_*
│       ├── rules/                   rules, unit_rules
│       ├── filters/                 filter_factory, types/user_profile_field
│       ├── operators/               string_compare_operators
│       ├── eventhandlers/           one class per \local_taskflow\event
│       ├── external_adapter/        external_api_interface|base|repository|error_logger
│       ├── personas/                moodle_users/, unit_members/
│       ├── units/                   organisational_unit(s)_factory, organisational_units/unit|cohort,
│       │                            unit_relations, unit_hierarchy
│       ├── messages/                message_base, types/, placeholders/, sending_condition/,
│       │                            notifiaction_message/ (sic), message_recipient, message_sending_time
│       ├── messages_form/           template editor form + entities
│       ├── internal_messages/       chat
│       ├── requests/ + requests.php request types, receivers
│       ├── competencies/            assignment_competency (evidence)
│       ├── supervisor/, deputy/
│       ├── history/                 history + types/
│       ├── changemanager/, dashboardcache/, adhoc_task_process/
│       └── users_profile/           (no-op, see debt list)
├── taskflowadapter/{standard,ksw,tuines}/   subplugins (ksw and tuines are separate git repos)
└── tests/                           PHPUnit + generator + fixtures
```

Dependencies (`version.php`): `local_multistepform`, `local_wunderbyte_table`, `mod_booking`. `tool_certificate`
is used but not declared (see debt list).

---

## 3. Import and organisational model

**Units.** Two backends, chosen by `local_taskflow/organisational_unit_option`:

| Value | Unit entity | Membership | Hierarchy |
|---|---|---|---|
| `unit` | `local_taskflow_units` (`local\units\organisational_units\unit`) | `local_taskflow_unit_members` | `local_taskflow_unit_rel` |
| `cohort` (used by all in-tree adapters) | core `cohort` (`…\organisational_units\cohort`), matched by `idnumber` | `cohort_members` **and** `local_taskflow_unit_members` (mirrored by `observer::cohort_member_added` when `cohortenrollment` is on, or written directly by adapters) | `local_taskflow_unit_rel` |

`organisational_unit_factory::instance($id)` / `create_unit($data)` dispatch on the setting. `create_unit()`
returns a `unit_relations` object when a *new* parent relation was created, otherwise the unit — callers must
check `instanceof unit_relations`. `unit_hierarchy` builds `[unitid => [depth, pathtoou]]` from active
relations (MUC `unit_hierarchy`) and is what rule inheritance/recursion walks.

**Users.** `personas\moodle_users\moodle_user_factory::update_or_create($translated)` finds a user by external
id → username → email and creates one otherwise (`defaultauth`, random password, generated username).
Custom profile values are written by the adapter through `external_api_base::save_all_user_infos()`
(`profile_save_custom_fields`); `users_profile\*` is a no-op remnant.

**Supervisor and deputy** are *profile fields*, never unit relations. The field shortnames are resolved
through the adapter's function mapping (`external_api_base::return_shortname_for_functionname(
taskflowadapter::TRANSLATOR_USER_SUPERVISOR|_DEPUTY)`). The supervisor field holds one Moodle user id; the
deputy field a comma-separated list. `supervisor::set_supervisor_for_user()` also assigns the role from
`local_taskflow/supervisorrole` in system context; the adhoc `task\check_supervisor` revokes it when nobody
references the person any more. Scope queries (`assignment::return_supervisor_assignments_sql()`,
`supervisor::get_visible_subordinate_ids()`) use `FIND_IN_SET` on MySQL/MariaDB and `string_to_array` on
Postgres.

See [Units and users](../user/units_and_users/README.md) for the admin view.

---

## 4. Assignment creation flow

### 4.1 Triggers

| Trigger | Observer / handler | Queued task | Preprocessor selectors |
|---|---|---|---|
| Rule saved (`editrulesmanager::persist()` or `rules::toggle_isactive()`) → `rule_created_updated` | `eventhandlers\rule_created_updated` | adhoc `task\update_rule` | `set_affected_users()` (unit, or all child units when `rule.inheritance`), `set_this_rules()` |
| Unit / cohort member added → `unit_member_updated` | `eventhandlers\unit_member_updated` (skipped if user suspended or on long leave) | — (direct) | `set_this_user()`, `set_all_inheritance_unit_rules()` |
| Unit relation created → `unit_relation_updated` | `eventhandlers\unit_relation_updated` | — | `set_all_affected_users()`, `set_all_affected_rules()` |
| Unit renamed / re-imported → `unit_updated` | `eventhandlers\unit_updated` | adhoc `task\unit_updated` | `set_this_unit()`, `set_all_affected_users()`, `set_all_affected_rules()` |
| `\core\event\user_created` / `user_updated` | `observer::core_user_created_updated` (not while `external_api_base::$importing`, not for adapter `tuines`, gated in PHPUnit by `enableeventhandlersinphpunit`) | — | adapter re-sync of that user, then `set_this_user()`, `set_all_user_affected_rules()` |
| `\mod_booking\event\bookingoption_uncompleted` | `observer::recalculate_existing_assignments` | — | `set_this_user()`, `set_all_user_affected_rules()` |
| Planned assignment due | adhoc `task\open_planned_assignment` → `assignments_facade::open_planned_assignment()` | adhoc `task\update_assignment` | `set_this_user()`, `set_this_rules()` |
| Competency evidence approved | `competencies\assignment_competency::set_competency()` runs `task\update_assignment::execute()` **synchronously** | — | `set_this_user()`, `set_this_rules()` |
| `assignment.php?action=checkstatus` | page code | — | `set_this_user()`, `set_all_inheritance_unit_rules()` |
| Long leave ends (tuines adapter) | `assignment_process\longleave_facade::longleave_deactivation()` | — | `set_this_user()`, `set_all_user_affected_rules()` |
| Time-relative filter (`nowminusdays`) | scheduled `task\reschedule_rules` 02:00 re-fires `rule_created_updated` for every rule containing that operator | adhoc `task\update_rule` | as for rule saved |

`observer::call_event_handler()` instantiates every class in `local\eventhandlers`, calls `handle()` on the one
whose `$eventname` equals the event class, then purges cache event `changesinassignmentslist`.

### 4.2 The pipeline

```
assignment_preprocessor($data)                      classes/local/assignment_process/assignment_preprocessor.php
  ├─ selectors fill  allaffectedusers[]  and  allaffectedrules[]  (rules|unit_rules objects or arrays of them)
  └─ process_assignemnts()
       └─ assignment_controller::process_assignments($changemanagement)
            for every (user, rule):
            ├─ gate (only when $changemanagement given, i.e. rule save):
            │     check_recursive_assignment(): rule.recursive == '1'  OR  user has no assignment yet
            ├─ filters_controller::check_if_user_passes_filter()
            │     → filter_operator::is_rule_active_for_user(): rule.isactive == 1 AND every
            │       rule->filter[] entry validates via local\filters\filter_factory (missing class = pass)
            ├─ PASS ─┬─ booking_migration branch (cyclic rule, no assignment yet, all targets are
            │        │   competencies already completed through booking options): create the assignment
            │        │   with historic dates, log old completion, schedule reset_cyclic_assignment
            │        └─ default: assignments_controller::construct_and_process_assignment()
            └─ FAIL ── assignments_controller::inactivate_existing_assignment()  → status droppedout
```

`assignments_controller::construct_and_process_assignment($userid, $rule, $migrationdata = [])`
(`classes/local/assignment_process/assignments/assignments_controller.php`):

1. Reads `rulejson->rulejson->rule->actions[]`; takes `targets` and `messages` of the **last** action.
2. Loads the newest existing assignment for (userid, ruleid) — `standard_assignment::get_assignment_by_userid_ruleid()`.
3. `set_due_date()`: `duedatetype = fixeddate` → `fixeddate`; `duration` → `assigneddate + duration` (new: `now + duration`); else 0.
   Existing assignments keep their due date unless the rule is `recursive` and the assignment is not `prolonged`.
4. New or `planned` assignments get `assignment_status_facade::set_initial_status()`: `planned` (-1) when
   `activationdelay > 0`, else `assigned` (0).
5. Unless `keepchanges` is set: `completion_operator::get_assignment_status()` recomputes the status from real
   target completion and writes per-target `completionstatus`; `active` is derived from the status class.
6. `assignments_facade::update_or_create_assignment()` → `standard_assignment::update_or_create_assignment()` →
   `assignment::add_or_update_assignment()` (the single write path, see 4.3).
7. `planned` → queue `task\open_planned_assignment` at `now + activationdelay`. Otherwise
   `action_operator::check_and_trigger_actions($rule)`: enrol/book targets, schedule `standard`-class
   messages, queue `task\check_assignment_status` at the due date.

### 4.3 The write path — `assignment::add_or_update_assignment()`

`classes/local/assignments/assignment.php` is the entity (singleton per id, MUC `assignments`). Every write
goes through `add_or_update_assignment(array $data, string $historytype, bool $manualupdate)`:

- **Insert:** defaults `status 0, active 1, counters 0`; fires `\local_taskflow\event\assignment_created`;
  if a due date exists queues `check_assignment_status` and runs `assignment_status_facade::execute()`.
- **Update:** if `keepchanges` and not manual → `duedate`/`active` are dropped from the data. Writes only when
  status, duedate, active, messages, targets or keepchanges changed (or a `comment` is present). A status
  change runs `assignment_status_facade::execute()` (history row `status_changed`, `onevent` message
  scheduling) and `change_status()` (the status type's own side effects). Re-queues `check_assignment_status`.
- Afterwards: MUC delete + reload, `purge_by_event('changesinassignmentslist')`.

### 4.4 Unassignment

`assignment_preprocessor::process_unassignemnts()` → `unassignment_controller::process_unassignments()`
removes unit memberships and calls `assignments_facade::delete_assignments($ruleids, $userid)`, which does
**not** delete: assignments become `droppedout` (completed ones only get `active = 0`) and their scheduled
messages are removed. Triggered by `unit_member_removed`, `unit_removed`, `\core\event\user_deleted`.
Rule deletion (`rules_table::action_deleterule` → adhoc `task\removed_rule` → `process_ruledeletion()`)
really deletes the rule row and its assignment rows.

Adapter-driven variants on `assignments_facade`: `set_user_units_assignments_inactive()` (unit lost →
droppedout), `set_user_units_assignments_active()` (unit regained → assigned, completed stays),
`set_all_assignments_inactive()` (contract end / long leave → paused), `set_all_paused_assignments_active()`,
`set_all_assignments_of_user_to_status()` (tuines missing persons → droppedout).

---

## 5. Actions and targets

Targets are copied from the rule into `assignment.targets` as JSON objects `{targettype, targetid, targetname,
actiontype, completebeforenext, sortorder, completionstatus}` (schema in
[RULE_JSON_FORMAT.md](RULE_JSON_FORMAT.md#4-target-json)).

`actions_factory::instance($target, $userid)` instantiates `actions\types\{actiontype}` — only `enroll`
exists. `action_operator::check_and_trigger_actions($rule)` walks the targets in order, calls `is_active()`
then `execute()`, and stops after a target with `completebeforenext = 1` whose `completionstatus` is still 0.

| targettype | `is_active()` | `execute()` |
|---|---|---|
| `moodlecourse` (also `course`) | course exists and (already enrolled or an enabled **manual** enrol instance exists) | `enrol_get_plugin('manual')->enrol_user()` with the student role |
| `bookingoption` | `mod_booking` present, `bo_info::is_available()` yields `BOOKITBUTTON` or `CONFIRMBOOKIT` | `booking_bookit::bookit('option', optionid, userid)` |
| `competency` | `core_competency\competency` record exists | no-op — competencies are reached via booking options or evidence |

`actions\types\unenroll` (used only by `assignments_facade::reopen_assignment()` for cyclic resets) reverses
this: manual unenrol + deletes `course_completions`/`course_modules_completion`; `booking_option::user_delete_response()`;
deletes `competency_usercomp` and the user's `competency_userevidencecomp` links.

Target *display* (names, links) is handled by `actions\targets\targets_factory` → `targets\types\{type}`.

---

## 6. Completion flow

```
\core\event\course_completed / course_completion_updated (→ incomplete) / course_reset_ended
\core\event\competency_user_competency_rated
\mod_booking\event\bookingoption_booked
        │  observer::course_completed | competency_completed | bookingoption_booked | course_reset
        ▼
completion_process\completion_operator($targetid, $userid, $targettype)->handle_completion_process($eventdata)
  1. types\{targettype}::get_all_active_assignemnts()  — active=1 assignments of the user whose targets contain
     the target; a bookingoption event also matches competency targets listed in the option's `competencies` CSV
  2. bookingoption_booked → status enrolled (3) if status < 3
  3. else get_assignment_status(): per target types\{type}::is_completed()
        moodlecourse   completion_info::is_course_complete()
        bookingoption  booking_answers::is_activity_completed(); cyclic rule → last completion within cyclicduration
        competency     any booking option carrying the competency with a completion (cyclic: within cyclicduration)
                       OR local_taskflow_assgin_comp row status='approved' with validationondate empty/future
  4. set_stauts() (sic) → new status (see 7), assignments_facade::update_or_create_assignment(),
     history row for the event's targettype, then action_operator::check_and_trigger_targets($assignment)
     to enrol the next target of a completebeforenext chain
```

All targets met → `completed` (15) and `\local_taskflow\event\assignment_completed`, whose handler
schedules `onevent` messages and — for cyclic rules — adhoc `task\reset_cyclic_assignment` at
`now + cyclicduration` (`scheduling_cyclic_adhoc`). The reset task reopens the assignment
(`assignments_facade::reopen_assignment()`: unenrol all targets, status `assigned`, counters 0, sent
messages removed).

---

## 7. Status model, due dates, counters

Statuses are singleton classes in `classes/local/assignment_status/types/` discovered by `glob()`; the facade
`assignment_status_facade` dispatches by id or label. Each type defines `identifier` (int), `label`,
`active` (0/1 written into `assignment.active`), `userchoice` (offered in manual status forms) and may override
`change_status(&$assignment)` for side effects (reset counters, null dates, remove scheduled messages,
increment counters, queue a new due-date check).

| id | label | active | id | label | active |
|---|---|---|---|---|---|
| -2 | notrelevant | 0 | 7 | partially_completed | 1 |
| -1 | planned | 0 | 10 | overdue | 1 |
| 0 | assigned | 1 | 11 | reprimand | 1 |
| 3 | enrolled | 1 | 12 | sanction | 1 |
| 4 | paused | 0 | 15 | completed | 1 |
| 5 | prolonged | 1 | 16 | droppedout | 0 |

Numeric ordering is semantic: `check_and_update_overdue_assignment()` applies to `planned < status < completed`;
`where_toclarify_assignment` = `overdue <= status < completed`; booking only upgrades `status < enrolled`.

`completion_operator::set_stauts()` decision order: keep `overdue` (unless all targets met and
`allowoverduecompletion`), keep `paused`/`notrelevant`; all targets met → `completed`; due date passed →
`overdue`; DB status `prolonged` → keep; `prolongedcounter > 0` → `prolonged`; some targets → `partially_completed`;
none → `assigned` (both unless excluded by `taskflowadapter_<x>/excludestatus`).

Due dates: `task\check_assignment_status` (queued at `duedate` on every relevant write) →
`assignments_facade::check_and_update_overdue_assignment()` → `overdue::change_status()`: when
`taskflowadapter_tuines/usingprolongedstate` is on, the rule has `extensionperiod > 0`, the assignment is
neither prolonged nor overdue and `prolongedcounter == 0`, the **first** overrun becomes `prolonged`
(`duedate += extensionperiod`, new check queued); otherwise `overdue`, `overduecounter++`
(only while `overduecounter < prolongedcounter || overduecounter == 0`). `prolonged::change_status()`
increments `prolongedcounter` when the new due date is later than the stored one.

`keepchanges` (set by the edit forms) protects `duedate`/`active` from non-manual writes and skips the status
recomputation in `construct_and_process_assignment()`. Full user-level description:
[Status lifecycle](../user/assignments/01-status-lifecycle.md),
[Due dates, prolongation, overdue](../user/assignments/05-due-dates-prolongation-overdue.md),
[Cyclic assignments](../user/assignments/06-cyclic-assignments.md).

---

## 8. Message pipeline

Templates live in `local_taskflow_messages` (`class`, `message` JSON `{heading, body}`, `priority`,
`sending_settings` JSON — schema in [RULE_JSON_FORMAT.md](RULE_JSON_FORMAT.md#6-message-sending_settings-json)).
A rule references templates through `actions[0].messages[].messageid`.

```
who schedules                                     class            when
─────────────────────────────────────────────────  ───────────────  ────────────────────────────────────────
action_operator::check_and_trigger_actions()       standard         sendstart start|end ± senddays×timeunit
                                                                    (message_sending_time), on assignment create/update
assignment_status_base::execute() and              onevent          status ∈ eventlist, sendingcondition
eventhandlers\assignment_completed|_status_changed                   (always | manually | automatically)
→ completion_process\scheduling_event_messages
observer::send_schedule_request_messages           request          request_created / request_treated events
observer::check_and_send_assignment_message_reminder chat           new_assignment_message event
```

Every path ends in `messages_factory::instance($message, $userid, $ruleid[, $manualchanged])` →
`types\{standard|request|chat}` → `schedule_message()`, which deletes identical queued tasks and queues adhoc
`task\send_taskflow_message` with customdata `{userid, messageid, ruleid, manualchanged | requestid | other}`.
When the task runs it rebuilds the object, checks `was_already_send()` (row in `local_taskflow_sent_messages`,
unless `manualchanged && sendmanualmailsmultipletimes`) and `is_still_valid()` (assignment not
completed/droppedout/paused/notrelevant; for status-change messages the status must still be in `eventlist`),
then `send_and_save_message()`:

1. `placeholders_factory::render_placeholders()` — `<name>` tokens resolved by
   `messages\placeholders\types\{name}` (`firstname`, `lastname`, `supervisor_firstname`, `supervisor_lastname`,
   `due_date`, `due_date_with_extension`, `status[ lang]`, `targets`, `opentargets`, `chat`).
2. `message_recipient` — `recipientrole[]` (`assignee`, `supervisor` (+deputies with `sendmailstodeputy`),
   `specificuser`) and `carboncopyrole[]`.
3. `email_to_user()` to each recipient (CC recipients get a separate mail with `[CC]` subject prefix),
   `message_send()` with provider `notificationmessage`, history row `mail_send`, insert into
   `local_taskflow_sent_messages`, purge `changesinassignmentslist`.

`messages_manager::delete_all_not_matching_messages_with_status()` (called on `bookingoption_uncompleted`)
and the status types (`droppedout`, `paused`, reopen) delete sent-message rows so messages can be sent again.

User docs: [Messages](../user/messages/README.md), [Placeholders](../user/messages/02-placeholders.md).

---

## 9. Request flow

A request is a self-service ticket of an assignee about one assignment (`local_taskflow_requests`).
Types are classes in `requests\request_types\types\` (`allowselfnotrelevant` id 1, `allowselfextension` id 2,
`allowuploadevidence` id 3), each enabled globally by the admin checkbox of the same name and per rule by
`actions[0].requests.receiver_<type>` (`not_allowed` | `0` supervisor | `1` HR).

```
form\notrelevantforme | requestprolongation | userevidence
   └─ requests::create($type, $userid, $assignmentid, $status, $createdby, $comment, $json)
        cap local/taskflow:createrequests; forhr from rulejson; event request_created; purge changesinrequestslist
             └─ observer::send_schedule_request_messages → request-class messages (sendstartrequest onrequestcreated)
                to receiver_facade::get_request_receiver(forhr): supervisor_receiver (+deputies) | hr_receiver (hrusers)
requests_table actions (cap treatrequests) / userevidence::process_set_status
   └─ requests->treat_request($id, $assignmentid, $userid, TREATED_STATUS_CONFIRMED|DECLINED)
        DB treated 2|1; history request_confirmed|request_declined; event request_treated
        confirmed notrelevant → assignment status notrelevant; approved evidence → assignment_competency::set_competency()
             └─ observer → request-class messages with onrequestclosed → the requester
```

Dashboards: `output\requestsdashboard` (supervisor/deputy scope via profile fields, `forhr = 0`) and
`requestsdashboardhr` (`forhr = 1`); `all=1` with `viewallrequests` shows everything.
User docs: [Requests](../user/requests/README.md).

---

## 10. Internal chat

`internal_messages\internal_messages($assignmentid)->set_new_assignment_message($text)` inserts into
`local_taskflow_int_com`, fires `new_assignment_message` (→ `chat`-class messages), updates the sender's
`local_taskflow_last_seen` and purges `changesinassignmentslist`. `assignment.php` fires `assignment_seen`
on every view → `assignment_seen::update_or_create_last_seen()`. The scheduled task
`notification_internal_messages` (00:00) diffs `int_com` against `last_seen` and sends a bilingual digest per
recipient type through `messages\notifiaction_message\notification_strategy_factory` (providers
`assigneenotification`, `supervisornotification`, `adminnotification`). The assignments table shows the
newest message (`lastinternalcomment` blob built in `assignment::set_from_sql()` with a window function).
User docs: [Internal communication](../user/messages/03-internal-communication.md).

---

## 11. Adapter layer

The subplugin type `taskflowadapter` (`db/subplugins.json`) is selected by `local_taskflow/external_api_option`.
Core never hard-references a customer adapter *by design* — it builds class names from the setting:

| Core dispatch point | Class looked up | Fallback |
|---|---|---|
| `external_api_repository::create($json)`, `eventhandlers\core_user_created_updated`, `unit_member_updated` | `\taskflowadapter_<x>\adapter` | exception |
| `settings.php` | `\taskflowadapter_<x>\taskflowadapter_<x>::load_settings()` for **every installed** adapter | — |
| `supervisor::get_supervisor_for_user()` | `\taskflowadapter_<x>\taskflowadapter_<x>::get_supervisor_for_user()` | `plugininfo\taskflowadapter::get_supervisor_for_user()` (setting `supervisor_field`) |
| `taskflow_stringmanager::get_string()` | string in component `taskflowadapter_<x>` | `local_taskflow` |
| `output\dashboard::set_data()` | `\taskflowadapter_<x>\output\supervisordashboard`, `admindashboard` | `taskflowadapter_standard\output\*` |
| `output\assignmentsdashboard::set_table()` | `\taskflowadapter_<x>\table\assignments_table` | `local_taskflow\table\assignments_table` |
| `editassignment_template_data_factory::get_data()` | `\taskflowadapter_<x>\output\editassignment_template_data_supervisor` / `_admin` | `taskflowadapter_standard\output\editassignment_template_data` |
| `assignment_status_facade` | config `taskflowadapter_<x>/excludestatus` | none |

Exceptions where core *does* hard-code the `tuines` adapter are listed in the debt section (D-38).
Full contract: [ADAPTER_API.md](ADAPTER_API.md).

---

## 12. Database table map

```
                       ┌──────────────────────────┐
                       │ local_taskflow_units     │◄──────────┐ childid / parentid
                       │ id, name, tissid, …      │           │
                       └───────────┬──────────────┘   ┌───────┴──────────────────┐
                                   │ unitid           │ local_taskflow_unit_rel  │
                                   ▼                  │ childid, parentid, active│
                       ┌──────────────────────────┐   └──────────────────────────┘
        user ─────────►│ local_taskflow_unit_members│
        (core)  userid │ unitid, userid, active   │
          │            └──────────────────────────┘
          │
          │   ┌──────────────────────────┐  unitid|userid (scope)
          │   │ local_taskflow_rules     │
          │   │ rulejson, isactive, …    │
          │   └───────────┬──────────────┘
          │               │ ruleid                          ┌──────────────────────────┐
          │               ▼                                 │ local_taskflow_messages  │
          │   ┌──────────────────────────┐ messages JSON ──►│ class, message,          │
          └──►│ local_taskflow_assignment│ (messageid)      │ sending_settings         │
       userid │ targets JSON, status,    │                  └───────────┬──────────────┘
              │ active, duedate, counters│                              │ messageid
              └──┬────┬────┬────┬────┬───┘                              ▼
                 │    │    │    │    │           ┌────────────────────────────────┐
     assignmentid│    │    │    │    └──────────►│ local_taskflow_sent_messages   │
                 │    │    │    │                │ messageid, ruleid, userid      │
                 │    │    │    ▼                └────────────────────────────────┘
                 │    │    │  local_taskflow_requests   (request, status, treated, forhr, json)
                 │    │    ▼
                 │    │  local_taskflow_assgin_comp     (competencyid, competencyevidenceid, status, validationondate)
                 │    ▼
                 │  local_taskflow_int_com              (message, usermodified)   ─┐ read state per
                 ▼                                                                 │ (userid, assignmentid)
               local_taskflow_history  (type, data JSON, annotation, createdby)    └─► local_taskflow_last_seen
```

| Table | Written by | Key relationships / notes |
|---|---|---|
| `local_taskflow_units` | `units\organisational_units\unit` (only in `unit` mode) | `tissid` unique (external id); in `cohort` mode the core `cohort` table plays this role (matched by `idnumber`) |
| `local_taskflow_unit_rel` | `units\unit_relations` | `(childid, parentid)` unique; `active`; source of `unit_hierarchy` |
| `local_taskflow_unit_members` | `personas\unit_members\types\unit_member`, `observer::cohort_member_added/removed` | `(unitid, userid)` unique; `active` toggled for long leave / lost units |
| `local_taskflow_rules` | `multistepform\editrulesmanager`, `rules::toggle_isactive`, `unit_rules::create_rule`, `unassignment_controller::process_ruledeletion` | `unitid` (unit rule) **or** `userid` (personal rule); `rulejson` (see RULE_JSON_FORMAT.md); `isactive`; `eventname` unused by the current flow; no `timecreated` column |
| `local_taskflow_assignment` | `assignment::add_or_update_assignment` (only write path) | one row per (userid, ruleid) in practice (newest wins on lookup); `unitid` of origin; JSON snapshots `targets`, `messages`; indexes ruleid, userid, status |
| `local_taskflow_messages` | `messages_form\message_form_entity` | tag area `local_taskflow_messages` ("packages") in `tag_instance` |
| `local_taskflow_sent_messages` | `message_base::insert_sent_message` | dedupe log keyed by (messageid, ruleid, userid); rows deleted on droppedout/paused/reopen |
| `local_taskflow_history` | `history\history::log()` | append-only; `type` = `history::TYPE_*` constants; `annotation` = free comment; rendered by `history\types\*` |
| `local_taskflow_assgin_comp` | `competencies\assignment_competency`, `form\userevidence` | links to core `competency_userevidence`; `status` underreview/approved/rejected |
| `local_taskflow_requests` | `requests::create/treat_request/update_request_treated` | `request` = type id (and `status` holds the same id — see debt D-24); `treated` 0/1/2; `forhr` 0/1; `json` extra data |
| `local_taskflow_int_com` | `internal_messages` | chat rows per assignment |
| `local_taskflow_last_seen` | `assignments\assignment_seen` | `(userid, assignmentid)` unique |

Core tables written: `user`, `user_info_field` (upgrade creates `unit_info`, `tissid_info`,
`organisational_unit_info`, `end_info`), `user_info_data`, `cohort`, `cohort_members`, `role_assignments`,
`user_enrolments`, `course_completions`, `course_modules_completion`, `booking_answers`,
`competency_usercomp`, `competency_userevidence(comp)`, `tag_instance`, `task_adhoc`, `tool_certificate_issues`
(standard adapter).

---

## 13. Caches

| Definition (`db/caches.php`) | Mode | Invalidated by | Holds |
|---|---|---|---|
| `unit_hierarchy` | APPLICATION, ttl 3600 | `unit_hierarchy::invalidate_cache()` | `full_hierarchy` array |
| `ruleslist` | APPLICATION | event `changesinruleslist` | wunderbyte_table cache of the rules table |
| `assignmentslist` | APPLICATION | event `changesinassignmentslist` | wunderbyte_table cache of assignment tables |
| `historylist` | APPLICATION | event `changesinhistorylist` (purged by `history::log()`) | history table |
| `dashboardfilter` | SESSION | `changesinassignmentslist` | selected user tabs (`dashboardcache`) + chart objects |
| `requestslist` | SESSION | `changesinrequestslist` | requests table |
| `assignments` | APPLICATION, static acceleration 100 | `assignment::destroy_instance()` / write path | `assignment` entity per id |

Static per-request caches also matter (and must be reset in tests via the generator's `teardown()`):
`external_api_base::$users/$usersbyid/$usersbyemail` and `$importing`, `rules::$instances`,
`unit_rules::$instances`, `unit_relations::$instances`, `unit::$instances`, `cohort::$instances`,
`standard_assignment::$instances`, `unit_member::$instances`, target type caches,
`singleton_service` (users), `mod_booking\singleton_service`.

---

## 14. Tasks, events, observers

**Scheduled tasks** (`db/tasks.php`): `task\reschedule_rules` (02:00), `task\notification_internal_messages`
(00:00); adapter `taskflowadapter_tuines\task\fetch_dwh_data` (03:00).

**Adhoc tasks** (`classes/task/`): `update_rule`, `unit_updated`, `update_assignment`, `removed_rule`,
`check_assignment_status` (customdata `userid, ruleid, assignmentid, scheduledtime`), `open_planned_assignment`,
`reset_cyclic_assignment`, `send_taskflow_message`, `check_supervisor`. Tasks are queued through
`\core\task\manager::reschedule_or_queue_adhoc_task()` so identical customdata collapses into one row.

**Events** (`classes/event/`, all `crud = 'c'`, `LEVEL_OTHER`): `assignment_created`, `assignment_completed`,
`assignment_status_changed`, `assignment_seen`, `new_assignment_message`, `request_created`, `request_treated`,
`rule_created_updated`, `unit_member_removed`, `unit_member_updated`, `unit_relation_updated`, `unit_removed`,
`unit_updated`, `upload_error`. The full observer table is in `db/events.php`; besides the plugin's own events it
listens to core `user_created/updated/deleted`, `cohort_member_added/removed`, `cohort_deleted`,
`course_completed`, `course_completion_updated`, `course_reset_ended`, `competency_user_competency_rated`,
and `mod_booking` `bookingoption_booked`, `bookingoption_uncompleted`.
Admin view: [Scheduled tasks](../user/scheduled_tasks/README.md).

---

## 15. Extension points

| You want to… | Do this | Discovery mechanism |
|---|---|---|
| Support a new HR feed / customer | Write a `taskflowadapter_<name>` subplugin ([ADAPTER_API.md](ADAPTER_API.md)) | class-name convention from `external_api_option` |
| Add an assignment status | Add `classes/local/assignment_status/types/<label>.php` extending `assignment_status_base` with a unique `identifier`; add lang string `status<label without _>` | `glob()` in `assignment_status_facade` |
| Add a message placeholder | Add `classes/local/messages/placeholders/types/<name>.php` implementing `placeholders_interface` (`__construct($ruleid, $userid, $assignment)`, `render(&$message)`) | regex `<name>` + `class_exists()` in `placeholders_factory`; listed by `placeholders_manager` |
| Add a message type | Add `classes/local/messages/types/<type>.php` extending `message_base` with `TYPE`/`TITLE`; map it in `messages_factory::instance()` and `message_form_entity` | `messages_facade::get_message_types()` scans the directory |
| Add a sending condition | Add `classes/local/messages/sending_condition/types/<id>.php`; register in `sending_condition_facade::create()`/`get_all()` | explicit switch |
| Add a runtime filter type | Add `classes/local/filters/types/<filtertype>.php` implementing `filter_interface` **and** a form class `classes/form/filters/types/<filtertype>.php` implementing `filter_types_interface` | `filter_factory::instance()` by `filtertype`; the form lists the type in `filter::definition_subelement()` |
| Add a comparison operator | Extend `operators\string_compare_operators::get_operator_keys()`, `get_operator_keys_and_values()`, `validate()` | explicit lists |
| Add a target type | Add `actions\targets\types\<type>.php` (name/link), `completion_process\types\<type>.php` (`is_completed`), a branch in `actions\types\enroll`/`unenroll`, and a form class `form\targets\types\<type>.php` | class-name from `targettype` |
| Add an action type | Add `classes/local/actions/types/<actiontype>.php` implementing `actions_interface` and write `actiontype` into the target JSON | `actions_factory::instance()` |
| Add a request type | Add `requests\request_types\types\<key>.php` extending `requests_base` (`ID`, `SETTINGKEY`) + admin checkbox + a form that calls `requests::create()`; add a receiver in `request_receivers/receivers/` | `requests_manager` scans `types/` |
| Add a history row renderer | Add `history\types\<type>.php` extending `base` and a `history::TYPE_*` constant | `typesfactory::create()` by type name |
| React to plugin events | Add an observer in your own plugin's `db/events.php` for `\local_taskflow\event\*` | Moodle events |
| Add a dashboard block | Use the shortcodes (`db/shortcodes.php`) or the `assignmentsdashboard` renderable with a custom `assignmentdataprovider` implementation | DI |
| Handle a new plugin event internally | Add `local\eventhandlers\<name>.php` with `public $eventname` and `handle()`, and register the event in `db/events.php` → `observer::call_event_handler` | namespace scan |

---

## 16. Known technical debt

Consolidated and de-duplicated from the six code inventories (September 2026). Priorities:
**P1** security / data integrity, **P2** silent functional defects, **P3** dead or misplaced code,
**P4** cosmetic / test-suite quality. Paths are relative to `local/taskflow/`.

### P1 — Security and data integrity

| # | Issue | Where |
|---|---|---|
| D-01 | Dynamic (AJAX) forms only call `require_login()` in `check_access_for_dynamic_submission()`; any logged-in user who knows an assignment id can change status/due date, post chat messages or add comments (IDOR). Core request forms check the capability only in `validation()`. | `taskflowadapter/standard/classes/form/editassignment.php`; `taskflowadapter/tuines/classes/form/{editassignment_admin,editassignment_supervisor,comment_form,internal_communication_form}.php`; `classes/form/{notrelevantforme,requestprolongation,dynamic_select_users}.php`; `classes/form/delete_userevidence.php` (`can_delete()` result ignored) |
| D-02 | `action=checkstatus` (re-runs the assignment pipeline) and the `assignment_seen` event execute **outside** the permission check. | `assignment.php` |
| D-03 | Debug action `deleteall` truncates core `cohort_members` for the whole site (guarded by `$CFG->debug` + `moodle/site:config`). | `view.php` |
| D-04 | tuines missing-person check has no plausibility gate: a partial DWH export suspends every other staff member, removes them from all cohorts, destroys sessions and drops out all their assignments; `activate_moodle_users()` un-suspends **every** suspended user of the site not in the missing list, including manually suspended accounts. | `taskflowadapter/tuines/classes/security_check.php`, `classes/task/fetch_dwh_data.php` |
| D-05 | Rule deletion hard-deletes assignment rows without cleaning `local_taskflow_history`, `_sent_messages`, `_int_com`, `_last_seen`, `_requests`, `_assgin_comp` → orphans. | `classes/local/unassignment_process/unassignments/unassignment_controller.php::process_ruledeletion()` |
| D-06 | `unit_relations::update()` writes to `local_taskflow_units` instead of `local_taskflow_unit_rel` (also uses undefined `$usermodified`); `delete_all_relations()` uses the relation id where unit ids are needed; `change_activision()` therefore corrupts data. | `classes/local/units/unit_relations.php` |
| D-07 | Deleting a message template removes `tag_instance` rows with `itemtype = 'messages'` — the real itemtype is `local_taskflow_messages` → orphaned tag instances. | `message_form/editmessage.php` |
| D-08 | `fetch_dwh_data` performs a plain unauthenticated GET with no timeout override; failures are only visible as `dwh_fetch_failed` log events. | `taskflowadapter/tuines/classes/task/fetch_dwh_data.php` |
| D-09 | Schema drift between `install.xml` and `upgrade.php` (history indexes `userid_idx`/`type_idx`, `duedate NOT NULL`, `unit_rel.active` default, `assgin_comp` keys added then dropped, stale XMLDB VERSION 2025011900). Fresh installs and upgraded sites differ. | `db/install.xml`, `db/upgrade.php` |

### P2 — Functional defects (silent wrong behaviour)

| # | Issue | Where |
|---|---|---|
| D-10 | Filter type `user_field` (firstaccess/lastaccess) exists in the form but has no runtime class → `filter_factory` returns null, `filter_operator` skips it, the filter matches everyone. | `classes/form/filters/types/user_field.php` vs missing `classes/local/filters/types/user_field.php` |
| D-11 | Operator `since` is offered in the UI and used by rules but missing from `string_compare_operators::get_operator_keys()` → `is_valid_comparions()` fails for JSON-array profile values. | `classes/local/operators/string_compare_operators.php`, `classes/local/filters/types/user_profile_field.php` |
| D-12 | JSON-array profile values: only the **first** element is evaluated (`return` inside the loop). | `classes/local/filters/types/user_profile_field.php::check_field_compatibility()` |
| D-13 | `assignment::set_prolonged_state_on_change()` passes the label `'prolonged'` where the facade expects a numeric id → silent no-op; manual due-date extension of an overdue assignment does not set `prolonged` through this path. | `classes/local/assignments/assignment.php` |
| D-14 | `assigned::change_status()` condition `status == paused && status == droppedout` can never be true (`\|\|` intended) → its reset branch is dead. | `classes/local/assignment_status/types/assigned.php` |
| D-15 | `overdue::change_status()` reads `get_config('taskflowadapter_tuines', 'usingprolongedstate')` regardless of the active adapter; `extensionperiod` is only honoured under that flag. `excludestatus` is honoured by core for every adapter but only tuines exposes it in its settings UI. | `classes/local/assignment_status/types/overdue.php`; `taskflowadapter/{standard,ksw}/classes/taskflowadapter_*.php` |
| D-16 | `assignment_preprocessor::get_unit_users()` hard-codes the profile field shortname `longleave` instead of resolving `TRANSLATOR_USER_LONG_LEAVE`; `get_units_users()` omits the `u.suspended = 0` check the other method has. | `classes/local/assignment_process/assignment_preprocessor.php` |
| D-17 | `construct_and_process_assignment()` takes `targets`/`messages` of the **last** action only (multiple actions unsupported); constructs `completion_operator` with `targetid 0 / targettype 0`. | `classes/local/assignment_process/assignments/assignments_controller.php` |
| D-18 | `handle_completion_process()` sets `completeddate = time()` when the status becomes `enrolled` (copy-paste of the completed branch). `types_base::filter_affected_assignments()` can add the same assignment twice (`continue` instead of `break`) → double processing. `is_target_completed()` calls `is_completed()` without its required argument (no caller). | `classes/local/completion_process/completion_operator.php`, `types/types_base.php` |
| D-19 | `standard_assignment::create_assignment/update_assignment/check_if_status_changed()` are never called → `\local_taskflow\event\assignment_status_changed` is never fired; `eventhandlers\assignment_status_changed` is effectively dead (status messages are scheduled from `assignment_status_base::execute()` instead). | `classes/local/assignments/types/standard_assignment.php`, `classes/local/eventhandlers/assignment_status_changed.php` |
| D-20 | `assignment_status_base::execute()`: on manual change `usermodified` becomes `hrusers[0]` regardless of actor; references undefined `$data['usermodified']`. `assignment::add_or_update_assignment()` ignores its `$historytype` parameter. `assignments_facade::check_and_update_overdue_assignment()` contains the no-op `$x = $x++`. | `classes/local/assignment_status/assignment_status_base.php`, `classes/local/assignments/assignment.php`, `assignments_facade.php` |
| D-21 | Message class derivation: `onevent` is produced only when `sendstart` is empty, but the standard form always submits `sendstart` (default `start`) → a "Status change" message may be stored as `class = standard` and treated as time-based. `messages_factory` returns null for classes `onrequestcreated`/`onrequestclosed` (no type class); `observer::send_schedule_request_messages` would then fatal. `receiver_facade::get_chat_receiver()` returns the *sender* when the sender is not the assignee. | `classes/local/messages_form/message_form_entity.php`, `classes/local/messages/messages_factory.php`, `classes/local/requests/request_receivers/receiver_facade.php` |
| D-22 | Rule form: `timecreated` logic inverted (`!empty(...) ? $now : ...`); `type` is taken from `$steps[1]['ruletype']`, which no form sets (null for UI-created rules; fixtures use `"taskflow"`). `types\unit_rule::definition_after_data()` unused. | `classes/form/rules/types/unit_rule.php` |
| D-23 | `unit_hierarchy::check_and_set_master()` references undefined `$unitrelations`. Cohort idnumber asymmetry: created cohorts get `crc32(parent/name)`, parent lookup uses `crc32(parentname)`; `cohort::__construct` sets `$component` from `contextid`. | `classes/local/units/unit_hierarchy.php`, `classes/local/units/organisational_units/cohort.php` |
| D-24 | `local_taskflow_requests.request` and `.status` both hold the request type id; tables and `singleassignment` compare `status` to type ids — semantics overlap. `requests_table::col_act()` has `case TREATED_STATUS_UNTREATED && $capability:` (boolean expression, matches `treated == 0` regardless of capability; server-side `require_capability` still protects). | `classes/local/requests.php`, `classes/table/requests_table.php` |
| D-25 | Adapter base: `map_value()` is **private** → adapter overrides never apply inside `translate_incoming_data()` (docblock claims otherwise); `translate_incoming_data()` does not descend `->` paths (each segment re-reads the top-level record; `translate_incoming_target_groups()` does descend); `store_user_in_static()` reads `profile['translator_user_externalid']` (the constant, never a real key) → external-id cache always keyed by username. | `classes/local/external_adapter/external_api_base.php` |
| D-26 | standard/ksw adapters: `contract_ended()` validates a unix timestamp against `Y-m-d` → an `upload_error` event per user with a contract end on every run; `$updatedentities` passed by value / kept local → `unit_relation_updated` / `unit_member_updated` events are never fired by these adapters; `necessary_customfields_exist()` treats the multiselect as a single string → always false with ≥2 fields; `set_supervisor_internal_id()` throws (`MUST_EXIST`) when SUPERVISOR_EXTERNAL is mapped but EXTERNALID is not and ignores `deleted`; `load_settings()` label of `blscertificatekey` concatenates the stale loop variable `$label`. KSW lacks null guards present in standard (`create_or_update_supervisor`, `translate_users`). | `taskflowadapter/standard/classes/{adapter,taskflowadapter_standard}.php`, `taskflowadapter/ksw/classes/{adapter,taskflowadapter_ksw}.php` |
| D-27 | tuines adapter: `external_api_base::$importing` set true in `create_or_update_users()` and only reset in the un-suspend path (stays true for the rest of the process); `invalidate_units_on_change()` passes the **external** unit id to `moodle_unit_member_facade->remove()` but Moodle ids to `set_user_units_assignments_inactive()`; `observer::user_info_field_deleted` unsets the function setting but not `translator_user_<shortname>`; `assignments_table::is_allowed_to_edit()` requires `prolongedcounter == 1` while the docblock says `<= 2`; behavioural inconsistency between tests on re-entry while on long leave (`gh_70_completed_do_not_come_back_test` → assigned vs `betty_best_overdue_after_paused_test` → paused). | `taskflowadapter/tuines/classes/{adapter,observer,security_check}.php`, `classes/table/assignments_table.php` |
| D-28 | HR users are defined twice: `local_taskflow/hrusers` (request receivers, status history) vs `bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers` (dashboard admin tab, shortcodes). | `settings.php`, `classes/output/dashboard.php`, `classes/shortcodes.php` |
| D-29 | Setting `inheritance_option` has no consumer; `supervisor_field` is only offered when no adapter is installed (never, adapters ship in-tree) yet is read by the fallback `plugininfo\taskflowadapter::get_supervisor_for_user()`; `users_profile_factory` reads `user_profile_option` (never set) and always returns the no-op `thour`. | `settings.php`, `classes/plugininfo/taskflowadapter.php`, `classes/local/users_profile/*` |
| D-30 | `history::return_sql()` appends `LIMIT n` inside the WHERE string (not portable; conflicts with wunderbyte_table paging). | `classes/local/history/history.php` |
| D-31 | Target types: `bookingoption::get_name_with_link()` links to `/mod/booking/view.php?id=<optionid>` (`id` is a cmid there); `moodlecourse::instance()` has no missing-record guard (fatal for deleted courses); `moodlecourse::destroy_instance()` empties the static `$formidentifiers` config; `competency::instance()` returns `false` where others return `null`. `deputy::get_deputies_of_user()` uses `MUST_EXIST` (throws on a deleted deputy id). | `classes/local/actions/targets/types/*.php`, `classes/local/deputy/deputy.php` |
| D-32 | `assignment_competency` extends `\core\persistent` but overrides the constructor without calling the parent and mixes public properties with persistent getters; `assignmentid` declared `PARAM_ALPHANUMEXT` for an int column. | `classes/local/competencies/assignment_competency.php` |
| D-33 | Strict comparisons on DB-typed values: `assignment::is_my_assignment()` (`===` int vs string), standard observer `$assignment->ruleid === $rule->id`, `reopen_missing_person_assignment()` `!==`. May never match depending on driver typing. | `classes/local/assignments/assignment.php`, `taskflowadapter/standard/classes/observer.php`, `assignments_facade.php` |
| D-34 | `output\dashboard::set_data()` checks `!empty($html)` before `$html` is set; the current user is always added as a tab; `check_supervisor::get_all_supervisors()` dereferences the field record without existence check. | `classes/output/dashboard.php`, `classes/task/check_supervisor.php` |
| D-35 | Form robustness: `form\requests\requests::set_data_for_dynamic_submission()` accesses `$data['requests']` without isset; `form\filters\filter::validation()` iterates `user_profile_field_userprofilefield` without isset (breaks with only `user_field` filters); `notrelevantforme` calls core `get_string('requestnotrelevantalreadyexisiting')` without component; `delete_userevidence` uses `$taskflowacrecord` after a possibly failed transaction. | `classes/form/*` |
| D-36 | Target pickers load **all** courses / competencies / booking options into the form (no LIMIT / AJAX). `tuines\table\assignments_table::col_fullname()` runs `profile_user_record()` per row (N+1). | `classes/form/targets/types/*.php`, `taskflowadapter/tuines/classes/table/assignments_table.php` |
| D-37 | `rulesdashboard` sorts by non-existent `timecreated`; `rules_table` AJAX fallback URL `/index.php/my`; `assignments_table::set_return_url()` stored but `col_actions` uses `$PAGE->url`. | `classes/output/rulesdashboard.php`, `classes/table/{rules_table,assignments_table}.php` |
| D-38 | Core hard-codes the `tuines` adapter: `renderer::render_singleassignment()` template, `singleassignment.php` and `editassignment.mustache` (`comment_form`, `internal_communication_form`), `form\importdwh` (`fetch_dwh_data`), `assignmentsdashboard::set_my_table_heading()` hides headings for tuines, `overdue.php` (D-15). Conversely, KSW-flavoured logic lives in *standard* (`%BLS%` rule-name match + `tool_certificate` observer) and KSW `lib.php` hard-codes role id 18 and course ids 9/8/29. | `classes/output/renderer.php`, `classes/output/singleassignment.php`, `templates/editassignment.mustache`, `classes/form/importdwh.php`, `taskflowadapter/standard/classes/observer.php`, `taskflowadapter/ksw/lib.php` |
| D-39 | Observers, shortcodes and navbar callbacks of **every installed** adapter are active regardless of `external_api_option` (e.g. the standard BLS certificate observer runs on a KSW site; the KSW navbar appears with `standard` selected). All adapters' settings sections are rendered simultaneously. | `taskflowadapter/*/db/events.php`, `db/shortcodes.php`, `lib.php`; `settings.php` |
| D-40 | `db/tag.php` references `lib.php::local_taskflow_messages_get_tagged_items` — `lib.php` does not exist → tag area callback broken. `tool_certificate` used by `mycertificates.php`, `my_certificates_table`, `userstatscard`, standard observer but not declared in `version.php`. | `db/tag.php`, `version.php` |
| D-41 | All events use `crud = 'c'` (also for updates/removals) and `get_url()` points at `view.php?id=<objectid>`, which `view.php` does not interpret. `eventhandlers\unit_member_removed` expects `other.unitmemberid` as an array and takes the first element. | `classes/event/*.php`, `classes/local/eventhandlers/unit_member_removed.php` |
| D-42 | Adapter mail behaviour under test but unverified: `sendmailstodeputy` tests assert the same sent-message count with and without deputy; `test_request_extension_created_and_declined_*` create `allowselfnotrelevant` requests (copy-paste) → the extension-declined path is untested. | `tests/requests/requests_messages_test.php` |

### P3 — Dead, duplicated or misplaced code

| # | Item | Where |
|---|---|---|
| D-43 | Demo/dead UI: `draftdashboard.php` + `templates/dashboards/dashboard_draft.mustache` (static German dummy data), `templates/initview.mustache` (Vue mount point without Vue), root `renderer.php` (empty legacy class shadowed by `output\renderer`). | root, `templates/` |
| D-44 | `adhoc_task_process\adhoc_task_controller` and `assignment_operators\assignment_operator::get_open_and_active_assignments()` are only reachable from tests (superseded by `assignment_preprocessor`). | `classes/local/adhoc_task_process/`, `classes/local/assignment_operators/assignment_operator.php` |
| D-45 | No production caller: `assignments\activity_status\assignment_activity_status` (defines `PAUSED = -1`, never written), `competencies\competency` (superseded by `assignment_competency`), `booking_migration::open_assignemnt()` (private, hard-coded `ruleid=1, assignmentid=1`), `standard_assignment::set_active_state()`, `assignments_facade::reopen_missing_person_assignment()` (only from dead tuines `security_check::open_all_dropped_out_assignments()`/`is_rule_still_valid()`), `unenroll` has no `unenrol_from_course` although `enroll` accepts `course`. Note: `longleave_facade`, `set_all_assignments_of_user_to_status()` and `set_user_units_assignments_active()` **are** used — by the tuines/ksw adapters. | `classes/local/assignments/`, `classes/local/assignment_process/booking_migration.php`, `taskflowadapter/tuines/classes/security_check.php` |
| D-46 | `rules\types\unit_rule` is an unreferenced duplicate of `unit_rules`; `rules::instance()` returns `[]` instead of `null` when missing. | `classes/local/rules/types/unit_rule.php` |
| D-47 | `eventhandlers\new_chat_message` is a no-op (the event class does not exist); `base_event_handler` is empty; `eventhandlers\core_user_created_updated::$eventname` names a non-existent event (invoked directly by the observer). | `classes/local/eventhandlers/` |
| D-48 | `users_profile\types\thour::update_or_create()` body commented out; `users_profile_factory` ignores its setting. | `classes/local/users_profile/` |
| D-49 | `external_adapter\adapters\external_api_user_data` (generic in-core adapter) is not instantiable through `external_api_repository`; `external_api_base::start/end_dynamic_report()` hard-code `/var/www/moodle/xhprof`; three identical static-cache reset methods (`teardown`, `reset_static_caches`, `destroy_instance`); `external_api_error_logger::$usererror` written, never read; `plugininfo\taskflowadapter::return_setting_mappingdescription()` empty. | `classes/local/external_adapter/` |
| D-50 | Adapter leftovers: standard `templates/naventry.mustache` (no `lib.php`, missing string `quickaccess`), standard `classes/shortcodes.php` (no `db/shortcodes.php`), private `get_user_info()`/`show_user_stats()` in all four dashboard classes (never called, unresolved class names), KSW `blscertificatekey` setting (no KSW consumer), unused imports in KSW `observer.php`, tuines `adapter::$issidmatching`, `assignments_table::col_testmoodleid()` (self-declared temporary column still in core's column list), ~30 orphan tuines lang strings for a removed "manage booking custom field options" feature plus `cachedef_dashboardfilter`, `extensiontext` string unused while `denytext` is shown under both headers. | `taskflowadapter/*` |
| D-51 | History renderers: `course_completed::render_additional_data()` returns the literal `'rasch'`; `competency_earned` has no matching `TYPE_*` constant. | `classes/local/history/types/` |
| D-52 | Unused/broken imports: `assignments_table` imports non-existent `local_taskflow\output\last_seen`; `unassignment_controller` imports `mod_booking\table\instancetemplatessettings_table`. | `classes/table/assignments_table.php`, `classes/local/unassignment_process/unassignments/unassignment_controller.php` |
| D-53 | Misspelled identifiers that are part of the public API: `process_assignemnts`, `get_all_active_assignemnts`, `set_stauts`, `has_no_exsisting_assignment`, `shedule_new_assignment_check`, `is_valid_comparions`, `calaculate_sending_time`, `notifiaction_message` namespace, `local_taskflow_assgin_comp` table, `assingmentcompetencyid` JSON key. | various |
| D-54 | Stale design docs inside `classes/`: `assignments/assignments_info.md` is a copy of `actions/action_info.md`; `action_info.md` mentions a non-existent "propose" action; `rules/README.md` sketches an outdated JSON shape; `users_profile_info.md` mentions "Inses"/"Thour". | `classes/local/**/*.md` |

### P4 — Cosmetic and test-suite quality

| # | Item | Where |
|---|---|---|
| D-55 | Lang: German strings in EN files (`inheritance` "Regelvererbung", `invalidjson`, `cachedef_requestslist`; standard `lessfunctions`/`manyfunctions`), English in DE (KSW `lessfunctions`/`manyfunctions`), missing keys `requestconfirmsuccess`/`requestdeclinesuccess`, `eventdwhfetchfailed` missing in tuines DE, typos `pendingapprovals` "Pending Approvlas", `recursive` "exsisting"; link asymmetries in `assignmentsavailable*` between en/de. | `lang/en/local_taskflow.php`, `taskflowadapter/*/lang/` |
| D-56 | Hard-coded English in JS and forms (`uploadusers.js` modal titles, `internal_communication_form` "Send message" / "No conversation has been found so far."), `console.log` left in `dashboard.js`, `uploadusers.js`, `form_users_selector.js`; `messages_facade::get_message_types()` titles are constants, not lang strings. | `amd/src/`, `taskflowadapter/tuines/classes/form/internal_communication_form.php`, `classes/local/messages/messages_facade.php` |
| D-57 | Test contradictions: `requests/request_evidence_test.php` asserts `recordscount = 0` for supervisors/deputies while `requests_test.php` documents 1/1/2/1/1 without asserting; `deputy_assignmentsdashboard_test` guards deputy data behind `field_exists('user_info_field','deputy')` (always false → inert); hierarchy fixtures (`mock_user_data_hierarchy.json`) are imported with flat KSW mapping → tests assert 0 units/relations and never exercise hierarchies or `inheritance_option`; `assertTrue(10 <= $array)` style always-true assertions in `receive_external_*` tests; `message_once_always_false_test` sets config on component `taskflow` (typo). | `tests/requests/`, `tests/usecases/deputy_assignmentsdashboard_test.php`, `tests/units_relation/`, `tests/units/organisational_units/cohort_relation_test.php`, `tests/external_data/`, `taskflowadapter/tuines/tests/usecases/messages/` |
| D-58 | Coverage gaps: cyclic re-assignment (time past `cyclicduration`) not asserted in core; `nowminusdays` semantics not pinned (`<=` vs `==`); message timing only `after/start/7 days` asserted; several tests skip themselves when `mod_booking` classes are already loaded (`load_dashboard_test`, `dashboard_test`, `migration_check_old_bookingoptions_test`) → never run in a full suite; many smoke tests without assertions (`observer_test`, `adhoc_task_controller_test`, `check_assignment_status_test`, `send_taskflow_message_test`, …); misleading test names. | `tests/**` |
| D-59 | tuines CI installs `local_taskflow` from branch `inestests`; KSW CI from `KSW-W` with `mod_booking` branch `KSW` — adapter suites are tied to specific core branches. | `taskflowadapter/*/.github/workflows/moodle-plugin-ci.yml` |
| D-60 | Unused/duplicate fixtures: `lydia_late` (0 bytes), `lydia_late_ksw.json`, `lucy_lazy_ksw.json`, `garry_gone_ksw.json`, `overdue_import_ksw.json`, KSW `mock/` (copy of tuines), `5000pers.json` (2.2 MB, unused by core tests), `usecases/*/external_json` duplicated pairs. | `tests/mock/`, `taskflowadapter/*/tests/` |
