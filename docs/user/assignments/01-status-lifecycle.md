[Back to chapter overview](README.md)

# 01 — Status lifecycle

> **Primary page** for: the meaning of every assignment status, which event moves an assignment from one status to the next, what the *active* flag does, how the dashboard chart groups statuses, and how the adapter setting **Do not use status** hides statuses. Due-date mechanics (extension period, counters, Paused, Droppedout) are detailed in [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md); cyclic re-opening in [06 — Cyclic assignments](06-cyclic-assignments.md).

---

## Table of contents

1. [Status table](#1-status-table)
2. [The active flag](#2-the-active-flag)
3. [Transitions — what moves an assignment](#3-transitions--what-moves-an-assignment)
4. [How the status is recomputed](#4-how-the-status-is-recomputed)
5. [What each status does to dates and counters](#5-what-each-status-does-to-dates-and-counters)
6. [Chart grouping and sorting](#6-chart-grouping-and-sorting)
7. [Manual status changes and their reasons](#7-manual-status-changes-and-their-reasons)
8. [Excluded statuses ("Do not use status")](#8-excluded-statuses-do-not-use-status)
9. [Worked example](#9-worked-example)

---

## 1. Status table

The status is stored as a number; the UI shows the name. Names can be overridden by the active adapter's language file (the TU Wien adapter does not rename statuses).

| Code | Internal label | UI name | Active | Selectable in **Change status** | Set by |
|---|---|---|---|---|---|
| -2 | `notrelevant` | **Not relevant** | no | no | Confirming a "Not relevant for me" request |
| -1 | `planned` | **Planned** | no | no | Rule with **Delay of activation** > 0, until the delay has passed |
| 0 | `assigned` | **Assigned** | yes | yes | Default start status; re-entry after Droppedout / Paused; cyclic reopening |
| 3 | `enrolled` | **Enrolled** | yes | yes | The user is booked into a booking-option target |
| 4 | `paused` | **Paused** | no | yes | Contract ended / long leave (import); manual |
| 5 | `prolonged` | **Prolonged** | yes | yes | Due date extended (automatic first overrun at TU Wien, supervisor "Grant extension", admin form) |
| 7 | `partially_completed` | **At least one target completed** | yes | yes | Some but not all targets completed |
| 10 | `overdue` | **Overdue** | yes | yes | Due date passed |
| 11 | `reprimand` | **Reprimand** | yes | yes | Manual only |
| 12 | `sanction` | **Sanctioned** | yes | yes | Manual only |
| 15 | `completed` | **Completed** | yes | yes | All targets completed; manual |
| 16 | `droppedout` | **Droppedout** | no | yes | User left the unit / no longer matches the filter / missing from the import / user deleted; manual |

"Selectable in **Change status**" refers to the dropdown on the edit page ([03 — Edit assignment](03-edit-assignment.md)): it offers every status with a code ≥ 0, minus the statuses listed in the adapter setting **Do not use status** (section 8). Planned and Not relevant can therefore never be chosen by hand.

> **Note:** each status type also carries an internal "user choice" flag (Assigned, Enrolled, Paused, Prolonged, Reprimand, Sanctioned, Completed, Droppedout = yes; the others = no). That flag is currently not used to build the dropdown, so Overdue and At least one target completed can be selected manually as well.

---

## 2. The active flag

Every status has a fixed `active` value (table above). When a status is set, the assignment's **Active** flag is set with it:

- **Active = 1** (Assigned, Enrolled, Prolonged, At least one target completed, Overdue, Reprimand, Sanctioned, Completed): the assignment appears in the default "My Assignments" and supervisor tables, is counted in the chart and is considered when a completion event arrives.
- **Active = 0** (Planned, Not relevant, Paused, Droppedout): the assignment is hidden from the default tables (shortcode argument `active=2` shows all), is ignored by completion detection, and receives no messages.

Two exceptions to "status decides the flag":

- A **Completed** assignment that is dropped out or paused keeps status Completed and only gets **Active = 0**. When the user comes back it is switched to Active = 1 again, still Completed. The training is never lost.
- The **Keep changes of the date on import of data** checkbox protects `Active` (together with the due date) from automatic updates — see [03 — Edit assignment](03-edit-assignment.md).

On the "My Assignments" table a notice *"Attention: The assignment for the package(s)/course(s) "…" has/have been deleted."* lists targets that only occur in the user's inactive assignments.

---

## 3. Transitions — what moves an assignment

| Trigger | From | To | Notes |
|---|---|---|---|
| Rule saved/enabled, user joins the unit, user starts matching the filter — and no assignment exists yet | – | **Assigned**, or **Planned** if the rule has a **Delay of activation** | Assignment date = now, due date = fixed date or now + duration; targets are enrolled/booked immediately (Assigned only); scheduled messages and the due-date check are queued. |
| Delay of activation has passed (adhoc task) | Planned | **Assigned** | Assignment date = now, due date computed from now; targets enrolled; completions achieved during the Planned phase are recognised on the next re-evaluation. |
| User is booked into a booking-option target | Assigned (any active status < 3) | **Enrolled** | TU Wien: Enrolled is excluded, so the assignment stays Assigned. |
| A target is completed (course completion, competency rated, booking option completed) — not all | Assigned, Enrolled | **At least one target completed** | TU Wien: excluded → stays Assigned. Prolonged and Overdue keep their status (see section 4). |
| All targets completed | Assigned, Enrolled, At least one target completed, Prolonged, Reprimand, Sanctioned | **Completed** | Completed date = now; event *Assignment completed* (messages "on status change", cyclic timer). |
| All targets completed while **Overdue** | Overdue | **Completed** only if **Overdue assignments can still be completed** (`allowoverduecompletion`, default on) is enabled; otherwise stays Overdue | The completion is still recorded on the targets. |
| Due date passed (adhoc `check_assignment_status` at the due date) | Assigned, Enrolled, At least one target completed, Prolonged, Reprimand, Sanctioned, Overdue | **Overdue** — overdue counter +1 | Not for Planned, Paused, Completed. **TU Wien (tuines)** with **Use prolonged state**: the *first* overrun of an assignment with an **Extension period** becomes **Prolonged** instead (due date + extension period), and only the second overrun becomes Overdue. |
| Completion revoked (booking option un-completed, course completion reset) | Completed | recomputed: **Assigned**, **At least one target completed**, **Prolonged** or **Overdue** depending on the remaining targets and the current date | History entry `competency_uncompleted`; the "Completed" message record is removed so it can be sent again. |
| User leaves the unit, stops matching the filter, is missing from the import, is deleted | any active, not Completed | **Droppedout** — counters 0, dates cleared, scheduled messages removed | Completed → only Active = 0. Standard/KSW: import removes the unit → Droppedout; KSW additionally removes the cohort membership. TU Wien: also for persons missing from the nightly feed (they are suspended). |
| User re-enters the unit / matches the filter again | Droppedout | **Assigned** — assignment date = now, due date recomputed, counters 0; "assigned" messages sent again | Completed inactive → Completed active. Re-entry through a rule/unit re-evaluation works with every adapter; KSW and TU Wien additionally re-activate the assignments of regained units directly during the import. |
| Contract ended or long leave (adapter import) | any except Completed, Droppedout, Not relevant, Planned | **Paused** — counters 0, dates cleared, scheduled messages removed | Completed → Active = 0 only. Users on long leave also get **no new** assignments. |
| Leave ended / contract end moved into the future (import) | Paused | **Assigned** — counters 0, due date = now + duration, messages re-scheduled, rules re-evaluated | TU Wien and Standard/KSW. |
| "Not relevant for me" request confirmed | any | **Not relevant** — dates cleared, Active = 0 | Stable afterwards: imports and re-evaluation never change it. A confirmed **prolongation** request changes nothing by itself — the supervisor/admin extends the due date on the edit page. |
| Manual change on the edit page | any | the chosen status | Logged as *Manual change* in the history; see section 7. |
| Cyclic rule: validation duration after completion | Completed | **Assigned** — user un-enrolled from all targets, overdue counter 0, sent messages cleared; due date kept | See [06 — Cyclic assignments](06-cyclic-assignments.md). |
| Rule re-saved / unit updated / user profile updated (re-evaluation) with **Keep changes** off | Droppedout (user still in unit and matching), Enrolled, At least one target completed, Reprimand, Sanctioned | recomputed from the targets, usually **Assigned** | Assigned, Overdue, Prolonged, Not relevant, Completed, Paused and Planned stay as they are. With **Keep changes** on, every status is kept. |

Numeric order matters in two places: the due-date check only touches assignments whose code lies strictly between Planned (-1) and Completed (15) and that are not Paused; the booking event only upgrades assignments with a code below Enrolled (3). The supervisor's **To clarify** view is defined as *Overdue with overdue counter ≤ 1 and prolonged counter ≤ 2*.

---

## 4. How the status is recomputed

Whenever a completion-related event arrives or an assignment is re-evaluated, Taskflow checks every target of the assignment and decides the new status in this fixed order:

1. If the stored status is **Overdue** and (not all targets are met **or** `allowoverduecompletion` is off) → stays **Overdue**.
2. If the stored status is **Paused** or **Not relevant** → unchanged.
3. All targets met → **Completed** (fires *Assignment completed* if it was not completed before).
4. Due date already passed → **Overdue**.
5. Stored status **Prolonged** → stays **Prolonged**.
6. Prolonged counter > 0 → **Prolonged**.
7. Some targets met → **At least one target completed** (unless excluded by **Do not use status**).
8. No target met → **Assigned** (unless excluded).

Rule 1 is why a user who finishes a course after the deadline can still be marked Overdue when `allowoverduecompletion` is disabled; the target itself is shown as *completed* on the detail page. Rules 5–6 are why a prolonged assignment does not fall back to Assigned when nothing has happened.

---

## 5. What each status does to dates and counters

| Status | Assignment date | Due date | Counters | Scheduled messages |
|---|---|---|---|---|
| Planned | cleared | cleared | – | none until opened |
| Assigned (from Planned / Droppedout / Paused) | now | recomputed (fixed date, or now + duration) | Droppedout/Paused re-entry: both 0 | re-scheduled ("assigned" messages are sent again) |
| Assigned (cyclic reset after Completed) | kept | **kept** from the previous cycle (see [06 — Cyclic assignments](06-cyclic-assignments.md)) | overdue counter 0 | sent-records cleared; "assigned" message sent again |
| Enrolled | – | – | – | – |
| At least one target completed | – | – | – | – |
| Prolonged | – | extended (manual date or + extension period) | prolonged counter +1 whenever the saved due date is later than before | due-date check rescheduled |
| Overdue | – | – | overdue counter +1 (only while it is 0 or lower than the prolonged counter) | – |
| Reprimand / Sanctioned | – | – | – | – |
| Completed | – | kept | – | scheduled "before/after due date" messages are no longer valid |
| Paused | cleared | cleared | both 0 | removed |
| Droppedout | cleared | cleared | both 0 | removed |
| Not relevant | cleared | cleared | – | not sent (assignment no longer valid) |

---

## 6. Chart grouping and sorting

The doughnut chart on the dashboards (**Overview** card, user stats card) counts only **active** assignments and groups them as:

- **Overdue** = Overdue (10)
- **Assigned** = Assigned (0), Enrolled (3), Prolonged (5), At least one target completed (7)
- **Completed** = Completed (15)

Reprimand and Sanctioned are not charted. In the tables the **Status** column sorts by *status + counter* and shows the counter in brackets for Prolonged and Overdue, e.g. "Overdue (2)". The status filter on the tables offers the same list as the **Change status** dropdown (codes ≥ 0 minus excluded statuses).

---

## 7. Manual status changes and their reasons

Admins (and, depending on the adapter, supervisors) change the status on [/local/taskflow/editassignment.php?id=<assignmentid>](/local/taskflow/editassignment.php?id=<assignmentid>). Typical reasons:

| Situation | What to set |
|---|---|
| Employee was ill or on holiday and could not meet the deadline | **Prolonged** with a later **Due date** and **Reason** *Sickness* or *Holidays* |
| Training was done outside Moodle | **Completed** with a comment |
| Employee should be temporarily exempt | **Paused** (clears the due date; a later import that ends the leave will reactivate it) |
| Formal escalation steps of the organisation | **Reprimand**, then **Sanctioned** — both are informational statuses without automatic behaviour, but "on status change" messages can be attached to them |
| Employee left the team but the import has not caught up | **Droppedout** |

The form's **Reason** field offers *Sickness* (1), *Holidays* (5) and *Other* (10); reason, comment and new status are recorded in the history ([04 — History](04-history.md)). Changing the status by hand also triggers the rule's messages configured for that status ("on event" messages).

> **Note:** a manual status change is written to the history with the first user id from the setting **HR userids** as actor (if that setting is filled), not with the id of the person who used the form. The separate *Manual change* row written by the form carries the real actor.

---

## 8. Excluded statuses ("Do not use status")

The adapter setting `taskflowadapter_<adapter>/excludestatus` — UI label **Do not use status**, description *"Status changes to the following statuses will not be executed"* — is a list of status codes that Taskflow must never set:

- they disappear from the **Change status** dropdown and from the table status filter;
- every automatic or manual transition *to* such a status is silently skipped (the assignment keeps its previous status).

**Standard/KSW:** the setting is not shown in the settings UI and is empty by default — all statuses are in use. **TU Wien (tuines):** the setting is part of the *INES API Settings* and the reference configuration excludes `3,7` (Enrolled and At least one target completed). Consequences at TU Wien: booking a training does not change the status, and completing one of two required competencies leaves the assignment **Assigned**; the "Partially" message is never sent.

---

## 9. Worked example

Rule "Fire safety basics": **Due date type** Duration, **Duration** 4 weeks, **Extension period** 2 weeks, one booking-option target, "on status change" message for Assigned, warning messages 10 and 5 days before the due date, an overdue message 10 minutes after it.

| Date | Event | Status | Active | Due date | Counters (overdue / prolonged) |
|---|---|---|---|---|---|
| 02.03.2026 | Anna joins unit "Facility", rule applies, no filter blocks her | Assigned | 1 | 30.03.2026 | 0 / 0 |
| 02.03.2026 +5 min | "Assigned" message sent | Assigned | 1 | 30.03.2026 | 0 / 0 |
| 05.03.2026 | Anna is booked into the option (automatically, by the assignment) | Enrolled — *TU Wien: stays Assigned* | 1 | 30.03.2026 | 0 / 0 |
| 20.03. / 25.03.2026 | Warning 1 / Warning 2 sent | unchanged | 1 | 30.03.2026 | 0 / 0 |
| 30.03.2026 23:59 | Due date passes, check task runs | **Standard/KSW:** Overdue — **TU Wien** (Use prolonged state on): Prolonged | 1 | Standard/KSW: 30.03.2026 — TU Wien: 13.04.2026 | Standard/KSW: 1 / 0 — TU Wien: 0 / 1 |
| 13.04.2026 | (TU Wien) extended due date passes | Overdue | 1 | 13.04.2026 | 1 / 1 |
| 15.04.2026 | Supervisor grants an extension on the edit page (TU Wien) / admin moves the due date to 30.04.2026 (Standard/KSW) | Prolonged | 1 | 27.04.2026 (TU Wien) / 30.04.2026 | 1 / 2 (TU Wien) / 1 / 1 |
| 20.04.2026 | Anna completes the training | Completed | 1 | unchanged | unchanged |
| 01.07.2026 | Anna moves to another unit without this rule | Completed | **0** | unchanged | unchanged |

Had Anna gone on long leave on 10.03.2026, the row for that day would read *Paused, Active 0, due date cleared, counters 0 / 0*; on her return the assignment restarts as Assigned with a new due date = return date + 4 weeks.

---

## Related

- [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md) — the mechanics behind Prolonged, Overdue, Paused and Droppedout.
- [03 — Edit assignment](03-edit-assignment.md) — the forms that set statuses manually.
- [Rules — 04 Messages step](../rules/04-messages-step.md) — messages triggered on a status change.
- [Adapters — TU Wien (tuines)](../adapters/tuines.md) — **Use prolonged state**, **Do not use status**, extension limits for supervisors.
