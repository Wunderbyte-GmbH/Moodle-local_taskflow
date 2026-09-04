[Back to chapter overview](README.md)

# Standard adapter (`taskflowadapter_standard`)

## Purpose

The Standard adapter is the default adapter of Taskflow and the base for the KSW adapter. It imports a **flat** HR export — one JSON object per person — where the organisational position is a single path string (e.g. `Hospital\Clinical Area A\Neonatology`). From this it builds nested organisational units (cohorts), makes the person a member of the deepest unit, resolves the supervisor, and pauses assignments or suspends the account when the contract has ended or the person is on long leave.

It is the right choice when your HR system can deliver a simple list of persons with a department path and a manager id. Select label in the settings: **Standard API**.

> **Note:** The adapter contains one piece of customer-specific logic — the BLS certificate observer (see [Tasks and events](#tasks-and-events)). It only does something if you configure a certificate template id, so it is harmless on other sites.

---

## How to activate

1. *Site administration → Plugins → Local plugins → Taskflow* (`/admin/settings.php?section=local_taskflow_settings`).
2. **External api with user data** (`local_taskflow/external_api_option`) → **Standard API**.
3. **Organisational unit option** (`local_taskflow/organisational_unit_option`) → `cohort` (recommended; with `unit` only Taskflow's own unit table is filled and no cohorts are created).
4. **Supervisor field** (`local_taskflow/supervisor_field`) → shortname of the profile field that will hold the supervisor's Moodle id (the same field you give the function *Supervisor* below).
5. **Supervisor role** (`local_taskflow/supervisorrole`) → the role supervisors should receive.
6. Fill in the section **Standard API Settings** (below). Save.

---

## Admin settings ("Standard API Settings")

All keys live under component `taskflowadapter_standard`.

| Key | UI label | Type | Default | Meaning |
|---|---|---|---|---|
| `translator_user_firstname` | JSON key for userprofilefield: First name | text | empty | JSON key holding the first name |
| `translator_user_lastname` | JSON key for userprofilefield: Last name | text | empty | JSON key holding the last name |
| `translator_user_email` | JSON key for userprofilefield: Email | text | empty | JSON key holding the e-mail address (also used to find existing users) |
| `translator_user_<shortname>` (one per custom profile field) | JSON key for userprofilefield: <field name> | text | empty | JSON key written into this profile field |
| `<shortname>` (one per custom profile field) | Assign function to userprofilefield: <field name> | select | No function | Function of the field, see [mapping model](README.md#the-mapping-model-identical-for-all-adapters) |
| `translator_target_group_name`, `translator_target_group_description`, `translator_target_group_unitid` | JSON key for userprofilefield: Name / Description / Organisational unit | text | empty | Unit-record keys; **not used** by the Standard import (units come from the org path) |
| `necessaryuserprofilefields` | User profile fields required to be filled in for Taskflow | multiselect of profile fields | none | When a Moodle user is created or edited, the Taskflow sync for that user runs only if all selected fields are non-empty. Leave empty to always sync. |
| `blscertificatekey` | Certificate ID for BLS qualification | text | empty | Id of a `tool_certificate` template that is issued when an assignment of a rule whose name contains "BLS" is completed. Leave empty if not used. |
| `excludestatus` | — (not shown in the UI) | comma list | empty | Status ids to hide in "Change status" selects; can only be set via CLI/`set_config` for this adapter |

> **Note:** Selecting more than one field in **User profile fields required to be filled in for Taskflow** currently disables the user-event sync entirely (the check never passes). Use at most one field, or leave the setting empty.

---

## Profile-field mapping

Reference mapping (the one used by the plugin's own tests; adapt shortnames and keys to your site):

| Function | Meaning | Profile field (example shortname) | Typical JSON key |
|---|---|---|---|
| external ID | HR person id; matching key for existing users and for `Manager_Id` | `externalid` | `userID` |
| Organisational unit | Path string, split on `\` into `Org1..OrgN` | `orgunit` | `Organisation` |
| Supervisor | Filled by the import with the supervisor's Moodle id | `supervisor` | *(leave JSON key empty)* |
| Supervisor (external Moodle id) | Supervisor's HR person id | `supervisor_external` | `Manager_Id` |
| Contract end | Exit date | `contractend` | `ExitDate` |
| Contract start | Entry date (for filters) | `contractstart` | `EntryDate` |
| Long Leave | Boolean absence flag | `longleave` | *(your key, must be `true`/`false`)* |
| Deputy | Deputy's Moodle id, maintained manually | `deputy` | *(leave JSON key empty)* |
| First name / Last name / Email | Core user fields | — | `Firstname`, `LastName`, `DefaultEmailAddress` |

Additionally create text profile fields **`Org1`, `Org2`, … `OrgN`** (as many levels as your deepest path has). The import writes one path level into each; only levels for which a field exists are stored. The `Org*` fields are also the source when a user is edited in Moodle and re-synced.

Any other JSON key (phone, title, department, role flags, …) can be imported into a profile field with "No function" — it is stored but Taskflow does nothing with it, except that you can use it in [rule filters](../rules/02-filters.md).

---

## External JSON import format

A **JSON array of person objects**. Only mapped keys are read; everything else is ignored. Dates may be `YYYYMMDD` integers or `YYYY-MM-DD` strings. Anonymised example with two persons (the second is the manager of the first):

```json
[
  {
    "DefaultEmailAddress": "anna.example@example.org",
    "userID": 300101,
    "PersonnelNumber": 300101,
    "Firstname": "Anna",
    "LastName": "Example",
    "Phone": "+41 00 000 00 01",
    "Titel": "Senior physician",
    "Department": "Centre for Paediatrics",
    "Manager_Id": "300102",
    "Manager_Email": "max.sample@example.org",
    "EntryDate": 20100101,
    "ExitDate": 20371231,
    "Organisation": "Hospital\\Clinical Area A\\Neonatology",
    "Org1": "Hospital",
    "Org2": "Clinical Area A",
    "Org3": "Neonatology",
    "IsTrainee": 0,
    "IsExternal": 0
  },
  {
    "DefaultEmailAddress": "max.sample@example.org",
    "userID": 300102,
    "PersonnelNumber": 300102,
    "Firstname": "Max",
    "LastName": "Sample",
    "Phone": "+41 00 000 00 02",
    "Titel": "Head physician",
    "Department": "Clinic management",
    "Manager_Id": "",
    "Manager_Email": "",
    "EntryDate": 20090101,
    "ExitDate": 20351231,
    "Organisation": "Hospital",
    "Org1": "Hospital",
    "Org2": "",
    "IsTrainee": 0,
    "IsExternal": 0
  }
]
```

Notes on the format:

- `Organisation` uses a backslash as separator; in JSON it must be escaped as `\\`.
- `Org1..OrgN` in the file are optional — the import derives them from `Organisation` itself when the field with function *Organisational unit* is mapped. If you map `Org1..OrgN` directly, make sure both agree.
- `Manager_Id` must contain the `userID` of another person who is either in the same file or already exists in Moodle with that external id.
- An open-ended contract is expressed with a far-future `ExitDate` (e.g. `20991231`).
- Validation problems (empty first/last name or e-mail, broken UTF-8, non-numeric supervisor, non-boolean long-leave value) are logged as **Upload error** events in the site log (*Site administration → Reports → Logs*, component Taskflow) and the person is imported with the remaining values.

---

## Import and sync behaviour

The import runs on every uploaded JSON and — for the persons concerned — on every Moodle user create/update event (see [Units and users](../units_and_users/README.md)). Rules, in order of processing:

1. **Users.** A person is matched by external id (profile field with function *external ID*, which is also used as Moodle username), then by e-mail. Missing users are created; changed first name, last name, e-mail or phone are updated.
2. **Units.** The org path `Level1\Level2\…` creates one unit per level, each with the previous level as parent. With `organisational_unit_option = cohort` every unit is a cohort and the user is added to the cohort of the **deepest** level. A unit-member record is written for that unit.
3. **Supervisors.** If a *Supervisor (external Moodle id)* value is present, the user with that external id is looked up and their Moodle id is written into the *Supervisor* field. Then the supervisor receives the **Supervisor role** in the system context (only if the supervisor's account is confirmed, not suspended and not `nologin`).
4. **Contract end.** If the *Contract end* date is in the past: all assignments of the user are set to **paused** (inactive) and the Moodle account is **suspended** (site administrators are never suspended). Assignments are not reactivated by this adapter when the date moves into the future again; use the edit form or a rule re-run.
5. **Long leave.** If the *Long Leave* value is true: all assignments are set to **paused**; the account stays active. Unit membership is not changed.
6. **Unit change.** Units that were in the user's previous path but are not in the new one: all assignments of the user for those units are set to **droppedout** and the unit-member record becomes inactive. **Cohort membership is kept** by this adapter (KSW removes it). Units newly in the path are joined; rules on them create new assignments through the normal rule engine.
7. **Missing persons.** A person that is no longer in the file is **not** touched. Suspend or remove such users manually or via the contract end date.
8. **Suspension.** Suspending a user manually does not change assignments. The import suspends only via contract end.

Every run also logs an "Invalid date format" **Upload error** event per user with a contract end date. This is a known cosmetic issue; the date is processed correctly.

---

## UI differences

### Dashboards (`/local/taskflow/index.php`)

- **Admin dashboard** (users with `local/taskflow:editassignment` or listed as HR users): card **Detailview** with the `assignmentsdashboard` shortcode (columns Full name, Targets, Rule name, Status, Created, Modified, Actions), card **Applications** (requests; shows "No applications" when empty), card **Overview** with the status chart.
- **Supervisor dashboard** (users with `local/taskflow:issupervisor`): card **Pending Approvals** (mod_booking list of bookings to approve, reduced), **Applications** (requests with deputy selector), **Bookings of your Teams** (mod_booking supervisor team list), the supervisor assignments table (Full name, Targets, Due date, Status, Actions, with deputy selector) and its chart.

See [Dashboard](../dashboard/README.md) for the table itself.

### Edit-assignment page (`/local/taskflow/editassignment.php?id=<assignmentid>`)

One form for admins and supervisors. Above the form: Full name, Targets (each with "completed" / "not completed"), Name and Description of the rule, Assignment date, Status, Last modified by. Below: the history list.

| Field | UI label | Type | Default | Meaning |
|---|---|---|---|---|
| `status` | Change status | select | current status | New status. All statuses except those in `excludestatus`. |
| `change_reason` | Reason | select | Sickness | Sickness / Holidays / Other. Stored in the history entry. |
| `comment` | Comment | textarea | empty | Free text, stored in the history as annotation. |
| `duedate` | Due date | date selector | today + rule extension period (if defined), else today | New due date. |
| `keepchanges` | Keep changes of the date on import of data | checkbox | checked | When set, the next import does not overwrite the manually set due date / active flag. See [Due dates](../assignments/05-due-dates-prolongation-overdue.md). |

Submitting writes a **manual change** history entry ("<status>: <comment>") and updates the assignment; status-specific logic (e.g. due-date extension for *prolonged*) is applied. The form is shown to users with `local/taskflow:viewassignment`.

### Single-assignment page

Standard uses the core template of `/local/taskflow/assignment.php?id=…` (see [Assignment detail page](../assignments/02-assignment-detail-page.md)).

### Strings

Overrides of core wording while Standard is active (English): banner **"There are open standard trainings to complete."** (`assignmentsavailablemy`, link to the dashboard) and **"There are open clarification cases to be resolved in the standard trainings."** (`assignmentsavailablesupervisor`). In German the supervisor overview title becomes "Vorgesetzte_r Überblick".

---

## Tasks and events

- **Scheduled tasks:** none of its own. The import runs when triggered (upload form on `/local/taskflow/view.php`, user events).
- **Events:** `upload_error` (core) for validation problems during import.
- **Observer — BLS certificate:** on `assignment_completed`, if the completed assignment belongs to a rule whose **name contains "BLS"**, a certificate from the `tool_certificate` template configured in **Certificate ID for BLS qualification** is issued to the assignee. Requires the *Custom certificate* tool (`tool_certificate`) to be installed. Active whenever the Standard adapter is installed, even if another adapter is selected.

---

## Typical admin problems

| Symptom | Check |
|---|---|
| Supervisor is not set / supervisor dashboard empty | Is a profile field mapped to **Supervisor** *and* is the same shortname entered in the core setting **Supervisor field**? Is **Supervisor (external Moodle id)** mapped to the JSON key that carries the manager's id, and **external ID** mapped so the id can be resolved? Does the manager exist (in the file or already in Moodle)? |
| Import stops with an error mentioning a user info field | **Supervisor (external Moodle id)** is mapped but **external ID** is not. Map the external id field. |
| No cohorts are created | `local_taskflow/organisational_unit_option` is `unit`, or no field has the function **Organisational unit**. |
| Units only one level deep | The path separator in the JSON is not a backslash, or the string was not escaped (`\\`) in JSON. |
| Profile changes in Moodle do not re-sync the user | More than one field selected in **User profile fields required to be filled in for Taskflow**, or the selected field is empty for that user. |
| Assignments stay paused after the contract was extended | Standard does not reactivate on its own. Change the status on the edit-assignment page, or remove and re-add the user from the unit/rule. |
| Site log full of "Invalid date format" upload errors | Known cosmetic issue for users with a contract end date; the date itself is processed. |
| Many users suddenly suspended after an import | Their `ExitDate` is in the past. Check the export; suspension follows the date, not presence in the file. |
| Certificate not issued on completion | Rule name must contain "BLS", **Certificate ID for BLS qualification** must hold a valid template id, `tool_certificate` must be installed. |

---

## Related

- [Adapters overview](README.md) · [KSW adapter](ksw.md) (same format, more sync features)
- [Units and users](../units_and_users/README.md) · [Settings](../settings/README.md)
- [Edit assignment](../assignments/03-edit-assignment.md) · [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md)
- [Filters](../rules/02-filters.md) — using imported profile fields (e.g. contract start) in rules
