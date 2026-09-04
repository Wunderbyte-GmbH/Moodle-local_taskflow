[Back to user documentation index](../README.md)

# Adapters — Overview

An **adapter** is the part of Taskflow that understands *your* HR data. Taskflow itself only knows Moodle users, cohorts/units, supervisors, rules and assignments. The adapter is the layer in between: it reads the external personnel export (persons, organisational units, supervisors, contract dates, absences), writes it into Moodle users and user profile fields, keeps cohort memberships and supervisor roles in sync, and — because customers differ — also swaps a few pieces of the user interface (dashboards, the *Edit assignment* page, wording).

Adapters are subplugins of type `taskflowadapter`, installed under `local/taskflow/taskflowadapter/<name>/`. Exactly **one** adapter is active at a time. Three adapters exist:

| Adapter | Select label | Built for | Page |
|---|---|---|---|
| `standard` | Standard API | Generic flat HR export (one JSON object per person, organisation as a `\`-separated path) | [standard.md](standard.md) |
| `ksw` | KSW API | A hospital customer (Kantonsspital Winterthur); same import format as standard plus protected cohorts, re-activation on unit change, booking cancellation on suspension, a navbar quick-access menu | [ksw.md](ksw.md) |
| `tuines` | Ines API | A university HR project (TU Wien "INES"); nested `persons` / `targetGroups` JSON fetched nightly from a data warehouse, supervisor extension workflow, prolonged state, internal chat | [tuines.md](tuines.md) |

---

## Quick path: activate and configure an adapter

1. Make sure the custom user profile fields you need exist: *Site administration → Users → Accounts → User profile fields* (`/user/profile/index.php`). Typical fields: `externalid`, `supervisor`, `orgunit` (or `units`), `contractend`, `contractstart`, `longleave`, `deputy`.
2. Open *Site administration → Plugins → Local plugins → Taskflow* (`/admin/settings.php?section=local_taskflow_settings`).
3. Set **External api with user data** (`local_taskflow/external_api_option`) to the adapter you want. Save.
4. Set **Organisational unit option** (`local_taskflow/organisational_unit_option`) — all three adapters are normally run with `cohort`, so that every unit is a Moodle cohort.
5. Set **Supervisor role** (`local_taskflow/supervisorrole`) to the role that every detected supervisor should receive in the system context.
6. Scroll to the section of your adapter ("Standard API Settings", "KSW API Settings" or "INES API Settings") and fill in the **mapping**: for every profile field a JSON key and a function (see below). Save.
7. Run a first import (upload / trigger on `/local/taskflow/view.php`, or wait for the nightly task in the INES case) and check the result on the dashboard `/local/taskflow/index.php`.

Details per adapter are on the adapter pages. All core settings are listed in [Settings](../settings/README.md).

---

## Pages in this chapter

| Page | Content |
|---|---|
| [standard.md](standard.md) | Standard adapter: settings, flat JSON format, org path `Org1..OrgN`, contract end / long leave, BLS certificate observer, dashboards, edit-assignment form |
| [ksw.md](ksw.md) | KSW adapter: everything the standard adapter does plus protected cohorts, re-activation of assignments when a user re-joins a unit, cancellation of future bookings for suspended users, quick-access navbar |
| [tuines.md](tuines.md) | INES adapter: data-warehouse fetch at 03:00, nested JSON, target groups → cohorts, missing-person suspension, prolonged state, supervisor grant/deny extension forms, comment form, internal chat, branded chat mails |

---

## What changes when you switch the adapter

| Area | Effect of the selected adapter |
|---|---|
| **Import / user sync** | The active adapter parses the external JSON and creates/updates users, units (cohorts), unit memberships and supervisors. With `standard` and `ksw` the *same* sync also runs whenever a Moodle user is created or edited (so a manual profile change re-evaluates units, supervisor and contract end for that user). With `tuines` user edits never trigger a sync — only the import does. |
| **Field mapping** | JSON key → profile field and profile field → function are stored per adapter (`taskflowadapter_<name>/…`). Switching adapters means re-entering the mapping in the new section. |
| **Strings / wording** | Any UI string can be overridden by the active adapter. Examples: the banner "There are open standard trainings to complete.", the supervisor overview title, the action label "Go to training" (INES). |
| **Dashboards** | The admin and supervisor dashboards on `/local/taskflow/index.php` are assembled by the active adapter (which shortcodes, which columns). |
| **Edit-assignment page** | `/local/taskflow/editassignment.php?id=…` uses the adapter's form(s). Standard and KSW share one form; INES has separate admin and supervisor forms. See [Edit assignment](../assignments/03-edit-assignment.md). |
| **Status list** | The statuses offered in "Change status" selects exclude the ids configured in `taskflowadapter_<name>/excludestatus` (only INES exposes this setting in the UI). |
| **Supervisor lookup** | Standard reads the profile field named in the core setting **Supervisor field** (`local_taskflow/supervisor_field`); KSW and INES read the profile field that has the function *Supervisor*. Keep both pointing at the same field to be safe. |

> **Note:** Adapter *settings sections* are shown for every installed adapter, not just the active one; only the active adapter's values are used. Event observers, shortcodes and navbar entries of an adapter are active as soon as the adapter is *installed*, independent of which one is selected (e.g. the KSW quick-access menu appears even while `standard` is selected).

---

## The mapping model (identical for all adapters)

Taskflow does not know the field names of your HR export. You tell it in the adapter's settings section, where **every custom user profile field** gets two settings:

| Setting key | UI label | Meaning |
|---|---|---|
| `taskflowadapter_<name>/translator_user_<shortname>` | **JSON key for userprofilefield: <field name>** | Name of the key in the incoming JSON whose value is written into this profile field. Leave empty to not import the field. |
| `taskflowadapter_<name>/<shortname>` | **Assign function to userprofilefield: <field name>** | Which *role* this field plays for Taskflow (select, default "No function"). |

Three more text settings map the core user fields: **JSON key for userprofilefield: First name / Last name / Email** (`translator_user_firstname`, `translator_user_lastname`, `translator_user_email`). Three map the unit records for the INES format: **JSON key for userprofilefield: Name / Description / Organisational unit** (`translator_target_group_name`, `translator_target_group_description`, `translator_target_group_unitid`).

### Functions

| Function (UI label) | Stored value | What Taskflow does with the field | Used by |
|---|---|---|---|
| Target group | `translator_user_units` | Array of external unit ids the person belongs to; each becomes a cohort membership | tuines |
| Organisational unit | `translator_user_orgunit` | Organisation path string `A\B\C`; split into levels `Org1..OrgN`, each level becomes a unit/cohort, the user joins the deepest one | standard, ksw |
| Supervisor | `translator_user_supervisor` | Holds the **Moodle user id** of the supervisor after import; the supervisor gets the *Supervisor role* | all |
| Supervisor (external Moodle id) | `translator_user_supervisor_external` | The supervisor's *external* id as delivered by HR; resolved to a Moodle id via the *external ID* field and written into the *Supervisor* field | standard, ksw |
| Deputy | `translator_user_deputy` | Moodle user id of the deputy; used by requests and the deputy selector (not written by the import) | all (core) |
| Long Leave | `translator_user_longleave` | Boolean; true → all assignments **paused** | all |
| Contract end | `translator_user_contractend` | Date → timestamp; in the past → assignments **paused**, user suspended (standard/ksw) or treated like long leave (tuines) | all |
| Contract start | `translator_user_contractstart` | Date → timestamp; available for rule filters (e.g. `nowminusdays`) | all |
| external ID | `translator_user_externalid` | The customer's person id; used to find existing users and to resolve external supervisor ids | all |

Each function should be assigned to exactly one profile field. After saving, the settings page shows a red alert "Not all functions were selected during the last save…" or "Functions were selected multiple times…" when the count of fields with a function does not match the number of functions. The alert is a heuristic — check your mapping rather than trusting the alert alone.

---

## Comparison: standard / ksw / tuines

| Topic | standard | ksw | tuines |
|---|---|---|---|
| Import format | Flat JSON array of persons, org path in one string | Same as standard | Object with `persons` and `targetGroups` arrays |
| Import trigger | Upload / user events (every user save) | Upload / user events (every user save) | Scheduled task **Fetch remote data** at 03:00 from `dwhurl`, or "Trigger DWH import" button |
| Units | Nested from org path; user is member of the deepest unit | Same, plus cohort membership is removed for units left, added for units joined | Flat target groups → cohorts; membership follows the `targetGroup` array |
| Unit left | Assignments of that unit → **droppedout** (cohort membership kept) | Assignments → **droppedout**, cohort membership removed (unless the cohort is protected) | Assignments → **droppedout**, cohort membership removed |
| Unit (re-)joined | New assignments via rules | Existing assignments → **assigned** (completed stays), membership re-added | Existing assignments → **assigned** (completed stays), membership re-added |
| Contract end passed | All assignments **paused**, account suspended | Same | All assignments **paused**, memberships inactive; account **not** suspended |
| Long leave | All assignments **paused** | Same | Same; return → **assigned**, counters reset, mails re-scheduled |
| Person missing from feed | No effect | No effect | Suspended, removed from all cohorts, logged out, assignments **droppedout** |
| Supervisor | Moodle id or external id (`Manager_Id`) | Same; own lookup via *Supervisor* function | External id (`directSupervisor`); role revoked when nobody references the person any more |
| Extra settings | `necessaryuserprofilefields`, `blscertificatekey` | + `protectedcohorts` | `usingprolongedstate`, `excludestatus`, `dwhurl` |
| Edit-assignment form | One form (status, reason, comment, due date, keep changes) | Same form | Admin form + supervisor grant/deny extension form + comment form + internal chat |
| Extra UI | — | "Quickaccess" navbar dropdown | TISS link on names, "Go to training" action, chat column, own single-assignment page |
| Observers active once installed | Certificate on completion of rules named `*BLS*` | Cancels future bookings when a user is suspended | Cleans up mapping config when a profile field is deleted |

---

## Related

- [Settings](../settings/README.md) — all core settings, including `external_api_option`, `organisational_unit_option`, `supervisorrole`, `supervisor_field`, `hrusers`
- [Units and users](../units_and_users/README.md) — how units, cohorts, supervisors and deputies work once imported
- [Edit assignment](../assignments/03-edit-assignment.md) — adapter variants of the edit form
- [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md) — paused, droppedout, prolonged
- [Scheduled tasks](../scheduled_tasks/README.md) — `fetch_dwh_data` and the adhoc tasks queued by an import
- [Dashboard](../dashboard/README.md) — what the adapter-built dashboards show

---

## For AI / explain-docs routing

Questions that belong in this chapter: "Which adapter should I select?", "How do I map JSON keys to profile fields?", "What does the function *Supervisor (external Moodle id)* do?", "Why is my supervisor not resolved?", "What does the import do with contract end / long leave / a person that disappeared?", "What is the JSON format the import expects?", "Why does the KSW navbar menu appear?", "Where is the DWH URL?"

Questions that belong elsewhere: how supervisors, deputies and HR users are *used* after import → [Units and users](../units_and_users/README.md); what the statuses paused / droppedout / prolonged mean in general → [Status lifecycle](../assignments/01-status-lifecycle.md) and [Due dates](../assignments/05-due-dates-prolongation-overdue.md); the generic edit-assignment page → [Edit assignment](../assignments/03-edit-assignment.md); requests for extension/not relevant → [Requests](../requests/README.md); the chat feature in general → [Internal communication](../messages/03-internal-communication.md); every core setting → [Settings](../settings/README.md).
