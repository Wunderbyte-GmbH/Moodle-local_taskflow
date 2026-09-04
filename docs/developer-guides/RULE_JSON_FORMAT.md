[Back to documentation index](../README.md)

# Rule JSON Format — `local_taskflow_rules.rulejson`

This is the complete developer reference for the JSON stored with a Taskflow rule: how the row in
`local_taskflow_rules` is composed, every key of `rulejson` with type, allowed values and default, the filter,
target, message and request sub-documents, and how the same JSON is copied into an assignment. The related
`sending_settings` JSON of a message template is documented here as well because rules only reference message
ids and the sending behaviour lives in the template.

For the runtime that consumes this JSON see [ARCHITECTURE_OVERVIEW.md](ARCHITECTURE_OVERVIEW.md); for the
editing UI see the [Rules chapter](../user/rules/README.md) of the user documentation.

---

## Quick setup path

1. Create a rule through the UI (`/local/taskflow/editrule.php?id=0`) and read the stored row
   (`SELECT * FROM {local_taskflow_rules}`) to see a real instance of the format below.
2. When generating rules programmatically (imports, tests), insert the row with `rulejson` as a JSON string and
   fire `\local_taskflow\event\rule_created_updated` with `other.ruledata` = the row — the adhoc task
   `update_rule` creates the assignments.
3. Validate your JSON against [section 8](#8-full-annotated-example) and keep `actions` to exactly one element.

---

## Table of Contents

1. [The database row](#1-the-database-row)
2. [Top-level structure](#2-top-level-structure)
3. [Filter JSON](#3-filter-json)
4. [Target JSON](#4-target-json)
5. [Messages JSON (rule side)](#5-messages-json-rule-side)
6. [Message `sending_settings` JSON](#6-message-sending_settings-json)
7. [Requests JSON](#7-requests-json)
8. [Full annotated example](#8-full-annotated-example)
9. [What is copied into an assignment](#9-what-is-copied-into-an-assignment)
10. [How the JSON is produced and read](#10-how-the-json-is-produced-and-read)
11. [Pitfalls](#11-pitfalls)

---

## 1. The database row

`local_taskflow_rules` (see `db/install.xml`):

| Column | Type | Written from | Meaning |
|---|---|---|---|
| `id` | int | — | primary key; referenced by `local_taskflow_assignment.ruleid` and by `local_taskflow_sent_messages.ruleid` |
| `unitid` | int, default 0 | step 1 `unitid` when `targettype = unit_target` | the unit (or cohort id in cohort mode) the rule applies to. `unit_rules::instance($unitid)` loads all rules of a unit. |
| `userid` | int, default 0 | step 1 `userid` when `targettype = user_target` | personal rule for one user; loaded by `assignment_preprocessor::get_all_personal_rules()` |
| `rulename` | char(255) | step 1 `name` | display name (rules dashboard, `col_rulename` in assignment tables, chat digest mails, standard adapter `%BLS%` certificate match) |
| `rulejson` | text | `form\rules\types\unit_rule::get_data()` | the JSON document described below |
| `eventname` | char(255) | — | reserved for event-based rules; not written or read by the current flow |
| `isactive` | int(2), default 1 | step 1 `enabled`; toggled by `rules::toggle_isactive()` | only active rules produce assignments (`filter_operator::is_rule_active_for_user()` returns false otherwise) |

`unitid`, `userid` and `isactive` are therefore stored **alongside** the JSON and duplicated inside it
(`rule.enabled`, and the scope is implied by which of `unitid`/`userid` is set). The JSON does not carry
`unitid`/`userid`; `editrulesmanager::load_data()` injects them from the row when the form is opened.
Exactly one of `unitid` / `userid` should be non-zero; the rule form enforces this (`validation()`).

There is **no `timecreated`/`timemodified` column** on the row; the timestamps live inside the JSON.

---

## 2. Top-level structure

```json
{
  "rulejson": {
    "rule": {
      "name": "…", "description": "…", "type": null,
      "enabled": 1, "recursive": 0, "inheritance": 0,
      "cyclicvalidation": 0, "cyclicduration": 31536000, "activationdelay": 0,
      "duedatetype": "duration", "duration": 2419200, "fixeddate": 0, "extensionperiod": 2419200,
      "timecreated": 1756900000, "timemodified": 1756900000, "usermodified": 2,
      "filter":  [ … ],
      "actions": [ { "targets": [ … ], "messages": [ … ], "requests": { … } } ]
    }
  }
}
```

The double wrapper `rulejson.rulejson.rule` is historical; all readers decode the column and then access
`->rulejson->rule`. Keys of `rule` (in creation order of `unit_rule::get_data()`):

| Key | Type | Allowed values / default | Set by | Read by |
|---|---|---|---|---|
| `name` | string | any (mandatory in the form) | step 1 `name` | `assignment::return_class_data()` (`name`), rules dashboard search, `<targets>` placeholder lookup |
| `description` | string | any, may be empty | step 1 `description` | `rules_table::col_description`, `requests_table::col_description`, `singleassignment` |
| `type` | string \| null | historically `"taskflow"`; **null for UI-created rules** (taken from `$steps[1]['ruletype']`, which no form sets) | step 1 | nothing at runtime |
| `enabled` | 0 \| 1 | default 1 | step 1 `enabled` ("Enable rule") | `changemanager` (change detection); the effective flag is the row column `isactive` |
| `recursive` | 0 \| 1 | default 0 | step 1 `recursive` ("Enable or disable rule will also effect exsisting assignments") | `assignment_controller::check_recursive_assignment()` — when a rule is *saved*, existing assignments are only touched if `recursive == '1'`; `assignments_controller` re-derives the due date of existing assignments only when recursive; `assignment_preprocessor::set_all_inheritance_unit_rules()` includes parent-unit rules that are recursive |
| `inheritance` | 0 \| 1 | default 0 | step 1 `inheritance` | `assignment_preprocessor::set_affected_users()` — apply the rule to members of the unit **and all child units** (`unit_hierarchy::get_all_childerns`) |
| `cyclicvalidation` | 0 \| 1 (stored as int or `"1"`; readers compare `== '1'`) | default 0 | step 1 | `assignmentrule::is_cyclic()`, `scheduling_cyclic_adhoc`, `booking_migration`, `completion_process\types\*` (completion must be newer than `now - cyclicduration`) |
| `cyclicduration` | int seconds | default 31536000 (1 year) | step 1 (`duration` element) | as above; `task\reset_cyclic_assignment` is queued at `completion + cyclicduration` |
| `activationdelay` | int seconds | default 0 | step 1 (duration element, units hours/days) | `assignment_status_facade::set_initial_status()` → `planned` when `> 0`; `task\open_planned_assignment` queued at `now + activationdelay` |
| `timecreated` | int unix \| null | see D-22 in the architecture debt list: the expression is inverted, so new rules get `null` and edited rules get "now" | `unit_rule::get_data()` | nothing at runtime |
| `timemodified` | int unix | now at save | `unit_rule::get_data()` | nothing at runtime |
| `usermodified` | int userid | `$USER->id` at save | `unit_rule::get_data()` | nothing at runtime |
| `duedatetype` | `"duration"` \| `"fixeddate"` | form default `duration`; `get_data()` falls back to `fixeddate` when the key is absent | step 1 "Due date type" | `assignments_controller::set_due_date()`, `standard_assignment::update_or_create_assignment()` |
| `duration` | int seconds | default 2419200 (4 weeks) | step 1 | `set_due_date()`: `duedate = assigneddate + duration` (new assignment: `now + duration`) |
| `fixeddate` | int unix | default now + 4 weeks (form) / 0 | step 1 | `set_due_date()`: `duedate = fixeddate` |
| `extensionperiod` | int seconds | default 2419200 | step 1 "Extension period" | `overdue::get_extension_period()` (automatic prolongation when `usingprolongedstate`), `<due_date_with_extension>` placeholder, default due date in the admin/supervisor edit forms |
| `filter` | array of filter objects | `[]` = no filter (everyone in scope) | step "Filter" | `filter_operator::is_rule_active_for_user()`, `task\reschedule_rules` (scans for operator `nowminusdays`) |
| `actions` | array with **exactly one** element | — | steps "Targets", "Messages", "Requests" each write into `actions[0]` | `assignments_controller` (takes targets/messages of the **last** element), `action_operator`, `scheduling_event_messages`, `singleassignment::get_request_states()` (`actions[0].requests`), `requests::create()` |

All numeric values may be stored as strings when a rule was written by hand or by fixtures; readers use loose
comparison (`== '1'`), so `"1"` and `1` are equivalent. `cyclicvalidation` in particular is compared with
`== "1"`.

---

## 3. Filter JSON

`rule.filter` is an array. Every element is one condition; **all** conditions must pass (AND). A filter whose
`filtertype` has no runtime class is skipped silently (treated as passing). Evaluation:
`local\assignment_operators\filter_operator` → `local\filters\filter_factory::instance($filter)` →
`local\filters\types\{filtertype}::is_valid($rule, $userid)`.

### 3.1 `user_profile_field` (the only type evaluated at runtime)

```json
{
  "filtertype": "user_profile_field",
  "userprofilefield": "contractstart",
  "operator": "since",
  "value": "",
  "date": 1748620600,
  "key": "role"
}
```

| Key | Type | Allowed values | Meaning |
|---|---|---|---|
| `filtertype` | string | `user_profile_field` | selects `local\filters\types\user_profile_field` |
| `userprofilefield` | string | shortname of a `user_info_field` (any except `idnumber`) | the profile field compared |
| `operator` | string | see table below | comparison |
| `value` | string | free text; for `isin`/`isnotin` a **semicolon-separated** list (`"XY;YY"`); for `nowminusdays`/`nowplusdays` a number of days | right-hand side of the comparison (ignored for `since`/`before`, see `date`) |
| `date` | int unix | present when the form was saved with `operator = since` (date selector) | right-hand side for `since`/`before` |
| `key` | string | always `"role"` (hard-coded by `form\filters\filter::set_data_to_persist()`) | only used when the profile value is a JSON array of objects: the property read from each item (`$item->role`). Fixtures also use `"since"`. |

Operators (`local\operators\string_compare_operators::validate($profilevalue, $rulevalue, $operator)`):

| `operator` | UI label | Semantics (profile value ⟷ rule value) |
|---|---|---|
| `equals` | equals | `$profile === $rule` (strict string compare) |
| `not_equals` | does not equal | `!==` |
| `contains` | contains | `str_contains($profile, $rule)` |
| `containsnot` | does not contain | negation |
| `isin` | list contains | `$profile ∈ explode(';', $rule)` |
| `isnotin` | list does not contain | negation |
| `since` | since | `$rule <= $profile` (rule value taken from `date`; boundary included). Only allowed on `datetime` profile fields (form validation). **Not** in `get_operator_keys()` — see D-11. |
| `before` | before | `$rule >= $profile` (rule value from `date`) |
| `nowminusdays` | before now minus value in days | `time() - $rule * 86400 >= $profile` — the profile date is at least N days in the past. Rules containing this operator are re-evaluated daily by `task\reschedule_rules` |
| `nowplusdays` | before now plus value in days | `time() + $rule * 86400 >= $profile` |

Profile values that decode to a JSON array/object are iterated and `$item->{key}` is compared; if the rule value
looks like a timestamp (numeric between 2000-01-01 and 3000-01-01) the numeric operators `equals`/`bigger`/`smaller`
are used instead. Only the first element is evaluated (D-12).

### 3.2 `user_field` (form only)

```json
{ "filtertype": "user_field", "userfield": "firstaccess", "operator": "equals", "value": "0", "key": "role" }
```

`userfield` ∈ `firstaccess` | `lastaccess`. The form persists it, but `classes/local/filters/types/user_field.php`
does not exist → the filter always passes (D-10). Do not rely on it.

### 3.3 Special case: rules for a specific user

When step 1 has `targettype = user_target` the filter step shows no inputs and `filter` stays `[]`; the row's
`userid` is the whole scope.

---

## 4. Target JSON

`rule.actions[0].targets` is an ordered array (order = execution order for `completebeforenext` chains):

```json
{
  "targettype": "bookingoption",
  "targetid": 5386,
  "completebeforenext": 0,
  "sortorder": 2,
  "targetname": "Fire safety training",
  "actiontype": "enroll"
}
```

| Key | Type | Allowed values | Set by | Meaning |
|---|---|---|---|---|
| `targettype` | string | `moodlecourse` \| `competency` \| `bookingoption` (`bookingoption` only offered when `mod_booking` is installed; runtime also accepts legacy `course` in `enroll`) | form | selects `actions\targets\types\{type}`, `completion_process\types\{type}`, form class `form\targets\types\{type}` |
| `targetid` | int | `course.id` \| `competency.id` \| `booking_options.id` | form autocomplete `<type>_targetid[n]` | the thing to complete |
| `completebeforenext` | 0 \| 1 | default 0 | checkbox "This target must be completed before user can continue with targets below" | `action_operator` stops enrolling after this target while its `completionstatus` is 0; the next target is enrolled by `check_and_trigger_targets()` once it completes |
| `sortorder` | int | always `2` | `target::set_data_to_persist()` | not used at runtime |
| `targetname` | string | resolved name at save time (`targets_factory::get_name()`) | form | display only (tables, `singleassignment`); runtime names are re-resolved |
| `actiontype` | string | always `enroll` (fixtures also contain `propose`, for which no class exists → `actions_factory` returns null and the target is skipped) | form | `actions_factory::instance()` → `actions\types\{actiontype}` |
| `duedate` | object `{fixeddate: int\|null, duration: int\|null}` | optional; only written when the step contains `duedatetype[n]` — the current form has no such element, so it is normally absent | legacy | not used at runtime ("We currently don't use the target due date") |

Runtime-only key added when the target is copied into an assignment (see section 9): `completionstatus`.

What each type does at execution/completion time is summarised in
[ARCHITECTURE_OVERVIEW.md §5–6](ARCHITECTURE_OVERVIEW.md#5-actions-and-targets) and, for admins, in
[Targets](../user/rules/03-targets.md).

---

## 5. Messages JSON (rule side)

```json
"messages": [ { "messageid": 12 }, { "messageid": 15 } ]
```

| Key | Type | Meaning |
|---|---|---|
| `messageid` | int | `local_taskflow_messages.id` of a template. The rule stores only the reference; class, recipients and timing come from the template (section 6). |

Fixtures written by hand sometimes carry `messagetype`/`messageclass` alongside; the runtime reads only `messageid`
(`action_operator::check_and_trigger_actions()`, `scheduling_event_messages::get_rule_messageids()`, placeholder
`targets` locates "the action that contains this message id").

In the form, choosing a **message package** (a tag of the tag collection "Taskflow") pre-selects every template
carrying that tag; the package itself is not stored.

---

## 6. Message `sending_settings` JSON

Stored in `local_taskflow_messages.sending_settings`, produced by `messages_form\message_form_entity::prepare_message_from_form()`.
The template row also has `class` (derived, see below), `name`, `message` = `{"heading": "...", "body": "<html>"}`, `priority` (1 normal, 2 important, 3 warning).

```json
{
  "recipientrole": ["assignee", "supervisor"],
  "userid": 0,
  "carboncopyrole": ["ccspecificuser"],
  "ccuserid": 45,
  "senddirection": "before",
  "senddays": 10,
  "timeunit": "days",
  "sendstart": "end",
  "sendstartrequest": "",
  "eventlist": ["0", "10"],
  "sendingcondition": "always"
}
```

| Key | Type | Allowed values | Used by |
|---|---|---|---|
| `recipientrole` | string[] | `assignee`, `supervisor` (label "Supervisor Overview"; + deputies when `sendmailstodeputy`), `specificuser` (with `userid`). Fixtures also use `personaladmin` (no runtime branch → falls through to *assignee*). Required for `standard`; hidden for `request`/`chat`. | `message_recipient::get_recepient()` |
| `userid` | int | Moodle user id | recipient for `specificuser` |
| `carboncopyrole` | string[] | `assignee`, `supervisor`, `ccspecificuser` (with `ccuserid`) | `message_recipient::get_carbon_copy()`; each CC gets a separate mail with `[CC]` subject prefix |
| `ccuserid` | int | Moodle user id | CC recipient for `ccspecificuser` |
| `senddirection` | `before` \| `after` | `before` is only valid with `sendstart = end` (form validation) | `message_sending_time::calaculate_sending_time()` |
| `senddays` | int | count of `timeunit`s | offset |
| `timeunit` | `minutes` \| `hours` \| `days` | ×60 / ×3600 / ×86400 | offset |
| `sendstart` | `start` \| `end` \| `status_change` | `start` = `assignment.assigneddate`, `end` = `assignment.duedate` (no due date → not scheduled), `status_change` = on entering a status in `eventlist` | `message_sending_time`, `types\standard::is_still_valid()` |
| `sendstartrequest` | `onrequestcreated` \| `onrequestclosed` \| `""` | request-type messages only | `observer::send_schedule_request_messages` |
| `eventlist` | string[] of status ids | e.g. `["0"]` assigned, `["15"]` completed, `["7"]` partially completed, `["5"]` prolonged; options = `assignment_status_facade::get_all_wanted_stati()` (minus adapter `excludestatus`) | `scheduling_event_messages::schedule_event_messages()`, `messages_manager` |
| `sendingcondition` | `always` \| `manually` \| `automatically` \| `0` | `manually` = only when a person changed the status, `automatically` = only when the engine did, `always` = both; `0`/absent behaves like `always` | `sending_condition_facade::create()` |

Derived column `class` (`message_form_entity::set_messagetype()`): form type `standard` with empty `sendstart`
→ `onevent`; a `sendstart` containing `onrequest` → that value; otherwise the form type (`standard`, `request`,
`chat`). Runtime classes and who schedules them:

| `class` | Scheduled by | Trigger |
|---|---|---|
| `standard` | `action_operator::check_and_trigger_actions()` when the assignment is created/updated | time offset from `assigneddate`/`duedate` |
| `onevent` | `assignment_status_base::execute()`, `eventhandlers\assignment_completed` / `assignment_status_changed` via `scheduling_event_messages` | status ∈ `eventlist` |
| `request` (`onrequestcreated`/`onrequestclosed` are mapped back to form type `request`) | `observer::send_schedule_request_messages` | `request_created` → receiver; `request_treated` → requester |
| `chat` | `observer::check_and_send_assignment_message_reminder` | `new_assignment_message` |

Placeholders allowed in `heading`/`body`: `<firstname>`, `<lastname>`, `<supervisor_firstname>`,
`<supervisor_lastname>`, `<due_date>`, `<due_date_with_extension>`, `<status>` (`<status de>` / `<status en>` force a
language), `<targets>`, `<opentargets>`, `<chat>` — see [Placeholders](../user/messages/02-placeholders.md).

---

## 7. Requests JSON

```json
"requests": {
  "receiver_allowselfnotrelevant": "0",
  "receiver_allowselfextension": "not_allowed",
  "receiver_allowuploadevidence": "1"
}
```

One key `receiver_<requesttype>` per request type that is **globally enabled** (admin checkboxes
`allowselfnotrelevant`, `allowselfextension`, `allowuploadevidence`); types disabled globally are simply absent.

| Value | Meaning |
|---|---|
| `"not_allowed"` | request type disabled for this rule (button hidden on the assignment page) |
| `"0"` | requests go to the assignee's **supervisor** (`request_receivers\receivers\supervisor_receiver::ID`, + deputies when `sendmailstodeputy`) → `local_taskflow_requests.forhr = 0` |
| `"1"` | requests go to **HR** (`hr_receiver::ID`, users from setting `local_taskflow/hrusers`) → `forhr = 1` |

Read by `output\singleassignment::get_request_states()` (numeric value → button shown) and by
`requests::create()` (value → `forhr`). The key names are the `SETTINGKEY` constants of
`local\requests\request_types\types\*`.

---

## 8. Full annotated example

A unit rule for cohort/unit 12: everyone whose `contractstart` is at least 30 days ago must complete a booking
option and then a course within 4 weeks, gets a 2-week extension on first overrun (tuines
`usingprolongedstate`), is reminded 10 days before the due date, is notified when the assignment is created,
may ask the supervisor to mark it "not relevant", and must repeat the whole thing yearly.

```json
{
  "rulejson": {
    "rule": {
      "name": "Onboarding safety package",          // rulename column
      "description": "Mandatory for all new staff",  // shown in rules/requests tables and on the assignment page
      "type": null,                                   // unused; null for UI-created rules
      "enabled": 1,                                   // mirrors the isactive column
      "recursive": 1,                                 // re-saving the rule also updates existing assignments
      "inheritance": 1,                               // members of child units of unit 12 are included
      "cyclicvalidation": 1,                          // must be repeated …
      "cyclicduration": 31536000,                     // … every 365 days (reset_cyclic_assignment task)
      "activationdelay": 0,                           // 0 → initial status assigned; >0 → planned
      "timecreated": null,                            // see D-22
      "timemodified": 1756900000,
      "usermodified": 2,
      "duedatetype": "duration",                      // or "fixeddate"
      "duration": 2419200,                            // 4 weeks after assigneddate
      "fixeddate": 0,                                 // only read when duedatetype = fixeddate
      "extensionperiod": 1209600,                     // 2 weeks; automatic prolongation / <due_date_with_extension>

      "filter": [
        {
          "filtertype": "user_profile_field",
          "userprofilefield": "contractstart",        // profile field shortname (holds a unix timestamp)
          "operator": "nowminusdays",                 // re-evaluated daily by reschedule_rules
          "value": "30",                              // days
          "key": "role"                               // always written; only relevant for JSON-array values
        }
      ],

      "actions": [
        {
          "targets": [
            {
              "targettype": "bookingoption",
              "targetid": 5386,
              "completebeforenext": 1,                // course below is enrolled only after this is completed
              "sortorder": 2,
              "targetname": "Fire safety training",
              "actiontype": "enroll"
            },
            {
              "targettype": "moodlecourse",
              "targetid": 31,
              "completebeforenext": 0,
              "sortorder": 2,
              "targetname": "Safety e-learning",
              "actiontype": "enroll"
            }
          ],
          "messages": [
            { "messageid": 12 },                      // template class standard: before/end/10 days
            { "messageid": 13 }                       // template class onevent: eventlist ["0"]
          ],
          "requests": {
            "receiver_allowselfnotrelevant": "0",     // supervisor decides
            "receiver_allowselfextension": "not_allowed",
            "receiver_allowuploadevidence": "not_allowed"
          }
        }
      ]
    }
  }
}
```

(Comments are for illustration; the stored value must be plain JSON.) The accompanying row:
`unitid = 12, userid = 0, rulename = 'Onboarding safety package', isactive = 1, eventname = NULL`.

Message template 12 (`local_taskflow_messages`):

```json
{ "class": "standard", "name": "Warning 1", "priority": 2,
  "message": "{\"heading\":\"Reminder: <targets>\",\"body\":\"<p>Dear <firstname>, due on <due_date>. Open: <opentargets></p>\"}",
  "sending_settings": "{\"recipientrole\":[\"assignee\"],\"carboncopyrole\":[\"supervisor\"],\"senddirection\":\"before\",\"senddays\":10,\"timeunit\":\"days\",\"sendstart\":\"end\",\"eventlist\":[],\"sendingcondition\":0}" }
```

---

## 9. What is copied into an assignment

When the rule is applied, `assignments_controller::construct_and_process_assignment()` snapshots parts of the
JSON into `local_taskflow_assignment`:

| Assignment column | Source | Runtime additions |
|---|---|---|
| `targets` (text, JSON array) | `rule.actions[last].targets` | each target gets `completionstatus` (0/1) written by `completion_operator::get_assignment_status()`; `col_targets`, `<opentargets>` and `check_and_trigger_targets()` read it |
| `messages` (text, JSON array) | `rule.actions[last].messages` | none |
| `duedate` | `duedatetype` + `duration`/`fixeddate` (+ `extensionperiod` on prolongation) | recomputed only for new assignments or when `recursive` |
| `status`, `active` | `activationdelay` → planned/assigned; then the status machine | — |
| `ruleid`, `unitid`, `userid` | row `id`, row `unitid`, the affected user | — |
| `keepchanges` | never from the rule; set by the edit forms | protects `duedate`/`active` from engine writes |

Assignments always re-read the *current* rule JSON for everything else (`assignment::rulejson` is joined in
`set_from_sql()`; `assignmentrule` joins it for message scheduling). Changing a rule therefore changes
behaviour of existing assignments immediately for filters, cyclic settings, extension period and request
receivers, but not for the target list or due date unless `recursive` is set and the rule is re-saved
(user docs: [Cyclic assignments and rule changes](../user/assignments/06-cyclic-assignments.md)).

---

## 10. How the JSON is produced and read

**Writing (UI).** `editrule.php` runs the `local_multistepform` flow with `multistepform\editrulesmanager`.
Steps: `form\rules\rule` (always) and, subject to setting `includedsteps`, `form\filters\filter`,
`form\targets\target`, `form\messages\messages`, `form\requests\requests`. On persist:

```
editrulesmanager::persist()
  └─ form\rules\rule::get_data_to_persist($steps)
       └─ form\rules\types\unit_rule::get_data($steps)
            ├─ builds ruledata {id, unitid, userid, rulename, isactive}
            ├─ builds rulejson.rulejson.rule (scalar keys, section 2)
            └─ for every further step: <formclass>->set_data_to_persist($step, &$rule)
                 filter   → $rule['filter']  (each type class get_data() + key='role')
                 target   → $rule['actions'][0]['targets']
                 messages → $rule['actions'][0]['messages']
                 requests → $rule['actions'][0]['requests']
  ├─ changemanager adds 'changemanagement' (enabled/recursive + *_changed flags) to the event payload
  ├─ insert/update local_taskflow_rules
  ├─ event rule_created_updated (other.ruledata) → adhoc update_rule
  └─ purge caches changesinruleslist, changesinassignmentslist
```

**Reading back into the form.** `editrulesmanager::load_data()` decodes the row, injects `unitid`/`userid`,
and calls each step class's static `load_data_for_form($step, $rule)`; the step's
`set_data_for_dynamic_submission()` flattens arrays into indexed form elements (`filtertype[n]`,
`user_profile_field_operator[n]`, `moodlecourse_targetid[n]`, …).

**Writing (code / tests).** Insert the row directly and trigger the event:

```php
$ruleid = $DB->insert_record('local_taskflow_rules', (object)[
    'unitid' => $cohortid, 'userid' => 0, 'rulename' => 'Test rule',
    'rulejson' => json_encode($rulejson), 'isactive' => 1,
]);
$rule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid]);
\local_taskflow\event\rule_created_updated::create([
    'objectid' => $ruleid, 'context' => \context_system::instance(),
    'other' => ['ruledata' => $rule],
])->trigger();
// Assignments exist after the adhoc task update_rule has run ($this->runAdhocTasks() in tests).
```

`unit_rules::create_rule($rule)` is an alternative that deduplicates on identical `unitid` + `rulejson`.

**Runtime readers** (all decode the whole column): `rules::instance()`, `unit_rules::instance()`,
`assignment_controller`, `assignments_controller`, `filter_operator`, `action_operator`, `assignmentrule`,
`scheduling_event_messages`, `scheduling_cyclic_adhoc`, `overdue::get_extension_period()`,
`booking_migration`, `singleassignment::get_request_states()`, `requests::create()`, placeholders
`targets`/`due_date_with_extension`, `task\reschedule_rules`, `rules_table`/`requests_table` description columns.

---

## 11. Pitfalls

- Only `actions[0]` is ever written by the forms, and `assignments_controller` reads the **last** action —
  keep exactly one action.
- Empty `filter` means "everyone in scope"; a filter with an unknown `filtertype` (including `user_field`) also
  passes silently.
- `since`/`before` read the rule value from `date`, everything else from `value`.
- `isin`/`isnotin` lists are `;`-separated, not `,`.
- `nowminusdays` makes the rule part of the daily `reschedule_rules` scan — the rule is re-fired every night.
- `duedatetype` absent → `unit_rule::get_data()` writes `fixeddate` with `fixeddate = 0` → due date 0
  (no `check_assignment_status` task). Always set both keys.
- `activationdelay > 0` creates the assignment as `planned` (-1, inactive, no dates) until
  `open_planned_assignment` runs.
- `extensionperiod` only turns the first overrun into `prolonged` when `taskflowadapter_tuines/usingprolongedstate`
  is on (read with that literal component, D-15); otherwise it only feeds `<due_date_with_extension>` and the
  edit-form defaults.
- Numeric flags are compared loosely; `"1"` and `1` both work, but `true` does not (`true == '1'` is true in
  PHP, `"true"` is not).
- `unitid` in the row is a **cohort id** when `organisational_unit_option = cohort`.
