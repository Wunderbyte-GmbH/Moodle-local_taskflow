[Back to user documentation index](../README.md)

# Assignments

> **Primary page** for: what an assignment is, which statuses it can have, how it moves between them, what the assignee and the supervisor see, how admins change it manually, and what the history log records. For *creating the rules that produce assignments* see [Rules](../rules/README.md); for *self-service requests* (not relevant / prolongation / evidence) see [Requests](../requests/README.md); for *e-mails and notifications* see [Messages](../messages/README.md).

---

## What is an assignment?

An **assignment** is one rule applied to one user: the obligation of that user to complete the rule's **targets** (booking options, Moodle courses, competencies) by a **due date**. Taskflow creates assignments automatically — nobody creates them by hand:

- when a **rule** is saved or enabled, for every user in the rule's unit / target group who passes the rule's filters;
- when a **user joins a unit** (import, cohort membership, upload) that has rules;
- when a user's **profile changes** so that they now pass a filter (re-evaluated nightly for date filters such as `nowminusdays`, see [Scheduled tasks](../scheduled_tasks/README.md)).

There is exactly one assignment per (user, rule). If the user leaves the unit or stops matching the filter, the assignment is not deleted but set to **Droppedout**; if they come back, the same assignment is reactivated. Only deleting the rule deletes its assignments.

An assignment carries:

| Field | Shown as | Meaning |
|---|---|---|
| Status | **Status** column / "Status" on the detail pages | Where the assignment is in its lifecycle — see [01 — Status lifecycle](01-status-lifecycle.md). |
| Active flag | **Active** / "Activate assignment" / "Deactivate assignment" | Whether the assignment currently counts. Derived from the status; inactive assignments are hidden from the default lists and ignored by completion detection. |
| Assignment date | **Assignment date** | When the assignment was (re)opened. Start point for `Duration` due dates and for "after start" messages. |
| Due date | **Due date** / "Due date until - " | When the targets must be completed. Empty for Planned, Paused, Droppedout and Not relevant assignments. |
| Completed date | – (used by messages and reports) | Set when the status becomes Completed. |
| Targets | **Targets** / "Assigned Packages" (TU Wien) | Copy of the rule's targets with a per-target *completed / Not completed* flag. |
| Overdue counter / Prolonged counter | shown in brackets after the status, e.g. "Overdue (1)" | How often the assignment went overdue / was prolonged. Used for sorting and for the supervisor's "To clarify" view. |
| Keep changes | **Keep changes of the date on import of data** | Protects a manually edited due date and active flag from being overwritten by imports and rule re-evaluation — see [03 — Edit assignment](03-edit-assignment.md). |
| Last modified by | **Last modified by** | The user who last changed the assignment. |

---

## Quick path

**As an employee (assignee)**

1. Open the Taskflow dashboard [/local/taskflow/index.php](/local/taskflow/index.php) or the page where your admin placed the `[myassignments]` shortcode.
2. In the **My Assignments** table click the info icon of a row.
3. You are on the assignment detail page [/local/taskflow/assignment.php?id=<assignmentid>](/local/taskflow/assignment.php?id=<assignmentid>): open the target, upload evidence, send a request or write to your supervisor — see [02 — Assignment detail page](02-assignment-detail-page.md).

**As a supervisor**

1. Open [/local/taskflow/index.php](/local/taskflow/index.php) → tab **Supervisor**.
2. The **Detailview** table lists the assignments of your team (and of the teams you are a deputy for). Filter **Status** or enable **Hide completed assignments**.
3. Click the info icon to see the assignment, or the edit icon to open [/local/taskflow/editassignment.php?id=<assignmentid>](/local/taskflow/editassignment.php?id=<assignmentid>) — see [03 — Edit assignment](03-edit-assignment.md).

**As an admin / HR**

1. Open [/local/taskflow/index.php](/local/taskflow/index.php) → tab **Admin- Dashboard** (needs `local/taskflow:editassignment`, see [Capabilities](../capabilities/README.md)).
2. In **Detailview** search for the user or rule; click the edit icon.
3. On [/local/taskflow/editassignment.php?id=<assignmentid>](/local/taskflow/editassignment.php?id=<assignmentid>) change status, reason, due date and comment, then **Save changes**. The change is logged in the **history** — see [04 — History](04-history.md).

---

## Documentation pages

| Page | What it covers |
|---|---|
| [01 — Status lifecycle](01-status-lifecycle.md) | Every status (code, name, active flag, manually selectable), what moves an assignment from one status to another, the `active` flag, chart grouping, reasons for manual changes, excluded statuses |
| [02 — Assignment detail page](02-assignment-detail-page.md) | `/local/taskflow/assignment.php?id=` — what assignee and supervisor see: targets, completion, request buttons, evidence upload, chat, possible courses, `checkstatus` |
| [03 — Edit assignment](03-edit-assignment.md) | `/local/taskflow/editassignment.php?id=` — admin and supervisor variants, the status change form, comment form, chat, history panel, differences per adapter |
| [04 — History](04-history.md) | The history log: every entry type, what it records, where it is shown |
| [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md) | Due date types, activation delay / Planned, extension period, prolongation and counters, Overdue, `allowoverduecompletion`, `usingprolongedstate`, Paused (contract end / long leave), Droppedout and re-entry |
| [06 — Cyclic assignments](06-cyclic-assignments.md) | Cyclic validation and validation duration, reopen / reset, complete-before-next chains, what happens when a rule is changed or deleted |

---

## The lifecycle in one picture

```
rule saved / user joins unit / profile matches filter
        │
        ▼
   ┌─ Planned ─┐   (only if the rule has a "Delay of activation")
   │           ▼
   │       Assigned ──── booking option booked ────► Enrolled
   │           │                                        │
   │           │  one of several targets completed      │
   │           ├───────────────────────────────────► At least one target completed
   │           │                                        │
   │           │  all targets completed                 │
   │           ├───────────────────────────────────► Completed ──(cyclic rule, validation
   │           │                                        ▲          duration over)──► Assigned
   │           │  due date passed                       │
   │           ├──────► Overdue ────────────────────────┘ (only if "Overdue assignments
   │           │           ▲                               can still be completed" is on)
   │           │           │ extended due date passes again
   │           └──────► Prolonged  (first overrun with extension period — TU Wien —,
   │                                or manual extension by supervisor / admin)
   │
   ├── user leaves unit / fails filter / missing in import ──► Droppedout ──(comes back)──► Assigned
   ├── contract ended / long leave ──────────────────────────► Paused ─────(returns)─────► Assigned
   └── "Not relevant" request confirmed ─────────────────────► Not relevant
   (Reprimand / Sanctioned: set manually by admins only)
```

Completed, Not relevant and Paused assignments are never changed automatically by completion events; a Completed assignment that loses its unit only becomes inactive but keeps its status.

---

## Who sees which assignments?

| Role | Where | What |
|---|---|---|
| Assignee | **My Assignments** (dashboard user tab, `[myassignments]` shortcode, [/local/taskflow/view.php](/local/taskflow/view.php)) and the detail page | Own active assignments (a shortcode argument can include inactive ones) |
| Supervisor / deputy | Dashboard tab **Supervisor** → **Detailview**, **To clarify**, `[supervisorassignments]` shortcode | Assignments of users whose supervisor profile field points to them, plus the teams of every supervisor who lists them as deputy |
| Admin / HR | Dashboard tab **Admin- Dashboard** → **Detailview**, `[assignmentsdashboard]` shortcode | All assignments; columns, filters, download and chart described in [Dashboard](../dashboard/README.md) |

Which columns the tables show, how to filter and download them and what the doughnut chart counts is documented in [Dashboard](../dashboard/README.md) and [Shortcodes](../shortcodes/README.md).

---

## Related

- [Rules](../rules/README.md) — the rule step ([01 — Rule step](../rules/01-rule-step.md)) defines due date type, duration, extension period, activation delay and cyclic validation; [03 — Targets](../rules/03-targets.md) explains how each target type is enrolled and detected as completed.
- [Requests](../requests/README.md) — "Not relevant for me", "Request Prolongation" and evidence requests and what confirming them does to the assignment.
- [Messages](../messages/README.md) — automatic e-mails before / after the due date and on status change; [03 — Internal communication](../messages/03-internal-communication.md) for the chat shown on the assignment pages.
- [Units and users](../units_and_users/README.md) — how users enter and leave units, long leave and contract end.
- [Adapters](../adapters/README.md) — the Standard, KSW and TU Wien (INES) adapters change forms, statuses and import behaviour described here.
- [Settings](../settings/README.md) — `allowoverduecompletion`, `allowinternalcommunication`, `hrusers`, `assignment_fields`, `showassignmentslist`.

---

## For AI / explain-docs routing

Questions that belong in this chapter: "what does status X mean", "why is my assignment Overdue / Prolonged / Paused / Droppedout", "how do I change the status or due date of an assignment", "what is keepchanges", "what does the history show", "when does a cyclic assignment reopen", "what is on the assignment page", "who can edit an assignment".

Neighbouring chapters: *how to create or change a rule, filter or target* → [Rules](../rules/README.md); *how an employee requests an extension or 'not relevant' and who approves it* → [Requests](../requests/README.md); *which e-mail is sent when, placeholders, chat digest* → [Messages](../messages/README.md); *table columns, filters, chart, user tabs* → [Dashboard](../dashboard/README.md); *import behaviour, contract end, missing persons, TU Wien specifics* → [Adapters](../adapters/README.md); *evidence review and certificates* → [Competencies and certificates](../competencies_and_certificates/README.md).
