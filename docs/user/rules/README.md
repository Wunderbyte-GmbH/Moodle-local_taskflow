[Back to user documentation index](../README.md)

# Rules — Overview

A **rule** is the central building block of Taskflow. It answers five questions:

```
[Rule]  →  [Filter]  →  [Targets]  →  [Messages]  →  [Requests]
 WHO         WHICH        WHAT           NOTIFY        SELF-SERVICE
```

| Step | Question it answers | Examples |
|------|---------------------|----------|
| **Rule** | *Who* is addressed, *by when*, *how often*? | All members of the unit "Sales"; one specific user; due 4 weeks after assignment; repeat every year |
| **Filter** | *Which* of those users are really affected? | Only users whose profile field "role" equals "nurse"; only users employed for at least 90 days |
| **Targets** | *What* must the user complete? | A Moodle course, a booking option, a competency |
| **Messages** | *Which* message templates are sent, and when? | Reminder 7 days before the due date; confirmation on completion |
| **Requests** | *May the user ask* for an exception, and who decides? | "Not relevant for me" goes to the supervisor; deadline extension goes to HR |

Every user who matches a rule receives one **assignment** — a record that tracks the user's progress towards the rule's targets (status, due date, history, messages). Assignments are described in the [Assignments chapter](../assignments/README.md).

> **NOT this page:** How to write a message template (subject, body, recipients, timing) → [Messages](../messages/README.md). What happens after a request has been raised → [Requests](../requests/README.md). Assignment statuses and due dates → [Assignments](../assignments/01-status-lifecycle.md).

---

## Quick setup paths

### Assign a mandatory course to every member of a unit with a 30-day deadline

1. Open the rule editor for a new rule: `/local/taskflow/editrule.php?id=0`
   (you need the capability `local/taskflow:createrules`).
2. Step **Rule**: tick **Enable rule**, enter a **Name**, choose **Type** = *Rule for target group*, pick the unit in **Cohort**.
   Set **Due date type** = *Duration* and **Duration** = `30` days. Click *Next*.
3. Step **Filter**: leave empty (no filter = every member of the unit). Click *Next*.
4. Step **Targets**: **Target type** = *Moodle course*, pick the course in **Moodle course**. Click *Next*.
5. Step **Messages**: optionally select a **Message package** or single **Messages**. Click *Next*.
6. Step **Requests**: leave every request at *Not allowed* or choose a receiver. Click *Save*.
7. Assignments are created by a background task on the next cron run (see [Rule change propagation](#4-rule-change-propagation)). Each member of the unit is enrolled into the course and gets an assignment that is due 30 days after it was created.

### Only users who have been employed for at least 90 days (rolling window)

1. Follow the steps above; in step **Filter** click **Add filter**.
2. **Filter type** = *User profil field filter*, **User profile field** = your contract-start date field, **Operator** = *before now minus value in days*, **Value** = `90`.
3. Save. Users whose contract start lies 90 days or more in the past receive the assignment. Users who cross the 90-day line later are picked up by the daily scheduled task *Rules with filters are regularly checked* — see [02-filters.md](02-filters.md#daily-re-evaluation).

### Only users hired since a fixed date

1. In step **Filter** click **Add filter**; **Filter type** = *User profil field filter*, **User profile field** = contract-start field (must be a custom profile field of type *Date/Time*), **Operator** = *since*, and pick the date in the date selector.
2. Users whose field value is on or after that date match.

### Repeat every year (cyclic validation)

1. In step **Rule** tick **Does rule need cyclic validation?** and set **Validation duration** = `365` days.
2. When a user completes the assignment, a reset is scheduled for 365 days later: the user is unenrolled from the targets, the assignment goes back to *Assigned* and the cycle starts again. A completion that is older than the validation duration does not count. Details: [../assignments/06-cyclic-assignments.md](../assignments/06-cyclic-assignments.md).

### Assign something to one specific user

1. Step **Rule**: **Type** = *Rule for specific user*, pick the person in **User**.
2. The **Filter** step shows only the notice *"As the rule is for a specific user, no further filter settings are needed."*; continue with **Targets**.

---

## Pages in this chapter

| Page | Content |
|------|---------|
| [01-rule-step.md](01-rule-step.md) | Every field of step "Rule": name, type (target group / specific user), unit selector, due date (duration / fixed date), extension period, delay of activation, cyclic validation, inheritance, recursive, enable |
| [02-filters.md](02-filters.md) | Filter types, every operator with exact semantics and value format, combining filters, daily re-evaluation, what happens when a user stops or starts matching |
| [03-targets.md](03-targets.md) | Target types (Moodle course, competency, booking option), how users are enrolled/booked, how completion is detected, "complete before next" |
| [04-messages-step.md](04-messages-step.md) | Attaching message templates and packages to a rule, when the attached messages are sent |
| [05-requests-step.md](05-requests-step.md) | Allowing not-relevant / deadline-extension / evidence-upload requests per rule and choosing the receiver (Supervisor or HR) |

---

## Table of contents

1. [Where to manage rules](#1-where-to-manage-rules)
2. [Which steps appear — setting "Included functions"](#2-which-steps-appear--setting-included-functions)
3. [Activation: "Enable rule"](#3-activation-enable-rule)
4. [Rule change propagation](#4-rule-change-propagation)
5. [What happens to existing assignments when a rule is edited or disabled](#5-what-happens-to-existing-assignments-when-a-rule-is-edited-or-disabled)
6. [Deleting a rule](#6-deleting-a-rule)
7. [Who is evaluated: unit members, inheritance, suspended users](#7-who-is-evaluated-unit-members-inheritance-suspended-users)
8. [Related](#8-related)

---

## 1. Where to manage rules

- **Rules dashboard** — a table of all rules. It is part of the admin view of the Taskflow dashboard `/local/taskflow/index.php` and can be placed on any page with the shortcode `[rulesdashboard]` (requires the capability `local/taskflow:viewrules`; see [Shortcodes](../shortcodes/README.md)).
  Columns: **Rulename**, **Description**, **Is active** (yes/no), **Actions** (edit, delete). A search box filters by rule name. Users with `local/taskflow:createrules` see a button that opens the editor for a new rule.
- **Rule editor** — `/local/taskflow/editrule.php?id=<ruleid>`; `id=0` creates a new rule. An optional `returnurl` parameter defines where you land after saving. Requires `local/taskflow:createrules`.

The editor is a multi-step form. The first step is always **Rule**; the further steps are **Filter**, **Targets**, **Messages** and **Requests** (see section 2). Each step is saved into the rule when you click *Save* on the last step. A rule is stored as one record with a JSON definition; the pages of this chapter describe what each field means.

## 2. Which steps appear — setting "Included functions"

Site administration → Plugins → Local plugins → Wunderbyte Taskflow → **Included functions** (`local_taskflow/includedsteps`) is a multi-select with the entries *Filter*, *Target*, *Messages*, *Requests*. Only the selected steps are shown in the rule editor. If nothing is selected, **all** steps are shown. The step **Rule** is always present. See [Settings](../settings/README.md).

## 3. Activation: "Enable rule"

The checkbox **Enable rule** in step Rule (help text *"Check to active"*) controls whether the rule is active (**Is active** = yes in the rules dashboard).

- Only **active** rules create assignments. When a user is checked against an inactive rule, the check fails just as if a filter did not match — see section 5 for the effect on already existing assignments.
- Actions (enrolment, booking) and scheduled messages are only triggered for active rules.
- There is no activate/deactivate switch in the rules table; open the rule and change **Enable rule**.

## 4. Rule change propagation

Saving a rule (new or edited) fires an internal event that queues a background task (*update rule*). The task runs on the next cron run and evaluates every affected user against the rule: users of the selected unit (plus child units when **inheritance** is set) or the single selected user. For every user the result is:

| Result | Effect |
|--------|--------|
| Rule active and every filter matches, no assignment yet | Assignment is created, targets are enrolled/booked, time-based messages are scheduled, a due-date check task is queued |
| Rule active and filters match, assignment exists | Assignment is updated (see section 5) |
| Rule inactive or a filter does not match, assignment exists and is active | Assignment goes to status **Droppedout** (see section 5) |
| Rule inactive or filter does not match, no assignment | Nothing happens |

Because this runs in a background task, changes become visible only after cron has executed the adhoc tasks (a few minutes on a normal installation).

Besides saving the rule, the same evaluation is triggered when

- a user is added to a unit (import, cohort membership, manual upload),
- a unit hierarchy or unit is changed,
- a user record is created or updated in Moodle (not during imports and not with the adapter *tuines*),
- the assignment page is opened with the re-check action (`/local/taskflow/assignment.php?id=<assignmentid>&action=checkstatus`),
- the daily scheduled task *Rules with filters are regularly checked* runs (only for rules with the operator *before now minus value in days*, see [02-filters.md](02-filters.md#daily-re-evaluation)).

## 5. What happens to existing assignments when a rule is edited or disabled

The checkbox **Enable or disable rule will also effect exsisting assignments** (stored as `recursive`) decides how far a saved change reaches:

| Situation | "…effect existing assignments" **unchecked** | "…effect existing assignments" **checked** |
|-----------|-----------------------------------------------|-------------------------------------------|
| Rule edited (targets, filter, due date …) and still enabled | Only users **without** an assignment for this rule are processed (new members get assignments). Existing assignments keep their targets, messages and due date. | Every matching user is re-processed: the assignment's target and message list is replaced by the rule's current list, missing targets are enrolled, the **due date is recalculated** from the rule (except for assignments in status *Prolonged*, which keep their due date). Status is recomputed from actual completion unless the assignment is marked *Keep changes* (see [../assignments/03-edit-assignment.md](../assignments/03-edit-assignment.md)). |
| Rule **disabled** | Existing assignments are left untouched; no new assignments are created. | Every active assignment of the rule goes to **Droppedout**: counters reset, assigned and due date cleared, scheduled messages removed. Assignments that were already *Completed* are only marked inactive and keep their data. |
| Rule **re-enabled** | Only users without an assignment get one. | Dropped-out assignments of matching users are reactivated (same record; status recomputed, due date recalculated from now for duration rules). |
| A user no longer matches the filter after an edit | Not detected for users who already have an assignment. | Assignment goes to **Droppedout** as described above. |

Rule changes are recorded in the assignment history (type *rule change*), see [../assignments/04-history.md](../assignments/04-history.md).

> **Note:** The same `recursive` flag has a second effect in unit hierarchies: an active rule of a **parent** unit that has this flag is also applied to users who are added to a **child** unit (see section 7).

## 6. Deleting a rule

In the rules dashboard the trash icon opens the confirmation *"Are you sure to delete the rule "<name>" and all the created assignments?"*. Confirming queues a background task that

1. **deletes every assignment** of this rule (hard delete — the assignments disappear from all dashboards; users are **not** unenrolled from courses or booking options), and
2. deletes the rule itself.

Feedback: *"Rule was successfully deleted"*. Related records such as history entries and sent-message logs are not removed. There is no undo. If you only want to stop the rule, disable it instead (section 3).

## 7. Who is evaluated: unit members, inheritance, suspended users

- **Rule for target group**: all **active** members of the selected unit whose Moodle account is not suspended and who are not on long leave (a custom profile field with the shortname `longleave` set to `1`, if such a field exists). How users get into units is described in [Units and users](../units_and_users/README.md).
- **Inheritance** (checkbox labelled *Regelvererbung* in the English UI, meaning "rule inheritance"): when checked, the rule is also applied to the members of all **child units** of the selected unit. The member list for child units does not apply the suspended-account check.
- **Parent-unit rules with "…effect existing assignments"**: when a user is added to a unit, Taskflow also collects the active rules of the parent units that have the `recursive` flag and applies them — unless the user is already a member of that parent unit (or reaches it through another membership), in which case the parent's own processing covers it.
- **Rule for specific user**: exactly the chosen user; no filter step.
- **Losing membership**: when a user is removed from a unit or the unit is deleted, the user's assignments for the unit's rules go to **Droppedout** (completed ones only become inactive). See [../assignments/05-due-dates-prolongation-overdue.md](../assignments/05-due-dates-prolongation-overdue.md).

> **Note:** The admin setting *Rule inheritance?* (`inheritance_option`) has no effect in the current code; inheritance is controlled per rule by the checkbox above.

## 8. Related

- [Getting started — vocabulary and flow](../getting_started/README.md)
- [Assignments — status lifecycle](../assignments/01-status-lifecycle.md), [cyclic assignments](../assignments/06-cyclic-assignments.md)
- [Messages — templates](../messages/01-message-templates.md), [placeholders](../messages/02-placeholders.md)
- [Requests](../requests/README.md)
- [Units and users](../units_and_users/README.md)
- [Settings](../settings/README.md), [Capabilities](../capabilities/README.md), [Scheduled tasks](../scheduled_tasks/README.md)

---

## For AI / explain-docs routing

Questions that belong in **this chapter**: how to create/edit/delete a rule; what a field in the rule editor means; which users a rule addresses (unit, specific user, inheritance); filter operators and value formats; target types and how enrolment/booking/completion works per type; attaching messages or allowing requests *in a rule*; what a rule change does to existing assignments.

Send elsewhere:
- Subject/body/recipients/timing of a message template, placeholders → [Messages](../messages/README.md).
- What a supervisor or HR does with a request, the requests dashboard → [Requests](../requests/README.md).
- Assignment statuses, overdue, prolongation, history, editing a single assignment → [Assignments](../assignments/README.md).
- How users get into units, supervisors, deputies, imports → [Units and users](../units_and_users/README.md) and [Adapters](../adapters/README.md).
- Evidence upload and certificates → [Competencies and certificates](../competencies_and_certificates/README.md).
