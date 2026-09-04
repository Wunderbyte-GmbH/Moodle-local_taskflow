[Back to chapter overview](README.md)

# KSW adapter (`taskflowadapter_ksw`)

## Purpose

The KSW adapter was built for a hospital customer (Kantonsspital Winterthur). It uses **the same import format, mapping and settings as the [Standard adapter](standard.md)** and adds:

- **Protected cohorts** — manually maintained cohorts that the sync never removes users from.
- **Full unit synchronisation** — when a person moves to another unit, the old cohort membership is removed and existing assignments for the new unit are re-activated.
- **Cancellation of future bookings** when a Moodle user is suspended.
- A **Quickaccess** dropdown in the Moodle navbar.
- Slightly different dashboards (booking custom-field filters, other columns).

Select label in the settings: **KSW API**. Read [standard.md](standard.md) first; this page documents only the deltas.

---

## How to activate

1. *Site administration → Plugins → Local plugins → Taskflow* (`/admin/settings.php?section=local_taskflow_settings`).
2. **External api with user data** (`local_taskflow/external_api_option`) → **KSW API**.
3. **Organisational unit option** → `cohort`; **Supervisor role** → the supervisors' role; **Supervisor field** → the shortname of your *Supervisor* profile field (KSW resolves supervisors through the function mapping, but core features still read this setting).
4. Fill in the section **KSW API Settings**. Save.

---

## Admin settings ("KSW API Settings")

Everything from the Standard adapter (same labels, component `taskflowadapter_ksw`) plus:

| Key | UI label | Type | Default | Meaning |
|---|---|---|---|---|
| `protectedcohorts` | Protected cohorts | multiselect of all cohorts (all contexts) | none | "Members of these cohorts are never removed by the KSW user sync when a user is updated. Use this for cohorts (units) that are filled manually." |
| `necessaryuserprofilefields` | User profile fields required to be filled in for Taskflow | multiselect | none | As in Standard (same one-field limitation) |
| `blscertificatekey` | Certificate ID for BLS qualification | text | empty | Present in the KSW section but **read only by the Standard adapter's observer**; if both adapters are installed, configure it in the Standard section |
| `excludestatus` | — (not shown in the UI) | comma list | empty | As in Standard |

---

## Profile-field mapping

Identical to the [Standard mapping](standard.md#profile-field-mapping). Reference:

| Function | Profile field (example) | Typical JSON key |
|---|---|---|
| external ID | `externalid` | `userID` |
| Organisational unit | `orgunit` | `Organisation` |
| Supervisor | `supervisor` | *(empty; filled by import)* |
| Supervisor (external Moodle id) | `supervisor_external` | `Manager_Id` |
| Contract end | `contractend` | `ExitDate` |
| Contract start | `contractstart` | `EntryDate` |
| Long Leave | `longleave` | *(boolean key if delivered)* |
| Deputy | `deputy` | *(empty; manual)* |
| First name / Last name / Email | — | `Firstname`, `LastName`, `DefaultEmailAddress` |

Plus text fields `Org1`…`OrgN` for the path levels. The hospital export additionally carries role and obligation flags (e.g. course-obligation flags, system role names, trainee/external flags); map them to profile fields with "No function" if you want to filter rules on them.

> **Note:** In KSW the function **Organisational unit** and **Supervisor** must be mapped; unlike Standard, the KSW import emits PHP warnings when they are missing.

---

## External JSON import format

Same as [Standard](standard.md#external-json-import-format): a JSON array of person objects. Anonymised example showing a two-level path and the supervisor reference:

```json
[
  {
    "DefaultEmailAddress": "anna.example@example.org",
    "userID": 300101,
    "Firstname": "Anna",
    "LastName": "Example",
    "Manager_Id": "300102",
    "EntryDate": 20100101,
    "ExitDate": 20371231,
    "Organisation": "Hospital\\Management",
    "Org1": "Hospital",
    "Org2": "Management",
    "InsulinCourseMandatory": 0,
    "ResuscitationCourseMandatory": 1,
    "IsTrainee": 0,
    "IsExternal": 0
  },
  {
    "DefaultEmailAddress": "max.sample@example.org",
    "userID": 300102,
    "Firstname": "Max",
    "LastName": "Sample",
    "Manager_Id": "",
    "EntryDate": 20090101,
    "ExitDate": 20351231,
    "Organisation": "Hospital",
    "Org1": "Hospital",
    "Org2": "",
    "InsulinCourseMandatory": 1,
    "ResuscitationCourseMandatory": 1,
    "IsTrainee": 0,
    "IsExternal": 0
  }
]
```

---

## Import and sync behaviour

Same pipeline as Standard (users → units → supervisors → contract end / long leave), with these differences:

1. **Units — leaving.** Units in the old path but not in the new one, **minus the protected cohorts**: the user is removed from the cohort, the unit-member record is removed, and all assignments of the user for those units are set to **droppedout**.
2. **Units — joining / re-joining.** Units in the new path but not in the old one: the user is added to the cohort and existing assignments of the user for those units are set back to **assigned** (a **completed** assignment stays completed). Counters are reset, the assigned date is set to now and the due date is recalculated from the rule duration; sent-message records for the old run are reset so the "assigned" mail goes out again. Assignments already completed before the move are restored as they were.
3. **Protected cohorts.** Membership in a protected cohort survives every sync and every user edit, including when the org path no longer contains that unit. Use this for manually filled cohorts (course-category cohorts, project groups) that carry their own rules. Without protection, the sync removes such manual memberships on the next user update and deactivates the corresponding assignments.
4. **Supervisor.** Resolved from the profile field with function **Supervisor** (not from `local_taskflow/supervisor_field`). Otherwise as Standard.
5. **Contract end / Long leave / missing persons.** As Standard: past exit date → assignments **paused** + account suspended; long leave → **paused**; persons missing from the file are not touched.
6. **Suspension → booking cancellation.** Whenever a user is updated and is suspended (by the import through contract end or manually), all of their **booked** booking options whose course start is in the future are cancelled. Self-learning-course options are not cancelled. This is an observer and runs regardless of which adapter is selected.

Behaviour confirmed by the adapter's test suite: a manual status set on the edit form is kept through the next import for every status; moving a person between units drops out the old assignment and creates/reactivates the new one; the extension period of a rule does **not** delay the overdue status for KSW (there is no automatic *prolonged* step unless the INES setting is enabled).

---

## UI differences

### Quickaccess navbar menu

A dropdown **Quickaccess** appears in the Moodle navbar for every logged-in user (as soon as the KSW adapter is installed):

| Entry | Link | Shown to |
|---|---|---|
| My Learning Profile | `/local/taskflow#user-pane-<userid>-` | everyone |
| My Courses | `/mod/booking/mybookings.php?completed=1&filter=1&typefilter=1` | everyone |
| Supervisor Overview | `/local/taskflow/` | users with `local/taskflow:issupervisor` |
| Content Database, Training Course, Archive | `/course/view.php?id=9`, `?id=8`, `?id=29` | users holding a specific role (role id 18) in the system context |

> **Note:** The last three entries are hard-coded to course ids and a role id of the customer's site. On another site they point to whatever courses have those ids (or nothing). There is no setting to change them.

### Dashboards

- **Admin dashboard**: as Standard; the requests card is limited to 10 rows per page.
- **Supervisor dashboard**: **Pending Approvals** filtered by the booking custom field `chf`, **Bookings of your Teams** filtered by the booking custom field `typen`, requests without deputy selector, and the supervisor assignments table with columns Full name, Targets, Status, Information (no Due date / Actions column), with deputy selector.

### Edit-assignment page

KSW has no own form: `/local/taskflow/editassignment.php?id=…` uses the [Standard form](standard.md) (Change status, Reason, Comment, Due date, Keep changes of the date on import of data).

### Shortcode

`[bookingoptiondescription]` — returns the description of the booking option currently being rendered into a certificate (for use inside `tool_certificate` templates together with mod_booking). Registered by KSW; outside a certificate rendering it prints "PLACEHOLDER".

### Strings

Banners: "There are open standard trainings to complete." / "There are open clarification cases to be resolved in the standard trainings." (English version without link; German with link). Supervisor overview title: "Supervisor Overview" / "Vorgesetzte_r Überblick".

---

## Tasks and events

- **Scheduled tasks:** none.
- **Observer:** `core\event\user_updated` → if the user is suspended, cancel their future bookings (see above).
- **Shortcode:** `bookingoptiondescription`.
- **Events:** `upload_error` (core) during import.

---

## Typical admin problems

| Symptom | Check |
|---|---|
| Manually added cohort members disappear after they edit their profile | Add the cohort to **Protected cohorts**. Every user update runs the sync, which removes non-protected memberships that are not in the org path. |
| Supervisor not resolved | Function **Supervisor** must be mapped to the field that will hold the Moodle id, **Supervisor (external Moodle id)** to the manager id key, **external ID** to the person id key. The manager must exist. |
| PHP warnings "Undefined array key" during import | **Supervisor** or **Organisational unit** function not mapped. |
| Future bookings of a user were cancelled | The user got suspended (contract end in the past, or manually). This is intended KSW behaviour. |
| Quickaccess shows Content Database / Training Course / Archive pointing to wrong courses | Hard-coded course ids 9, 8, 29 for role id 18; not configurable. |
| Quickaccess visible although Standard adapter is selected | Navbar callback is active as long as the KSW plugin is installed. Uninstall the KSW adapter if you do not want it. |
| Assignment re-activated after a user re-joined a unit, but it was already completed | Completed assignments are restored as completed; only open ones restart. If you see otherwise, check the history panel of the assignment. |
| Certificate for "BLS" rules not issued | Configure **Certificate ID for BLS qualification** in the **Standard API Settings** section — the KSW copy of the setting has no consumer. |

---

## Related

- [Standard adapter](standard.md) — base behaviour, format, form
- [Adapters overview](README.md)
- [Units and users](../units_and_users/README.md) · [Settings](../settings/README.md)
- [Edit assignment](../assignments/03-edit-assignment.md) · [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md)
- [Shortcodes](../shortcodes/README.md)
