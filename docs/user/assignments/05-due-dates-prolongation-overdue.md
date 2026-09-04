[Back to chapter overview](README.md)

# 05 — Due dates, prolongation, overdue

> **Primary page** for: how the due date of an assignment is calculated (duration vs fixed date, activation delay), what **Extension period**, **Prolonged**, **Overdue** and the two counters do, the settings `allowoverduecompletion` and `usingprolongedstate`, and the inactive states **Paused** (contract end / long leave) and **Droppedout** with re-entry. The rule fields themselves are documented in [Rules — 01 Rule step](../rules/01-rule-step.md); the status names in [01 — Status lifecycle](01-status-lifecycle.md).

---

## Table of contents

1. [Due date types](#1-due-date-types)
2. [Delay of activation — Planned assignments](#2-delay-of-activation--planned-assignments)
3. [The due-date check](#3-the-due-date-check)
4. [Overdue and the overdue counter](#4-overdue-and-the-overdue-counter)
5. [Extension period and Prolonged](#5-extension-period-and-prolonged)
6. [Completing after the deadline — allowoverduecompletion](#6-completing-after-the-deadline--allowoverduecompletion)
7. [Paused — contract end and long leave](#7-paused--contract-end-and-long-leave)
8. [Droppedout and re-entry](#8-droppedout-and-re-entry)
9. [Worked examples](#9-worked-examples)

---

## 1. Due date types

The rule step offers **Due date type**:

| Due date type | Field | Due date of the assignment |
|---|---|---|
| **Duration** (default) | **Duration**, default 4 weeks | Assignment date + duration. For a new assignment the assignment date is the moment it was created; after a Planned, Paused or Droppedout phase it is the moment it was (re)opened. |
| **Fixed date** | **Fixed date**, default now + 4 weeks | The same calendar date for everyone, regardless of when they were assigned. Users who join after the fixed date are Overdue at the first check. |

If a rule has neither (older rules), the due date is 0 — such assignments never become Overdue and no due-date related messages are sent.

An existing assignment keeps its due date when the rule is re-saved, unless the rule option **Enable or disable rule will also effect exsisting assignments** (`recursive`) is on — then a changed duration or fixed date is applied to existing assignments too, except for assignments currently **Prolonged**, whose extended due date is always kept. A manually set due date is additionally protected by **Keep changes of the date on import of data** ([03 — Edit assignment](03-edit-assignment.md), section 7).

The assignment date, not the due date, is also the anchor for messages sent "after start" ([Rules — 04 Messages step](../rules/04-messages-step.md)).

---

## 2. Delay of activation — Planned assignments

**Delay of activation** (`activationdelay`, hours or days, default 0) postpones the start:

- The assignment is created with status **Planned** (inactive, no assignment date, no due date). Nothing is enrolled, no messages are scheduled.
- After the delay an adhoc task opens it: status **Assigned**, assignment date = now, due date = now + duration (or the fixed date), targets are enrolled, messages scheduled.
- Completions the user achieved during the Planned phase are recognised at the first re-evaluation after opening.

Use it for onboarding rules ("start the safety training two weeks after the entry date"). Planned assignments cannot be paused or dropped out by imports and are not offered in the manual status form.

---

## 3. The due-date check

Every time an assignment gets or changes a due date, Taskflow queues an adhoc task **check_assignment_status** for exactly that due date (or immediately if the date is already past). Cron executes it and applies the rule below. If cron does not run at the due date the check happens with the next cron run — so the status may flip a few minutes late, never early. The check is skipped for Planned, Completed and Paused assignments and for assignments whose due date is still in the future (e.g. after a manual extension).

---

## 4. Overdue and the overdue counter

When the check finds `due date < now` for an active, uncompleted assignment, the status becomes **Overdue** and the **overdue counter** increases by 1 — but only while the counter is 0 or lower than the prolonged counter. The counter therefore counts the *distinct* overruns (initial deadline, then each extended deadline), not repeated checks.

Once Overdue, the assignment stays Overdue until

- all targets are completed (and `allowoverduecompletion` allows it — section 6),
- an admin or supervisor sets status Prolonged with a later due date (section 5),
- an import pauses or drops it out (sections 7–8),
- or an admin sets another status manually.

Messages: the "after end" message of the rule (e.g. *Overdue* 10 minutes after the due date) and any "on status change → Overdue" message are sent; see [Messages](../messages/README.md). For supervisors an Overdue assignment with overdue counter ≤ 1 and prolonged counter ≤ 2 is a **To clarify** case on the dashboard.

---

## 5. Extension period and Prolonged

**Extension period** (`extensionperiod`, default 4 weeks) is the standard length of a deadline extension. It is used in three ways:

1. **Default of the due-date field** on the edit page: the date selector is pre-filled with *today + extension period*, so granting the standard extension is one click.
2. **Automatic first extension — TU Wien (tuines) only.** With the adapter setting **Use prolonged state** (`taskflowadapter_tuines/usingprolongedstate`, *"Use prolonged state to mark first automated expansion of due date"*) enabled, the first time an assignment passes its due date it does **not** become Overdue: the status becomes **Prolonged**, the due date is extended by the extension period, the prolonged counter becomes 1 and a new check is queued. Only when the extended date passes does it become Overdue (overdue counter 1). Conditions: extension period > 0, prolonged counter still 0, status not already Prolonged/Overdue. **Standard/KSW:** the setting is not offered; the first overrun goes straight to Overdue ("in KSW we have no extension period" — the extension period only serves as default for manual extensions).
3. **Supervisor extension — TU Wien:** the **Grant Extension** button on the edit page proposes *current due date + extension period* (or *today 23:59 + extension period* if the due date is already past) and sets **Prolonged**.

**Prolonged** in general:

- The **prolonged counter** increases by 1 each time the status Prolonged is applied with a due date later than the stored one — automatically, via **Grant Extension**, or via the admin form. **TU Wien:** a supervisor's **Deny Extension** also increments the counter without changing the date, so two decisions (grant or deny) exhaust the supervisor's options (form shown while counter < 2).
- A Prolonged assignment keeps its status through re-evaluations and partial completions; it changes only when everything is completed (→ Completed) or the extended due date passes (→ Overdue).
- An assignment with prolonged counter > 0 that is neither Overdue nor Completed is shown as Prolonged even after a re-evaluation.
- On the Standard/KSW form choose **Change status** *Prolonged* together with a later **Due date** to extend an Overdue assignment; the counter is incremented because the due date moved later. Moving only the date while leaving the status on Overdue does not switch the status to Prolonged.

The placeholder `<due_date_with_extension>` in messages renders *due date + extension period* — useful for the warning text ("you may complete it until <date> at the latest").

---

## 6. Completing after the deadline — allowoverduecompletion

Site setting **Overdue assignments can still be completed** (`local_taskflow/allowoverduecompletion`, default **on**, *"If enabled, users are allowed to mark assignments as completed even after the due date has passed."*):

- **On:** an Overdue assignment becomes **Completed** as soon as all targets are completed.
- **Off:** an Overdue assignment stays **Overdue** even when all targets are completed. The targets are still shown as *completed* on the detail page; only an admin can set the status to Completed by hand. Use this when a missed deadline must remain visible for HR follow-up.

The setting applies to Overdue only. Prolonged assignments always complete normally.

---

## 7. Paused — contract end and long leave

**Paused** is the status for "the person is temporarily not with us". It is set

- automatically by the HR import when the person's **contract end** date lies in the past or the **long leave** flag is set (all adapters that map these functions — see [Adapters](../adapters/README.md) and [Units and users](../units_and_users/README.md));
- manually on the edit page (reason *Sickness* / *Holidays*).

Effects: inactive (hidden from default lists), assignment date and due date cleared, both counters reset to 0, all scheduled messages of the assignment removed, no overdue check. Completed, Droppedout, Not relevant and Planned assignments are not paused (a Completed one only becomes inactive). A person who is **already** on long leave when first imported gets **no** assignments at all.

Return: when the import sees the long leave flag cleared or the contract end moved into the future, all Paused assignments of the person become **Assigned** with counters 0 and a fresh due date = return date + duration, messages are re-scheduled ("Assigned" mail is sent again) and the person's rules are re-evaluated. The unit memberships that were deactivated with the leave are reactivated.

Adapter notes: **Standard/KSW:** contract end additionally **suspends** the Moodle account; the long-leave field is a boolean profile field. **TU Wien (tuines):** `contractEnd` `9999-12-31` means open-ended; `currentlyOnLongLeave` drives the pause; suspended/missing persons are handled by the separate *missing persons* check (section 8). Manually suspending a Moodle user does **not** pause assignments — only contract end / long leave do.

---

## 8. Droppedout and re-entry

**Droppedout** means "the rule no longer applies to this person". It is set when

- the user is removed from the unit / cohort of the rule (import with a changed org path or target group; cohort membership removed; unit deleted),
- the user stops matching the rule's **filter** (profile change, daily re-check of date filters),
- the rule is disabled/re-saved with **Enable or disable rule will also effect exsisting assignments** and the user no longer qualifies,
- **TU Wien:** the person is missing from the nightly feed (account suspended, cohorts removed) or the target group list is empty,
- the Moodle user is deleted,
- or an admin chooses it manually.

Effects: inactive, dates cleared, counters 0, scheduled messages removed and the "already sent" records of the rule's messages cleared. A **Completed** assignment is not dropped out — it only becomes inactive and keeps everything.

Re-entry (user back in the unit / matching again / present in the feed): the same assignment is reopened as **Assigned** with assignment date = now, due date = now + duration (or the fixed date), counters 0; because the sent-message records were cleared, the "Assigned" message and the warnings are sent again. An inactive Completed assignment is simply switched back to active. **KSW** and **TU Wien** perform this re-activation directly during the import; with every adapter it also happens when the rule or unit is re-evaluated.

Deleting the **rule** is different: its assignments are deleted, not dropped out ([06 — Cyclic assignments](06-cyclic-assignments.md), section 6).

---

## 9. Worked examples

**A — Standard/KSW, duration 10 days, extension period 5 days, one course target**

| Date | Event | Status | Due date | Counters (overdue / prolonged) |
|---|---|---|---|---|
| 01.06.2026 | Olaf is assigned | Assigned | 11.06.2026 | 0 / 0 |
| 11.06.2026 | Due date passes | **Overdue** | 11.06.2026 | 1 / 0 |
| 12.06.2026 | Admin opens the edit page; **Due date** is pre-filled with 17.06.2026 (today + 5 days); saves with reason *Sickness* | **Prolonged** | 17.06.2026 | 1 / 1 |
| 17.06.2026 | Extended date passes | Overdue | 17.06.2026 | 1 / 1 (counter unchanged: overdue counter 1 is not lower than prolonged counter 1) |
| 20.06.2026 | Olaf completes the course (`allowoverduecompletion` on) | **Completed** | 17.06.2026 | 1 / 1 |

With `allowoverduecompletion` **off** the last row would stay *Overdue* until an admin sets Completed.

**B — TU Wien, same rule, Use prolonged state on**

| Date | Event | Status | Due date | Counters |
|---|---|---|---|---|
| 01.06.2026 | Peter is assigned | Assigned | 11.06.2026 | 0 / 0 |
| 11.06.2026 | Due date passes → automatic first extension | **Prolonged** | 16.06.2026 | 0 / 1 |
| 16.06.2026 | Extended date passes | **Overdue** | 16.06.2026 | 1 / 1 |
| 17.06.2026 | Supervisor sees **Edit** (counters 1 / 1) and clicks **Deny Extension** with a comment | Overdue | 16.06.2026 | 1 / 2 |
| 18.06.2026 | Supervisor reconsiders, **Grant Extension** (reason *Other*): proposed date = 18.06. 23:59 + 5 days | **Prolonged** | 23.06.2026 | 1 / 3 |
| 23.06.2026 | Extended date passes | Overdue | 23.06.2026 | 2 / 3 |

After the second decision the supervisor's form is gone (counter ≥ 2); the case is now handled by HR on the admin form. Between 16.06. and 17.06. the assignment is a **To clarify** case (Overdue, counters ≤ 1 / ≤ 2).

**C — Long leave, duration 90 days (TU Wien)**

| Date | Event | Status | Active | Due date |
|---|---|---|---|---|
| 01.02.2026 | Sara is assigned | Assigned | 1 | 02.05.2026 |
| 15.03.2026 | Import: `currentlyOnLongLeave: true` | **Paused** | 0 | – |
| 15.03.–10.09.2026 | Nightly imports, due date passes | Paused (no overdue, no mails) | 0 | – |
| 11.09.2026 | Import: long leave false | **Assigned** (counters 0, "Assigned" mail again) | 1 | 10.12.2026 (= return + 90 days) |

---

## Related

- [01 — Status lifecycle](01-status-lifecycle.md) — the full transition table.
- [03 — Edit assignment](03-edit-assignment.md) — manual extensions, Keep changes.
- [Rules — 01 Rule step](../rules/01-rule-step.md) — Due date type, Duration, Fixed date, Extension period, Delay of activation.
- [Messages](../messages/README.md) — warnings before and notices after the due date.
- [Units and users](../units_and_users/README.md) — long leave and contract end fields.
- [Scheduled tasks](../scheduled_tasks/README.md) — the adhoc tasks behind the checks.
