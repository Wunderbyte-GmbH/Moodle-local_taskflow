[Back to chapter overview](README.md)

# INES adapter (`taskflowadapter_tuines`)

## Purpose

The INES adapter was built for a university HR project (TU Wien "INES"). It differs from the other adapters in three ways:

1. **Data source.** It fetches a **nested JSON** document (`persons` + `targetGroups`) from a data-warehouse URL every night at 03:00 (or on demand) instead of receiving a flat upload. Persons carry an array of *target group* ids; each target group becomes a cohort.
2. **Security check.** Persons that are **missing from the feed** are suspended, removed from all cohorts, logged out and all their assignments set to *droppedout*; persons that reappear are un-suspended.
3. **Assignment workflow.** Supervisors decide on **deadline extensions** on a dedicated form (Grant / Deny); the first automatic overshoot of a due date can be recorded as **prolonged** instead of *overdue*; statuses can be excluded; an **Internal Chat** per assignment and branded chat notification mails exist.

Plugin name shown in Moodle: **INES**; select label: **Ines API**. Read [standard.md](standard.md) for the general idea of the mapping — the mapping UI is the same.

---

## How to activate

1. Create the profile fields (text): `externalid`, `units`, `organisation`, `supervisor`, `longleave`, `contractend`, `contractstart`, `deputy` (shortnames are your choice).
2. *Site administration → Plugins → Local plugins → Taskflow* (`/admin/settings.php?section=local_taskflow_settings`).
3. **External api with user data** (`local_taskflow/external_api_option`) → **Ines API**.
4. **Organisational unit option** → `cohort`. **Supervisor role** → the role for supervisors. **Supervisor field** → shortname of your *Supervisor* field.
5. Section **INES API Settings**: mapping (below), **Data Warehouse Url**, **Use prolonged state**, **Do not use status**. Save.
6. Check *Site administration → Server → Scheduled tasks* for **Fetch remote data** (default 03:00), or trigger a first import: `/local/taskflow/view.php` → **Trigger DWH import** (needs `moodle/site:config`). The result text of the task is shown on the page.

---

## Admin settings ("INES API Settings")

All keys under component `taskflowadapter_tuines`.

| Key | UI label | Type | Default | Meaning |
|---|---|---|---|---|
| `translator_user_firstname` / `_lastname` / `_email` | JSON key for userprofilefield: First name / Last name / Email | text | empty | Keys inside a `persons` record |
| `translator_user_<shortname>` | JSON key for userprofilefield: <field name> | text | empty | Key (or `->`-separated path) inside a `persons` record written into this profile field |
| `<shortname>` | Assign function to userprofilefield: <field name> | select | No function | Function of the field |
| `translator_target_group_name` | JSON key for userprofilefield: Name | text | empty | Key inside a `targetGroups` record → cohort name |
| `translator_target_group_description` | JSON key for userprofilefield: Description | text | empty | → cohort description |
| `translator_target_group_unitid` | JSON key for userprofilefield: Organisational unit | text | empty | → cohort id number; the value the persons' `targetGroup` array refers to |
| `usingprolongedstate` | Use prolonged state | checkbox | off | "Use prolonged state to mark first automated expansion of due date": when an assignment passes its due date for the first time and the rule has an extension period, the status becomes **prolonged** and the due date is extended by the extension period; only the second overshoot becomes **overdue**. Off: due date passed → **overdue** immediately. |
| `excludestatus` | Do not use status | multiselect of statuses | none | "Status changes to the following statuses will not be executed." Excluded statuses are removed from "Change status" selects and automatic transitions into them are blocked. The reference configuration excludes **enrolled** (3) and **partially completed** (7), so partial completion shows as *assigned*. |
| `dwhurl` | Data Warehouse Url | text (URL) | empty | Endpoint fetched by the **Fetch remote data** task with a plain HTTP GET (no authentication headers). |
| `necessaryuserprofilefields` | — | — | — | Not shown for INES: the adapter never reacts to Moodle user events. |

The section headings **Fields for User** and **Fields for Units** separate the person mapping from the target-group mapping.

---

## Profile-field mapping

Reference configuration (as used by the plugin's tests):

| Function | Meaning | Profile field (example shortname) | JSON key |
|---|---|---|---|
| external ID | Person id of the HR system (at TU Wien the TISS id); matching key; also used for the person link in tables | `externalid` | `tissId` |
| Target group | Array of target-group ids → cohort memberships | `units` | `targetGroup` |
| Organisational unit | Org-unit codes (informational; stored, not used for cohorts) | `organisation` | `orgUnit` |
| Supervisor | External id of the supervisor in the feed; replaced by the supervisor's Moodle id after import | `supervisor` | `directSupervisor` |
| Long Leave | Boolean long-term absence | `longleave` | `currentlyOnLongLeave` |
| Contract end | Date `YYYY-MM-DD`; `9999-12-31` = open-ended | `contractend` | `contractEnd` |
| Contract start | Date; for rule filters | `contractstart` | `contractStart` |
| Deputy | Maintained manually in Moodle | `deputy` | *(empty)* |
| First name / Last name / Email | Core fields | — | `firstName`, `lastName`, `eMailAddress` |
| Target group Name / Description / Organisational unit | Cohort name / description / id number | — | `displayNameDE`, `descriptionDE`, `number` |

The **Supervisor** function here receives the *external* id directly (no separate "Supervisor (external Moodle id)" field is needed); the import resolves it to the Moodle id.

---

## External JSON import format

One JSON **object** with `persons` and `targetGroups` arrays (an optional `comment` array is ignored). Anonymised example:

```json
{
  "persons": [
    {
      "tissId": 500001,
      "firstName": "Anna",
      "middleName": null,
      "lastName": "Example",
      "eMailAddress": "anna.example@example.org",
      "orgUnit": ["E230-00", "E230-01"],
      "mainOrgUnit": "E230-00",
      "headOfOrgUnit": ["E230-00"],
      "workingHours": 40,
      "contractStart": "2019-03-01",
      "contractEnd": "9999-12-31",
      "currentlyOnLongLeave": false,
      "directSupervisor": 500002,
      "targetGroup": [102]
    },
    {
      "tissId": 500002,
      "firstName": "Max",
      "middleName": null,
      "lastName": "Sample",
      "eMailAddress": "max.sample@example.org",
      "orgUnit": ["E230-01"],
      "mainOrgUnit": "E230-01",
      "headOfOrgUnit": [null],
      "workingHours": 10,
      "contractEnd": "2027-06-30",
      "currentlyOnLongLeave": true,
      "directSupervisor": null,
      "targetGroup": [101, 103]
    }
  ],
  "targetGroups": [
    {
      "number": 101,
      "displayNameDE": "beschäftigt bis 6 Monate und/oder 10 Stunden",
      "displayNameEN": "employed up to 6 months and/or 10 hours",
      "descriptionDE": "…",
      "descriptionEN": "…"
    },
    {
      "number": 102,
      "displayNameDE": "beschäftigt über 6 Monate und 10 Stunden",
      "displayNameEN": "employed for more than 6 months and 10 hours",
      "descriptionDE": "…",
      "descriptionEN": "…"
    },
    {
      "number": 103,
      "displayNameDE": "Führungskraft",
      "displayNameEN": "manager",
      "descriptionDE": "…",
      "descriptionEN": "…"
    }
  ]
}
```

Format rules:

- `targetGroup` must be an **array of numbers** matching `targetGroups[].number`; an empty array `[]` means "member of no unit" (all existing assignments drop out).
- `currentlyOnLongLeave` must be a real boolean (`true`/`false`, not the string `"false"`).
- `directSupervisor` is the `tissId` of another person (or `null`).
- `contractEnd` / `contractStart` must be parseable dates (`YYYY-MM-DD`); `01.04.2026`-style strings fail.
- Type errors (string ids, missing names/e-mail, broken UTF-8) are logged as **Upload error** events; the person is skipped where mandatory values are invalid.
- A response without a non-empty `persons` array is rejected as "The DWH response was empty or invalid" and nothing is changed.

---

## Import and sync behaviour

Order per run: units → users → supervisors → profile save → security check → rule re-evaluation.

1. **Units.** Every `targetGroups` entry becomes a cohort (name = mapped Name, id number = mapped unit id) or is updated.
2. **Users.** A person is matched by external id, then username, then e-mail; created if missing; first name, last name, e-mail, phone updated when changed (an e-mail change in the feed updates the existing account, it does not create a new one). Profile fields are written; the *Target group* field stores the array of unit ids as JSON.
3. **Unit change.** For an existing user, units removed from the array: cohort membership removed, unit-member record removed, assignments for those units → **droppedout** (inactive, due date cleared, sent-message records flushed). Units added: cohort membership added, existing assignments for those units → **assigned** with counters reset, assigned date = now, due date = now + rule duration; the assigned mail is sent again. A **completed** assignment only toggles its active flag and is restored as completed.
4. **Contract end / long leave (pause).** If the contract end is in the past **or** long leave is true: unit memberships become inactive and all assignments → **paused** (inactive, due date cleared, no reminder mails). A person imported *for the first time* while on long leave gets no assignments until the flag is false.
5. **Return (reactivation).** If long leave switches from true to false, or the contract end moves from the past into the future while not on leave: memberships reactivated, **paused → assigned** with counters reset and due date = now + full rule duration, sent-message records removed (reminder mails start again), rules re-evaluated. Completed assignments stay completed.
6. **Supervisors.** The external id in the *Supervisor* field is resolved to a Moodle user; their id is stored and they receive the **Supervisor role**. After the run the adhoc task **check_supervisor** removes the role from users that are no longer referenced as supervisor by any non-suspended user.
7. **Missing persons (security check).** Every Moodle user with a non-empty *external ID* profile field who is **not** in the current feed is: suspended, emptied of target groups, removed from **all** cohorts, logged out of all sessions, and all assignments → **droppedout**. Site administrators are never suspended. Users in the feed who are suspended are un-suspended (this applies to every suspended account on the site that is not a missing person). Dropped-out assignments are not automatically reopened when the person reappears; rules on their (new) units create new assignments.
8. **Manual suspension.** Suspending a user by hand does not change their assignments; only contract end, long leave and missing-from-feed do. Deleting a user leaves the assignment inactive; a later import creates a new account and new assignments.
9. **Re-imports.** Importing unchanged data never touches status, counters, history or the modification time of assignments; manual changes with **keepchanges** survive imports (the due-date engine still moves *assigned/prolonged* to *overdue* when the date passes).
10. **Moodle user events** never trigger a sync for INES (unlike standard/ksw).

> **Note:** The security check treats the whole feed as authoritative. A partial export (e.g. only one faculty) would suspend everybody else. Make sure the DWH always delivers the complete staff list.

### Prolonged state and the extension limit

With **Use prolonged state** on and a rule **extension period** > 0: first due-date overshoot → **prolonged**, `prolongedcounter` 1, due date + extension period; second overshoot → **overdue**, `overduecounter` 1. A supervisor may then **grant** (→ prolonged again, counter 2) or **deny** (status unchanged, counter 2). The supervisor form is offered only while `prolongedcounter < 2`; an assignment that is *overdue* with `overduecounter` 1 and `prolongedcounter` 2 is a **clarification case** and shows the supervisor banner "There are open clarification cases in the standard trainings to be resolved." Details: [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md).

---

## UI differences

### Assignments table (all dashboards and shortcodes)

- **Full name** links to the person's page in the external HR system: `https://tiss.tuwien.ac.at/person/<externalid>.html` (hard-coded host).
- **Actions**: **Go to training** → `/local/taskflow/assignment.php?id=…`; **Edit** → `/local/taskflow/editassignment.php?id=…` for users with `local/taskflow:editassignment`, or for the assignee's supervisor when `overduecounter ≤ 1` and `prolongedcounter == 1` (i.e. after exactly one prolongation).
- **Targets**: each target with "( completed)" / "( Not completed)".
- **Comment** column: date/time and text of the latest manual-change comment (shortened to 200 characters).
- Table headings ("My assignments", "Assignments", …) are suppressed for INES; use the shortcode `description` argument instead.

### Edit-assignment page — admin variant (`/local/taskflow/editassignment.php?id=…`)

Shown to users with `local/taskflow:editassignment` (or anyone who is not the supervisor and has `local/taskflow:viewassignment`). Header facts: Full name, Due date, Status, **Assigned Packages** (targets with completion). Then three forms and the history list:

**Form "Change status"**

| Field | UI label | Type | Default | Meaning |
|---|---|---|---|---|
| `status` | Change status | select (required) | current | Any status not in **Do not use status** |
| `change_reason` | Reason | select | Holidays | Holidays / Other / Sickness |
| `comment` | Comment | textarea | empty | Logged in the history |
| `duedate` | Extension until | date selector | today + rule extension period, else today | Set to 23:59 of the chosen day |
| `keepchanges` | (hidden) | — | 1 | Always kept: the next import will not overwrite the manual due date / active flag |

Choosing *prolonged* or *overdue* first applies that status type's logic (counters, extension), then the change is saved and a **manual change** history entry is written.

**Form "Save Comment"** — a comment-history table (Date, Comment as "reason; text", Last modified by; 5 rows per page) plus a **Comment** textarea and button **Save Comment**. Adds a history entry only; the assignment itself is not changed.

**Internal Chat** — shown when **Allow internal communication** (`local_taskflow/allowinternalcommunication`) is on: chat bubbles (own messages right, others left), a message box and **Send message**. Messages are stored per assignment and announced by the daily digest mail (see below and [Internal communication](../messages/03-internal-communication.md)).

### Edit-assignment page — supervisor variant

Shown to the assignee's supervisor (without `local/taskflow:editassignment`) while `prolongedcounter < 2`. Header facts: Full name, Status, Due date, Assigned Packages. No history, no comment form. Two collapsed sections:

| Section | Fields | Effect on submit |
|---|---|---|
| **Grant Extension** | Reason (select, required), Comment (`comment_approved`), **Extension until** (read-only date: current due date + rule extension period if the due date is in the future, else today 23:59 + extension period), button **Grant Extension** | Status → **prolonged**, due date = shown date, `prolongedcounter` +1, history "Request confirmed: <comment>" |
| **Deny Extension** | Comment (`comment_denied`, required), button **Deny Extension** | Status and due date unchanged, `prolongedcounter` +1, history "Request denied: <comment>" |

Validation messages: "If the deadline is extended, a reason must be selected." / "If the deadline extension is denied, no reason may be selected." / "When denying the extension request, a comment must be provided". Both sections display the same explanatory text about consequences of denial (the wording that the deadline may be extended "once" is not currently displayed).

### Single-assignment page (`/local/taskflow/assignment.php?id=…`)

INES ships its own template: **Back** button; card "Assignment to <name>" with profile picture, rule description, "Due date until – <date>", "Contact supervisor in case of problems" (mailto link); per target "(completed | Not completed)"; if evidence upload is allowed (`local_taskflow/allowuploadevidence`) a button **Upload evidence**, or the evidence card with Status / Edit / Delete buttons; for competency targets one card **Possible courses: <target>** with the bookable options; and the **Internal Chat** card when enabled. See [Competencies and certificates](../competencies_and_certificates/README.md).

### Strings (override core wording)

**Go to training**, **Edit**, **Assigned Packages**, **Save Comment**, **Grant Extension**, **Deny Extension**, **Extension until**, banners "There are open standard trainings to complete." / "There are open clarification cases in the standard trainings to be resolved." (German: "Standardschulungen", "Klärungsfälle", "Zur Schulung").

**Chat notification mail** (sent by the core task `notification_internal_messages`): subject "ines - You have new chat messages", greeting "Hello {firstname} {lastname},", "You have received new chat messages:", signature "Kind regards, E068 - HR Development" and a footer naming the HR development contact address. These texts are customer-specific; change them via *Language customisation* for component `taskflowadapter_tuines` if you reuse the adapter.

---

## Tasks and events

| Item | Name | When | What it does |
|---|---|---|---|
| Scheduled task | **Fetch remote data** (`\taskflowadapter_tuines\task\fetch_dwh_data`) | daily 03:00 (configurable) | GET **Data Warehouse Url** → full import as described above. Returns "Fetched and processed data from <url>. Execution time: N seconds…" or an error text. |
| Manual trigger | **Trigger DWH import** button on `/local/taskflow/view.php` | on click | Runs the same task synchronously and prints its result. Requires `moodle/site:config`. |
| Adhoc task | `unit_updated` (core) | after each import, per unit | Re-evaluates all rules of the unit |
| Adhoc task | `check_supervisor` (core) | after each import | Revokes the supervisor role from users no longer referenced |
| Event | **DWH import error** (`dwh_fetch_failed`) | URL empty, curl error, empty/invalid response | Visible in the site log with URL and error text |
| Event | `upload_error` (core) | validation problems | Site log |
| Observer | `user_info_field_deleted` | a custom profile field is deleted | Removes that field's function setting from the INES mapping (the JSON-key setting remains and can be ignored) |
| Cache | Comment historylist | — | Purge via *Site administration → Development → Purge caches* if the comment table looks stale |

See [Scheduled tasks](../scheduled_tasks/README.md).

---

## Typical admin problems

| Symptom | Check |
|---|---|
| Nightly import does nothing; log shows "DWH import error" | **Data Warehouse Url** empty or unreachable from the Moodle server (plain GET, no auth — the endpoint must whitelist the server). Run **Trigger DWH import** on `/local/taskflow/view.php` to see the exact message. |
| "The DWH response was empty or invalid" | Response has no non-empty `persons` array, or is not JSON. |
| Supervisor not resolved / no supervisor role | Function **Supervisor** mapped to the key with the supervisor's external id (`directSupervisor`)? Function **external ID** mapped (`tissId`)? Is the supervisor part of the feed or already in Moodle with that id? Non-numeric value → "Invalid supervisor format" upload error. |
| Supervisor lost the role | `check_supervisor` removes it when no non-suspended user references them. |
| No cohorts / persons in no unit | **JSON key for userprofilefield: Organisational unit** (target-group id key, `number`) and the person field with function **Target group** (`targetGroup`) must both be mapped; `targetGroup` must be an array of numbers. |
| Many users suspended after an import | Feed was incomplete — every user with an external id not in the feed is a "missing person". Restore by importing a complete feed (they are un-suspended automatically; dropped-out assignments stay dropped out). |
| A manually suspended account was un-suspended | The security check un-suspends every suspended account that is in the feed (and in practice every suspended non-missing user). Use contract end instead of manual suspension. |
| Partial completion never shows "partially completed" | Status 7 is in **Do not use status** (reference config `3,7`). Remove it there if you want the status. |
| Assignment went to *prolonged* instead of *overdue* | **Use prolonged state** is on and the rule has an extension period — intended. |
| Supervisor does not see the Grant/Deny form | `prolongedcounter` is already 2 (two decisions/prolongations used), or the user is not the supervisor stored in the assignee's *Supervisor* field. Admins with `local/taskflow:viewassignment` always see it. |
| Supervisor sees "Go to training" but no **Edit** link | Edit appears for supervisors only when `overduecounter ≤ 1` and `prolongedcounter == 1`. |
| Long-leave person got no assignment | Intended: persons imported while on leave get assignments only when the flag becomes false. |
| Chat mails carry the university wording | Customise `taskflowadapter_tuines` strings `notificationmessage*` in *Language customisation*. |
| Name links point to tiss.tuwien.ac.at | Hard-coded in the INES table; not configurable. |

---

## Related

- [Adapters overview](README.md) · [Standard adapter](standard.md)
- [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md) — prolonged, paused, droppedout, counters
- [Edit assignment](../assignments/03-edit-assignment.md) · [Assignment detail page](../assignments/02-assignment-detail-page.md) · [History](../assignments/04-history.md)
- [Internal communication](../messages/03-internal-communication.md) — chat, digest mail
- [Requests](../requests/README.md) — self-service extension / not relevant / evidence requests
- [Units and users](../units_and_users/README.md) · [Settings](../settings/README.md) · [Scheduled tasks](../scheduled_tasks/README.md)
