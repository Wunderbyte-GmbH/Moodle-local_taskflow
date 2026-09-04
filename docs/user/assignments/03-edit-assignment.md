[Back to chapter overview](README.md)

# 03 — Edit assignment

> **Primary page** for: `/local/taskflow/editassignment.php?id=<assignmentid>` — changing status, reason, due date and comment of one assignment by hand, the supervisor's extension decision (TU Wien), the comment form, the chat and the history panel on that page. What the statuses mean is in [01 — Status lifecycle](01-status-lifecycle.md); how due dates and counters behave afterwards in [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md).

---

## Table of contents

1. [Access and how to get there](#1-access-and-how-to-get-there)
2. [Admin variant vs supervisor variant](#2-admin-variant-vs-supervisor-variant)
3. [Assignment data card](#3-assignment-data-card)
4. [Status change form — Standard/KSW](#4-status-change-form--standardksw)
5. [Status change form — TU Wien admin](#5-status-change-form--tu-wien-admin)
6. [Grant / Deny extension — TU Wien supervisor](#6-grant--deny-extension--tu-wien-supervisor)
7. [Keep changes of the date on import of data](#7-keep-changes-of-the-date-on-import-of-data)
8. [Comment form and comment history (TU Wien)](#8-comment-form-and-comment-history-tu-wien)
9. [Internal Chat and history panel](#9-internal-chat-and-history-panel)
10. [What happens when you save](#10-what-happens-when-you-save)

---

## 1. Access and how to get there

- **Link:** the edit icon (Standard/KSW) or **Edit** link (TU Wien) in the **Actions** column of every assignments table. It is shown to users with `local/taskflow:editassignment` and to the assignee's supervisor. **TU Wien:** the supervisor sees **Edit** only while `overduecounter ≤ 1` and `prolongedcounter = 1`, i.e. after exactly one prolongation event — the moment an extension decision is due; admins always see it.
- **URL:** [/local/taskflow/editassignment.php?id=<assignmentid>](/local/taskflow/editassignment.php?id=<assignmentid>) (optionally `&returnurl=…`).
- **Who may open the page:** users with `local/taskflow:viewassignment` (system level) **or** the assignee's supervisor (the user in the supervisor profile field — deputies do not qualify). Everyone else gets *"Insufficient permissions!"*.

Opening the page marks the assignment as *seen* for the chat indicators. See [Capabilities](../capabilities/README.md) for the recommended role setup.

---

## 2. Admin variant vs supervisor variant

The page picks one of two data/form sets:

| Viewer | Variant |
|---|---|
| Has `local/taskflow:editassignment` — or is not the supervisor but has `viewassignment` | **Admin variant** |
| Is the assignee's supervisor and does **not** have `editassignment` | **Supervisor variant** |

Which forms the variants contain depends on the adapter (setting **External api with user data**):

| | Standard | KSW | TU Wien (tuines) |
|---|---|---|---|
| Admin variant | status change form + history | same as Standard | TU Wien admin form + **Comment** form with comment history + **Internal Chat** + history |
| Supervisor variant | same page as admin, but the form is only shown with `local/taskflow:viewassignment` | same as Standard | **Grant Extension / Deny Extension** form; no comment form, no history |

Standard and KSW have no separate supervisor form; a supervisor without `viewassignment` sees the assignment data and the history but no form. The whole form is rendered only when the viewer has `local/taskflow:viewassignment`.

---

## 3. Assignment data card

The card **Assignment data** lists the facts of the assignment (read-only):

| Standard/KSW label | TU Wien label | Value |
|---|---|---|
| Full name | Full name | The assignee |
| Targets | Assigned Packages | Each target with *completed* / *not completed* |
| Name / Description | – | Name and description of the rule |
| Assignment date | – | When the assignment was (re)opened |
| Status | Status | Standard/KSW: **Active** / **Inactive** (the active flag) — TU Wien: the status name (e.g. *Prolonged*) |
| Last modified by | – | User who last changed the assignment |
| – | Due date | Current due date (d.m.Y) |

---

## 4. Status change form — Standard/KSW

The form below the card (heading none; submit button **Save changes**):

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Change status** | select, required | current status | All statuses with code ≥ 0 minus the adapter's **Do not use status** list: Assigned, Enrolled, Paused, Prolonged, At least one target completed, Overdue, Reprimand, Sanctioned, Completed, Droppedout. |
| **Reason** | select | – | *Sickness*, *Holidays*, *Other*. Purely informational; shown in the history. |
| **Comment** | textarea | – | Free text; stored as the history annotation ("<status>: <comment>"). |
| **Due date** | date selector | today + the rule's **Extension period** (if the rule has one, otherwise today) | New due date. Choosing **Prolonged** together with a later date is how you grant an extension on an Overdue assignment (the date alone does not change the status). |
| **Keep changes of the date on import of data** | checkbox | checked | See section 7. |

Effects of saving are listed in section 10. Typical uses are collected in [01 — Status lifecycle, section 7](01-status-lifecycle.md#7-manual-status-changes-and-their-reasons).

> **Note:** the KSW tests confirm that a status set through this form survives the next HR import for every status (with the default *Keep changes* on).

---

## 5. Status change form — TU Wien admin

Same purpose, slightly different fields:

| Field | Type | Default | Meaning |
|---|---|---|---|
| **Change status** | select, required | current status | As above, minus the excluded statuses (TU Wien default excludes Enrolled and At least one target completed). |
| **Reason** | select | – | *Holidays*, *Other*, *Sickness*. |
| **Comment** | textarea | – | Stored as history annotation. |
| **Extension until** | date selector | today + rule **Extension period** (or today) | The chosen day is saved as **23:59** of that day. |
| Keep changes | hidden, always **on** | – | Every manual edit at TU Wien is protected against the nightly import. |

If **Prolonged** or **Overdue** is chosen, the status logic of that status is applied first (prolonged counter +1 when the due date was extended; overdue counter +1), then the record is saved.

---

## 6. Grant / Deny extension — TU Wien supervisor

The supervisor's form answers an employee's extension request (or acts proactively). It is shown while the assignment has **prolongedcounter < 2** (a supervisor may act on at most two extension events); admins with `viewassignment` always see it.

Two collapsed sections:

**Grant Extension**

| Field | Meaning |
|---|---|
| **Reason** (required) | *Holidays*, *Other*, *Sickness* — validation: *"If the deadline is extended, a reason must be selected."* |
| **Comment** | Optional comment; the decline comment must stay empty. |
| **Extension until** (display only) | Proposed new due date = current due date + rule **Extension period** if the due date is still in the future, otherwise today 23:59 + extension period. Cannot be edited by the supervisor. |
| Button **Grant Extension** | Sets status **Prolonged** (prolonged counter +1), due date = proposed date, history *Manual change* with annotation "Request confirmed: <comment>". |

**Deny Extension**

| Field | Meaning |
|---|---|
| **Comment** (required) | Validation: *"When denying the extension request, a comment must be provided"*; the reason must stay empty. |
| Button **Deny Extension** | Status and due date unchanged, **prolonged counter +1**, history *Manual change* with annotation "Request denied: <comment>". |

Both sections show the TU Wien legal text about written warnings and employment-related consequences (`denytext`). Denying increments the prolonged counter so that the second denial ends the supervisor's possibility to act (the form disappears at counter 2) and the case becomes a *clarification case* for HR once it is Overdue with counters 1 / 2.

Neither button changes the request record on the requests dashboard; the request itself is confirmed or declined there (see [Requests](../requests/README.md)).

---

## 7. Keep changes of the date on import of data

The checkbox (`keepchanges`, label **Keep changes of the date on import of data**) protects your manual edit:

- With **Keep changes on**, automatic updates — the nightly HR import, a rule re-save, a unit update, a profile update, `checkstatus` — do **not** overwrite the assignment's **due date** and **active** flag, and skip the status recomputation from the targets. Manual edits on this page always apply.
- With **Keep changes off**, the next re-evaluation recomputes the status from the targets. Statuses Droppedout, Enrolled, At least one target completed, Reprimand and Sanctioned are then reset to what the targets say (usually **Assigned**); Assigned, Overdue, Prolonged, Not relevant, Completed, Paused and Planned are stable either way.

Limits: Keep changes does not stop the due-date engine. An assignment whose due date passes still becomes Overdue (or Prolonged at TU Wien), and a completion event still completes it. Standard/KSW default: on (visible checkbox). TU Wien: always on (hidden).

---

## 8. Comment form and comment history (TU Wien)

The TU Wien admin variant adds a card **Comment** with

- a **comment history** table (columns **Date**, **Comment**, **Last modified by**, 5 rows per page) listing all *Manual change* history rows that carry a comment; the **Comment** cell shows "<Reason>; <comment>";
- a **Comment** textarea and the button **Save Comment**.

Saving a comment writes a *Manual change* history row **without** touching the assignment (no status, due date or counter change). Use it for case notes.

---

## 9. Internal Chat and history panel

- **Internal Chat** card (TU Wien admin variant; setting `allowinternalcommunication` on): the same chat as on the detail page, so that HR can write to the employee and supervisor. Details in [Messages — 03 Internal communication](../messages/03-internal-communication.md).
- **History** card (heading *Historie*; Standard/KSW always, TU Wien admin variant): the assignment's history log — type, date, actor, and a collapsible details row — 5 entries per page, newest first. Entry types are explained in [04 — History](04-history.md).

---

## 10. What happens when you save

Saving the status change form (any adapter):

1. A history row **Manual change** is written with the reason, the comment and the chosen status (actor = you).
2. If the **status** changed: the status type is applied (e.g. Droppedout/Paused clear the dates and remove scheduled messages; Prolonged increments its counter when the due date moved later), a history row **Assignment status changed** ("Status changed to <label>") is written, and the rule's *on status change* messages for the new status are scheduled ("manual" sending condition permitting).
3. The **due date** and **active** flag from the form are saved — also when Keep changes is on, because this is a manual update.
4. The due-date check task is (re)scheduled for the new due date. If the new date is in the past, the assignment becomes Overdue at the next cron run.
5. Nothing is saved if nothing changed (same status, dates, flags and no comment).

> **Note:** the *Assignment status changed* row of step 2 records the first user id of the setting **HR userids** as actor when that setting is filled; the *Manual change* row of step 1 records the real actor.

Saving does **not** enrol or un-enrol the user in targets and does not confirm or decline requests.

---

## Related

- [01 — Status lifecycle](01-status-lifecycle.md) — what each status means and does.
- [04 — History](04-history.md) — the rows written by these forms.
- [05 — Due dates, prolongation, overdue](05-due-dates-prolongation-overdue.md) — counters, extension period, `usingprolongedstate`.
- [Requests](../requests/README.md) — the request that usually precedes an extension decision.
- [Adapters — TU Wien (tuines)](../adapters/tuines.md), [Adapters — Standard](../adapters/standard.md), [Adapters — KSW](../adapters/ksw.md).
