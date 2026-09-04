[Back to chapter overview](README.md)

# 06 — Cyclic assignments

> **Primary page** for: rules that must be repeated regularly (**Does rule need cyclic validation?** / **Validation duration**), how a completed assignment is reset and reopened, how old completions are credited, sequential targets (**complete before next**), and what a rule change or deletion does to existing assignments. For the rule form itself see [Rules — 01 Rule step](../rules/01-rule-step.md).

---

## Table of contents

1. [What a cyclic rule is](#1-what-a-cyclic-rule-is)
2. [Completion within the validation window](#2-completion-within-the-validation-window)
3. [Reset and reopening](#3-reset-and-reopening)
4. [Crediting completions from before the rule existed](#4-crediting-completions-from-before-the-rule-existed)
5. [Sequential targets — complete before next](#5-sequential-targets--complete-before-next)
6. [Rule changes and rule deletion](#6-rule-changes-and-rule-deletion)
7. [Worked example](#7-worked-example)

---

## 1. What a cyclic rule is

In the rule step:

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Does rule need cyclic validation?** | checkbox (`cyclicvalidation`) | off | The training must be repeated. |
| **Validation duration** | duration (`cyclicduration`) | 1 year | How long a completion stays valid. Shown only when the checkbox is on. |

Typical use: yearly fire-safety or hygiene refreshers, two-yearly first-aid courses. A cyclic rule produces the same single assignment per user as any other rule; the assignment is **reopened** instead of a new one being created, so the history of all cycles stays on one record.

---

## 2. Completion within the validation window

For a cyclic rule a target only counts as completed if the completion is **younger than the validation duration**:

| Target type | Counts as completed when… |
|---|---|
| Booking option | the user's last completion of the option is younger than the validation duration |
| Competency | the user completed a booking option carrying the competency within the validation duration, **or** has approved evidence whose **Valid until date** is empty or in the future |
| Moodle course | Moodle course completion is set — there is no age check for course targets; Taskflow removes the completion when it reopens the assignment (section 3) |

Example: validation duration 212 days, the user completed the booking option 1 year ago → the target is *not* completed, the assignment is created as **Assigned**.

---

## 3. Reset and reopening

When a cyclic assignment becomes **Completed**, Taskflow schedules an adhoc task **reset_cyclic_assignment** for *completion time + validation duration*. When it runs (and the rule is still cyclic):

1. The user is **un-enrolled** from every target: manual course enrolment removed together with the course completion and activity completion records; booking answer deleted; the competency and its evidence links removed.
2. The overdue counter is set to 0 and the status becomes **Assigned**. The completed date of the previous cycle stays on the record; the prolonged counter is not touched.
3. The "already sent" records of the rule's messages are cleared, so the "Assigned" message is sent again with the status change and the warnings can be sent again for the new cycle.
4. The due-date check is queued for the assignment's due date.

The reset itself does **not** re-enrol the user and does **not** compute a new due date:

- **Assignment date and due date are kept** from the previous cycle. With a *Duration* rule that stored due date is normally in the past, so the reopened assignment becomes **Overdue** at the next due-date check (next cron run) — the overdue message is then sent, and the supervisor / admin sets the new deadline on the edit page ([03 — Edit assignment](03-edit-assignment.md)). With a *Fixed date* rule the due date is the fixed date; move it forward in the rule (with `recursive` on) for the next cycle.
- **Enrolment into the targets** happens at the next re-evaluation of the rule for this user — a rule save, a unit or profile update, the nightly HR import (TU Wien: every imported unit is re-evaluated) or the `checkstatus` action ([02 — Assignment detail page](02-assignment-detail-page.md), section 8). Booking-option targets that are no longer bookable (past, full) are not re-booked; the target shows as *Not completed* until the user books a suitable option.

> **Note:** if you expect reopened cyclic assignments to start a fresh period automatically, check the due date after the first reset in your installation; the current behaviour is to keep the old date.

If the cyclic checkbox was switched off in the meantime the task does nothing and the assignment stays Completed.

---

## 4. Crediting completions from before the rule existed

When a cyclic rule with **competency targets only** is applied to a user who has **already completed** booking options carrying all those competencies, the assignment is created as already **Completed** with historic dates:

- assignment date = date of the first matching completion, completed date = date of the last one;
- a history entry *Assignment status changed* with the comment *"Assignment was completed on <date>"*;
- if the last completion is still within the validation duration, the reset task is scheduled for *last completion + validation duration*; if it is already older, the assignment is reopened immediately (→ Assigned).

Users with a completion outside the window or with a booking option carrying the wrong competency start as Assigned like everyone else. Non-cyclic rules also recognise earlier completions (the assignment is created directly as Completed), but without the historic dates.

---

## 5. Sequential targets — complete before next

Each target in the **Targets** step has a checkbox **complete before next** (`completebeforenext`). Taskflow processes the targets in the order of the list:

- The user is enrolled/booked into the targets one after another; enrolment **stops** after a target that has *complete before next* on and is not yet completed.
- When that target is completed, the completion event enrols the next target(s) immediately, again stopping at the next *complete before next* target.
- A target without the flag lets enrolment continue to the following target right away.

Example with three courses and flags *on, on, off*: the user is enrolled in course 1 only; completing it enrols course 2; completing course 2 enrols course 3; completing course 3 completes the assignment. On the detail page all three targets are listed from the start, the not-yet-enrolled ones as *Not completed*. The status logic is unchanged: while some targets are completed the assignment is **At least one target completed** (or stays Assigned at TU Wien), the due date applies to the whole chain. Details of the target types are in [Rules — 03 Targets](../rules/03-targets.md).

---

## 6. Rule changes and rule deletion

**Saving a rule again** (`update_rule`): all users of the rule's unit(s) are re-evaluated.

| Situation | Effect on existing assignments |
|---|---|
| **Enable or disable rule will also effect exsisting assignments** (`recursive`) **off** (default) | Existing assignments keep their due date; only users without an assignment get one. Existing statuses are recomputed from the targets unless **Keep changes** is on ([03 — Edit assignment](03-edit-assignment.md), section 7); Assigned/Overdue/Prolonged/Completed/Paused/Not relevant/Planned stay. |
| `recursive` **on** | Changed duration / fixed date is applied to existing assignments as well (Prolonged assignments keep their extended date); users who no longer match the filter are set to **Droppedout**, users who newly match get an assignment. |
| **Enable rule** unchecked (rule disabled) | No new assignments; with `recursive` on, existing active assignments are set to Droppedout. |
| Cyclic checkbox switched **off** | Pending reset tasks do nothing; completed assignments stay Completed. |
| Cyclic checkbox switched **on** | Applies to completions from now on (the reset is scheduled when an assignment becomes Completed). |
| Messages changed | New sending settings are used for existing assignments (a newly added "100 days after due date" message is sent once when due). |
| Targets changed | The new target list is copied to the assignments on re-evaluation; completion flags are recomputed. |

Re-saving a rule never adds history rows or changes timestamps of assignments whose result is unchanged.

**Deleting a rule** (trash icon on the rules dashboard, adhoc task `removed_rule`): the rule row **and all its assignments are deleted** — not dropped out. History rows, sent-message records and chat messages of those assignments remain in the database but are no longer reachable. Users stay enrolled in the targets. Prefer disabling the rule when the assignments should remain visible.

---

## 7. Worked example

Rule "Hygiene refresher": cyclic, **Validation duration** 365 days, **Duration** 30 days, one booking-option target carrying competency *Hygiene*, messages *Assigned*, *Warning 1* (10 days before), *Overdue* (after).

| Date | Event | Status | Due date | Notes |
|---|---|---|---|---|
| 10.01.2025 | Betty completes a hygiene course (booking option with the competency) — no rule yet | – | – | |
| 01.09.2025 | Rule created; Betty is in the unit | **Completed** | 09.02.2025 (historic: first completion + 30 days) | Created directly as completed; history "Assignment was completed on 10.01.2025"; reset scheduled for 10.01.2026 |
| 01.09.2025 | Colleague Carl (no earlier completion) | Assigned | 01.10.2025 | Assigned mail |
| 10.01.2026 | Reset task for Betty runs | **Assigned** | 09.02.2025 (unchanged) | Booking answer removed, overdue counter 0, Assigned mail sent |
| 10.01.2026 (next cron) | Due-date check finds the old due date in the past | **Overdue** | 09.02.2025 | Overdue mail; the case appears in the supervisor's **To clarify** list |
| 12.01.2026 | HR sets **Change status** Prolonged and **Due date** 11.02.2026 on the edit page | **Prolonged** | 11.02.2026 | Nightly import re-evaluates the rule → Betty is booked into the next hygiene course |
| 25.01.2026 | Betty completes the course | **Completed** | 11.02.2026 | Reset scheduled for 25.01.2027 |

Carl's own cycle starts when he completes for the first time: Completed on, say, 20.09.2025 → reset on 20.09.2026 → Assigned with the old due date 01.10.2025 → Overdue at the next check until a new due date is set.

---

## Related

- [01 — Status lifecycle](01-status-lifecycle.md) — Completed → Assigned reopening in the transition table.
- [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md) — the due date of each new cycle.
- [Rules — 01 Rule step](../rules/01-rule-step.md) — cyclic fields, `recursive`, enabling a rule; [Rules — 03 Targets](../rules/03-targets.md) — complete before next.
- [Competencies and certificates](../competencies_and_certificates/README.md) — evidence validity dates.
- [Scheduled tasks](../scheduled_tasks/README.md) — `reset_cyclic_assignment`, `update_rule`, `removed_rule`.
