[Back to user documentation index](../README.md)

# Scheduled tasks, adhoc tasks and caches — Reference

Taskflow does almost all of its work in the background. Two **scheduled tasks** run on a fixed schedule (a third one comes with the TU Wien INES adapter); everything else — creating assignments, enrolling users, sending messages, checking due dates, reopening cyclic assignments — happens in **adhoc tasks** that are queued by Moodle events and executed by cron. If "nothing happens" after saving a rule or importing users, cron has usually not run yet.

---

## Quick setup path

1. Make sure Moodle cron runs at least every minute (*Site administration → Server → Tasks → Task processing*).
2. Open *Site administration → Server → Tasks → Scheduled tasks* (`/admin/tool/task/scheduledtasks.php`) and search for "taskflow".
3. Adjust the schedule if needed (e.g. move *Rules with filters are regularly checked* to another night hour) and run a task manually with **Run now** for testing.
4. Watch pending adhoc tasks under *Site administration → Server → Tasks → Ad hoc tasks*.
5. After changing settings or when a table looks stale, purge caches (*Site administration → Development → Purge caches*).

---

## Table of contents

1. [Scheduled tasks](#1-scheduled-tasks)
2. [Adhoc tasks](#2-adhoc-tasks)
3. [Caches and when to purge](#3-caches-and-when-to-purge)
4. [Troubleshooting](#4-troubleshooting)
5. [Related](#5-related)

---

## 1. Scheduled tasks

| Task (name in the task list) | Class | Default schedule | Purpose |
|------------------------------|-------|------------------|---------|
| **Rules with filters are regularly checked** | `local_taskflow\task\reschedule_rules` | daily at 02:00 (`0 2 * * *`) | Re-evaluates every rule whose filter uses the time-relative operator `nowminusdays` (e.g. "contract start at least 90 days ago"), so users who newly match get their assignment and users who stop matching are dropped out. |
| **Notification of internal messages** | `local_taskflow\task\notification_internal_messages` | daily at 00:00 (`0 0 * * *`) | Daily digest of unread internal chat messages to assignees, supervisors and site administrators. |
| **Fetch remote data** (INES adapter only) | `taskflowadapter_tuines\task\fetch_dwh_data` | daily at 03:00 (`0 3 * * *`) | Nightly import of persons and organisational units from the Data Warehouse URL. |

### 1.1 `reschedule_rules` — Rules with filters are regularly checked

- Loads all rules and picks those with at least one filter using the operator `nowminusdays` (see [Filters](../rules/02-filters.md)).
- For each of them it fires the same event as saving the rule would, which queues the adhoc task `update_rule` (see below). The rule is then applied to all affected users: new matches get an assignment, users who no longer match are set to *Droppedout*.
- Rules without such a filter are **not** touched by this task; they are re-evaluated only when the rule, the unit membership or the user record changes.
- Running it more often than daily gives no benefit because `nowminusdays` has day granularity.

### 1.2 `notification_internal_messages` — Notification of internal messages

- Looks at chat messages written since the last run (or the last 24 hours on the first run) and compares them with the *last seen* timestamps of the assignee and the supervisor.
- Every assignee with unread messages from someone else gets **one** notification listing the affected assignments; every supervisor likewise; every site administrator gets a combined list.
- Message providers used (users can tune them under *Preferences → Notification preferences*): **Summary of internal Chat Message to assignees** (`assigneenotification`, e-mail forced on), **Summary of internal Chat Message to supervisors** (`supervisornotification`), **Summary of internal Chat Message to admin and chiefs** (`adminnotification`). Subject: *Notification of new chat messages*.
- Requires the setting **Internal Chat** (`allowinternalcommunication`) to be meaningful; see [Internal communication](../messages/03-internal-communication.md).

### 1.3 `fetch_dwh_data` — Fetch remote data (INES adapter)

- Reads the setting **Data Warehouse Url** (`taskflowadapter_tuines/dwhurl`); if empty, or if the endpoint returns an error or no `persons`, it logs the failure as an event and stops.
- Otherwise it hands the JSON to the adapter's import, which creates/updates users, cohorts, supervisors and pauses/suspends persons as described in [TU Wien INES adapter](../adapters/tuines.md).
- The same import can be triggered manually on `/local/taskflow/view.php` with **Trigger DWH import**.

---

## 2. Adhoc tasks

Adhoc tasks are queued automatically; you do not schedule them. They appear under *Site administration → Server → Tasks → Ad hoc tasks* until cron has processed them. All classes live in `local_taskflow\task`.

| Adhoc task | Queued when | What it does |
|------------|-------------|--------------|
| `update_rule` | A rule is created or saved (rule editor); the daily `reschedule_rules` run | Applies one rule to all its affected users (unit members, or the single user of a personal rule): creates or updates assignments for users who pass the filters, sets assignments of users who fail the filters to *Droppedout*. |
| `removed_rule` | A rule is deleted from the rules dashboard | Deletes the rule **and all its assignments**, then purges the rules cache. See [Rules](../rules/README.md). |
| `unit_updated` | A unit is updated (adapter import, unit relation change) | Re-applies all rules of that unit (and inherited ones) to all its members. |
| `update_assignment` | A planned assignment is opened; competency evidence is approved | Re-evaluates one user against one rule. |
| `open_planned_assignment` | An assignment is created with status *Planned* (rule has **Delay of activation**) | At the activation time switches the assignment to *Assigned* with a new assigned date and queues `update_assignment` so that targets are enrolled and messages scheduled. See [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md). |
| `check_assignment_status` | An assignment gets or changes a due date | Runs at the due date: if the assignment is still open (not paused, not completed), sets it to *Overdue* — or, with the INES setting **Use prolonged state** and an **Extension period** on the rule, to *Prolonged* on the first overrun and reschedules itself. |
| `reset_cyclic_assignment` | An assignment of a rule with **Does rule need cyclic validation?** is completed | Runs after the **Validation duration**: if the rule is still cyclic, unenrols the user from all targets, resets the overdue counter, reopens the assignment as *Assigned* and clears the sent-messages log so that reminders are sent again. See [Cyclic assignments](../assignments/06-cyclic-assignments.md). |
| `send_taskflow_message` | A message template is scheduled for an assignment (assignment created/updated, status changed, request created/treated, chat message) | Runs at the computed sending time; if the assignment still exists, the message is still valid and was not already sent, it sends e-mail (+ CC) and Moodle notification, logs history and records the send. See [Messages](../messages/README.md). |
| `check_supervisor` — *Check for supervisor role* | After an adapter import has written supervisor fields | Removes the **Supervisor role** from users who are no longer stored as anybody's supervisor. |

Notes:

- Queued `send_taskflow_message` tasks for the same user, message and rule are replaced when the assignment is rescheduled, so changing a due date does not produce duplicate mails.
- Assignments that lose their unit membership or fail a filter are not deleted; they are set to *Droppedout* (see [Status lifecycle](../assignments/01-status-lifecycle.md)). Only rule deletion (`removed_rule`) deletes assignment rows.
- During PHPUnit and in most adapter imports the tasks are queued, not executed immediately; on a live site cron picks them up within a minute.

---

## 3. Caches and when to purge

Taskflow defines the following cache definitions (visible under *Site administration → Plugins → Caching → Configuration*):

| Cache definition | Scope | Holds | Invalidated |
|------------------|-------|-------|-------------|
| Unit Hierarchy (`unit_hierarchy`) | application, 1 h TTL | The unit parent/child tree | by time (1 hour) |
| Rules list (`ruleslist`) | application | The rules table | when a rule is saved or deleted |
| Assignments list (`assignmentslist`) | application | The assignments tables (all dashboards and shortcodes) | when assignments change, a chat message is posted, a rule is saved |
| History list (`historylist`) | application | The history tables | when a history row is written |
| Dashboard filter (`dashboardfilter`) | session | The user tabs opened on the dashboard and the cached charts | when assignments change; tabs are removed with the tab's close button |
| Requests list (`requestslist`) | session | The requests table | when a request is created or treated |
| Assignments (`assignments`) | application | Loaded assignment objects | when an assignment is saved |

The INES adapter adds **Comment historylist** (`taskflowadapter_tuines/commenthistorylist`) for the comment history table, invalidated together with the history list.

**When to purge caches** (*Site administration → Development → Purge caches*, or `php admin/cli/purge_caches.php`):

- After changing **Display optional user profile field** or the adapter field mapping — the table column definitions are cached.
- After a manual database change or a data import outside the adapter.
- When a table still shows stale rows although cron has run.
- When the unit hierarchy was changed and rules for sub-units do not seem to apply yet (or wait up to one hour).

Purging caches never deletes assignments, rules or history — it only forces the tables to reload.

---

## 4. Troubleshooting

| Symptom | Check |
|---------|-------|
| Rule saved, but no assignments appear | Cron / pending `update_rule` adhoc task; the rule is **Enable rule**d; users are members of the unit; filters match. |
| Nobody gets *Overdue* | `check_assignment_status` tasks pending; the assignment has a due date; the status is not *Paused*. |
| Reminder mails are not sent | `send_taskflow_message` tasks pending; the message was already sent once for this user/message/rule (see **Send manual mails always**); the template's sending settings; Moodle's outgoing mail configuration. |
| Users with an older contract start never get the rule | *Rules with filters are regularly checked* runs at 02:00 — run it manually with **Run now**. |
| Supervisors keep the role after leaving | Wait for the next import (`check_supervisor` is queued by imports), or re-run the import. |
| Chat digest not received | The task runs at 00:00; the user's notification preferences for the three chat providers; messages must be newer than the reader's last visit of the assignment page. |

---

## 5. Related

- [Rules](../rules/README.md) — what happens when a rule is saved or deleted
- [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md)
- [Cyclic assignments](../assignments/06-cyclic-assignments.md)
- [Messages](../messages/README.md)
- [Internal communication](../messages/03-internal-communication.md)
- [TU Wien INES adapter](../adapters/tuines.md)

---

**For AI / explain-docs routing:** this chapter answers *"when does Taskflow run X / why is nothing happening / which task do I run manually / which cache do I purge"*. What a task *decides* (status rules, due-date logic) is documented in [Assignments](../assignments/README.md); *what* a message contains and *who* receives it is in [Messages](../messages/README.md); the nightly HR import itself is in [TU Wien INES adapter](../adapters/tuines.md).
