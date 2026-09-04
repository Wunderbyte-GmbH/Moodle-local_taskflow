[Back to user documentation index](../README.md)

# Units and users

Taskflow assigns learning obligations to **people** because they belong to an **organisational unit** (a department,
a clinic, a target group) and because a **rule** exists for that unit. This chapter explains where units come from,
how users become unit members, which profile fields Taskflow relies on, how **supervisors**, **deputies** and **HR
users** are determined, and what happens to a person's assignments when they leave a unit, go on long leave, reach
their contract end, are suspended or are deleted.

Most of the *import* behaviour is provided by the active adapter; this page describes what is common to all adapters
and links to [Adapters](../adapters/README.md) for the differences.

---

## Quick path (administrator)

1. *Site administration → Plugins → Local plugins → Wunderbyte Taskflow*: under **Organisational units** choose
   whether units are modelled as **Units** (Taskflow's own table) or **Cohorts** (Moodle cohorts) —
   setting `organisational_unit_option`. Decide this **before** the first import; the two models are not migrated
   into each other.
2. Under **External api with user data** select the adapter and, in the adapter's settings block, map the JSON
   keys of your HR export to user profile fields and give each field its **function** (Supervisor, Deputy, Contract
   end, …) — see [Adapters](../adapters/README.md).
3. Choose the **Supervisor role** (`supervisorrole`) that every supervisor should receive automatically, and enter
   the Moodle user ids of your HR staff in **HR userids** (`hrusers`).
4. Import users: `/local/taskflow/view.php` → **Upload users** (paste JSON) or **Trigger DWH import** (INES), or wait
   for the adapter's scheduled import.
5. Check the result: *Site administration → Users → Cohorts* (cohort model) shows the units with their members;
   the supervisor's profile field now holds a user id and the supervisor holds the configured role.

---

## Organisational units

### Units versus cohorts

| Model (`organisational_unit_option`) | Where units live | Membership | Visible to admins as |
|---|---|---|---|
| **Units** (`unit`) | Taskflow's own table of units (name, description, external id) | Taskflow's own membership table | only inside Taskflow (rule form selector, tables) |
| **Cohorts** (`cohort`, recommended and used by all three adapters' reference configurations) | Moodle **system cohorts**; the cohort *ID number* holds the external unit id (or a hash of the unit path for units created from an organisation path) | Moodle cohort membership **and** Taskflow's membership table (kept in sync) | *Site administration → Users → Cohorts*, plus everywhere cohorts can be used in Moodle |

In the cohort model the rule form shows units by their **path**, e.g. `KSW/Klinischer Bereich A/Zentrum X`, so
that units with the same name under different parents can be told apart.

### Hierarchy

Units can have a **parent unit**. The hierarchy is written by the imports:

- the standard and KSW adapters split the organisation path of a person (e.g. `KSW\Klinischer Bereich A\Zentrum X`)
  into levels and create one unit per level with the parent relation; the person becomes a member of the
  **deepest** unit;
- the INES adapter imports a flat list of target groups (no parent relations).

The hierarchy is used for the **unit selector** in the rule form and by the rule's *Rule for target group*
inheritance options (see [Rule step](../rules/01-rule-step.md)). Relations are cached for an hour.

> **Note:** The admin setting *Rule inheritance?* (`inheritance_option`) is present on the settings page but is not
> evaluated anywhere in the current code. Whether a rule applies to sub-units is decided in the rule itself.

### How users become unit members

| Source | What happens |
|--------|--------------|
| **Adapter import** (scheduled task, *Upload users*, *Trigger DWH import*) | users are created or updated, units are created, the user is made a member of the units listed in the import, memberships that disappeared are deactivated (see [Leaving a unit](#leaving-a-unit)) |
| **Manual cohort membership** (cohort model, admin adds a user to a cohort in Moodle) | if the setting **Cohort enrollment** (`cohortenrollment`, default on) is enabled, the user becomes a Taskflow unit member and all rules of that unit are evaluated for them immediately. If the setting is off, manual cohort changes are ignored by Taskflow |
| **Removing a user from a cohort** (manually or by import) | the unit membership is removed and the assignments that stem from rules of that unit are set to **Droppedout** (completed assignments stay completed) |
| **Deleting a cohort** | treated like removing every member: memberships gone, related assignments **Droppedout** |
| **Standard/KSW adapters only: saving a user profile** in Moodle (`user_created` / `user_updated`) | the adapter re-reads the mapped profile fields of that user and re-syncs units, supervisor, contract end and long leave, provided the fields listed in the adapter setting **User profile fields required to be filled in for Taskflow** are not empty. The INES adapter never reacts to profile saves; its data comes only from the DWH feed |

A new membership triggers the rule evaluation for that user only if the user is **not suspended** and **not on long
leave**; otherwise the membership is recorded but no assignments are created until the person is active again.

### Users created by the import

- Existing users are matched in this order: external id (profile field with function **external ID**) → username →
  e-mail. Only if nothing matches, a new account is created.
- New accounts get the authentication method from the setting **Default authentication method** (`defaultauth`,
  default *Manual accounts*), are marked confirmed, receive a random password, and their username is the external id
  if one is mapped, otherwise `firstname.lastname` (transliterated to ASCII, numbered if taken).
- On every import first name, last name, e-mail and phone are updated when they changed, and the mapped profile
  fields are overwritten with the imported values.

---

## Profile fields Taskflow relies on

Taskflow stores everything it knows about a person in **custom user profile fields**. Which field plays which role is
configured in the adapter settings (the field's **function**, see [Adapters](../adapters/README.md)):

| Function | Content of the field | Used for |
|----------|----------------------|----------|
| Target group | JSON list of external unit ids (INES) | which units the person belongs to |
| Organisational unit | organisation path `A\B\C` (standard/KSW) | creating the unit hierarchy |
| **Supervisor** | the **Moodle user id** of the supervisor (one number) | team scope, "Supervisor" column, request receiver, mails |
| **Deputy** | comma-separated Moodle user ids, stored on the **supervisor's** record | deputy scope, mail copies |
| Supervisor (external Moodle id) | external id of the supervisor; converted to the Moodle id during import | standard/KSW |
| Long Leave | `1`/`0` | pausing assignments |
| Contract end / Contract start | unix timestamps (imported dates are converted) | pausing / suspension; rule filters (e.g. "contract start at least N days ago") |
| external ID | the person id of the HR system | matching users, TISS link (INES) |

On installation/upgrade Taskflow creates four textarea profile fields if they do not exist (all in the first
profile category, visible to everyone): `unit_info` (*Unit Information*, locked), `tissid_info` (*Externe ID*),
`organisational_unit_info` (*Organisational unit Information*) and `end_info` (*End Information*). They are offered
as candidates in the mapping settings; they are **not** mapped automatically, and you may just as well map fields you
created yourself (the reference configurations use fields such as `supervisor`, `deputy`, `externalid`,
`contractend`, `contractstart`, `orgunit`).

Rules can filter on any of these fields (see [Filters](../rules/02-filters.md)), and the assignments table can show
them as extra columns (setting **Display optional user profile field**, see [Dashboard](../dashboard/README.md)).

---

## Supervisor and deputy

### Who is somebody's supervisor?

The supervisor of a user is the user whose **id** is stored in that user's profile field with the function
**Supervisor**. Nothing else (no cohort role, no course role) makes somebody a supervisor in Taskflow. The value is
written by the import; with the standard adapter it can also be maintained by hand in the user's profile.

If **no adapter mapping** for the Supervisor function exists, Taskflow falls back to the core setting
**Supervisor Overview – Choose a field for the supervisor** (`supervisor_field`), which names the profile field
directly. This setting is only offered on the settings page when no adapter plugin is installed.

### The automatic supervisor role

- Setting **Supervisor role** (`supervisorrole`) selects a system role. On upgrade Taskflow creates a role with the
  shortname `supervisor` that contains `local/taskflow:issupervisor` and can be assigned in the system context; you
  may select this or any other role.
- During every import, each user who is referenced as supervisor receives this role in the system context — but only
  if the supervisor account is confirmed, not suspended, not deleted and does not use the *No login* authentication.
- With the INES adapter the role is **revoked** after each import from users who are no longer referenced as
  supervisor by any non-suspended user (adhoc task *Check for supervisor role*). The standard and KSW adapters do
  not revoke the role automatically; remove it by hand in *Site administration → Users → Permissions → Assign
  system roles*.

The capability `local/taskflow:issupervisor` is what unlocks the Supervisor tab, the team-restricted user search,
the `[supervisorassignments]` shortcode, the *Status* button on competency evidence and the request actions. See
[Capabilities](../capabilities/README.md).

### Team scope

A supervisor sees:

- **Direct team**: all users whose Supervisor field equals the supervisor's user id;
- **Deputy teams**: if the supervisor's user id appears in the **Deputy** field of another supervisor, that
  supervisor's direct team as well.

This scope is applied consistently to the Supervisor tab, the user search, `[supervisorassignments]`, the requests
dashboard and the edit-assignment page.

### Deputies

The **Deputy** field lives on the **supervisor's** profile and holds a comma-separated list of user ids. Deputies

- see the supervisor's team (see above);
- see the requests addressed to the supervisor (see [Requests](../requests/README.md));
- receive a copy of mails sent to the supervisor if the setting **Send mails to deputy** (`sendmailstodeputy`) is on
  (see [Messages](../messages/README.md)).

> **Note:** Every id in the Deputy field must belong to an existing user; a stale id (deleted account) causes an
> error when Taskflow resolves the deputies for a mail or request.

---

## HR users

Taskflow knows two HR lists, configured in two different places:

| Setting | Effect |
|---------|--------|
| **HR userids** (`local_taskflow/hrusers`, comma-separated user ids) | receivers of requests whose rule routes them to **HR** instead of the supervisor (see [Requests step](../rules/05-requests-step.md)); the first id is also recorded as modifier of manual status changes made through the status form |
| **HR users** of the booking extension *confirmation supervisor* (`bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers`) | unlocks the **Admin- Dashboard** tab on `/local/taskflow/index.php` and the HR view of the `[requests]` shortcode for users without `local/taskflow:editassignment` |

In practice enter the same ids in both places. HR staff additionally need `local/taskflow:editassignment`,
`local/taskflow:viewreports`, `local/taskflow:treatrequests` and `local/taskflow:viewallrequests` for the full
picture — see [Capabilities](../capabilities/README.md).

---

## Lifecycle events and their effect on assignments

### Leaving a unit

When an import no longer lists a unit for a person (or the person is removed from the cohort by hand):

- the unit membership is deactivated (KSW and INES also remove the cohort membership; the standard adapter leaves the
  cohort membership in place),
- every **active** assignment of a rule of that unit is set to **Droppedout**; completed assignments stay completed,
- **KSW / INES:** if the unit reappears later, the dropped-out assignments of that unit are re-activated to
  **Assigned** (completed ones stay completed).
- **KSW:** cohorts listed in the adapter setting *Protected cohorts* are never touched by the import.

### Long leave and contract end (adapter-specific)

A person is treated as absent when the field with function **Long Leave** is set or the **Contract end** lies in the
past. Then **all** assignments of the person are set to **Paused** (inactive) and new memberships do not create
assignments. What else happens depends on the adapter:

| | Standard / KSW | INES (tuines) |
|---|---|---|
| Contract end in the past | assignments **Paused**; the Moodle account is **suspended** (site admins excepted) | assignments **Paused**, unit memberships deactivated |
| Long leave set | assignments **Paused** | assignments **Paused**, unit memberships deactivated |
| Return (long leave cleared, or contract end moved from past into the future) | no automatic reactivation | paused assignments become **Assigned** again with counters reset, memberships reactivated, rules re-evaluated |
| Person missing from the feed | – | account suspended, removed from all cohorts, sessions ended, all assignments **Droppedout**; reappearing persons are un-suspended |

Details and the exact JSON fields: [Standard adapter](../adapters/standard.md), [KSW adapter](../adapters/ksw.md),
[TU Wien INES adapter](../adapters/tuines.md). What *Paused* and *Droppedout* mean for due dates and re-entry is
described in [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md).

### Suspension

- A suspended user is excluded from the dashboard user search and no longer qualifies for the automatic supervisor
  role; with INES the role is revoked on the next import.
- New unit memberships of a suspended user do not create assignments.
- **KSW:** when a user is suspended, all their future bookings (booking options with a start date in the future,
  self-learning courses excepted) are cancelled.

### Deleting a Moodle user

Deleting a user account removes all of the person's unit memberships and sets their assignments to **Droppedout**
(completed ones stay). History rows are kept for auditing.

---

## Related

- [Getting started](../getting_started/README.md) — vocabulary and the first admin workflow
- [Adapters](../adapters/README.md) — import formats, field mapping, adapter-specific behaviour
- [Rules](../rules/README.md) — how units are selected in a rule; [Filters](../rules/02-filters.md) on profile fields
- [Dashboard](../dashboard/README.md) — supervisor and deputy scope in the tables and the user search
- [Requests](../requests/README.md) — supervisor versus HR as request receiver
- [Settings](../settings/README.md) — `organisational_unit_option`, `cohortenrollment`, `supervisorrole`, `hrusers`, `defaultauth`
- [Capabilities](../capabilities/README.md) — `local/taskflow:issupervisor` and the recommended role setup

---

## For AI / explain-docs routing

Questions that belong here: difference between units and cohorts, how a user gets into (or out of) a unit, which
profile fields Taskflow creates or expects, how the supervisor or deputy of a user is determined, why a supervisor
did (not) get the supervisor role, what "HR users" means, and what happens to assignments on unit change, long
leave, contract end, suspension or account deletion.

Route elsewhere: JSON keys and the mapping settings of a particular adapter → [Adapters](../adapters/README.md);
the meaning of the statuses Paused/Droppedout and due-date handling → [Assignments](../assignments/README.md);
which capabilities to give supervisors and HR → [Capabilities](../capabilities/README.md); who receives a request
→ [Requests](../requests/README.md).
