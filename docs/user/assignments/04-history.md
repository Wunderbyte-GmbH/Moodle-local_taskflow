[Back to chapter overview](README.md)

# 04 — History

> **Primary page** for: the per-assignment **history log** — which entry types exist, what each records, who writes it, and where it is displayed. For the e-mail log of a rule see [Messages](../messages/README.md); for the requests table see [Requests](../requests/README.md).

---

## Table of contents

1. [What the history is](#1-what-the-history-is)
2. [Where it is shown](#2-where-it-is-shown)
3. [Entry types](#3-entry-types)
4. [Reading a typical history](#4-reading-a-typical-history)
5. [Adapter differences](#5-adapter-differences)

---

## 1. What the history is

Every assignment has an append-only log. Each entry has

| Column | Content |
|---|---|
| **Type** | What kind of event (section 3), shown as a readable label. |
| **Date** | When the entry was written. |
| **Created by** | The actor: the person who used a form, the assignee whose completion triggered it, or the system/cron user for automatic events. |
| Details (collapsible row) | Type-specific data: reason, comment, new status, message name, competency name … Click the row to expand it. |
| Comment (annotation) | Free text attached by the forms (edit form, comment form). |

Entries are never modified or deleted by Taskflow, with one exception: deleting a **rule** deletes its assignments but leaves their history rows orphaned in the database; they are no longer reachable through the UI.

---

## 2. Where it is shown

| Place | What |
|---|---|
| Edit page [/local/taskflow/editassignment.php?id=<assignmentid>](/local/taskflow/editassignment.php?id=<assignmentid>) → card **Historie** | Full history of the assignment, newest first, 5 entries per page. Standard/KSW: always; TU Wien: admin variant only. |
| TU Wien edit page → card **Comment** → comment history table | Only *Manual change* entries that carry a comment: **Date**, **Comment** ("<Reason>; <comment>"), **Last modified by**. |
| Assignments table → column **Comment** | The most recent comment of the assignment (Standard/KSW: text of the last manual change, shortened to 50 characters; TU Wien: "dd.mm.YYYY HH:MM; <comment>", shortened to 200 characters). |

The history is **not** visible to the assignee on the detail page.

---

## 3. Entry types

The **Type** column shows the label; the internal key is stored in the database and appears as raw text for the two types without a label.

| Label in the table | Internal key | Written when | Details shown |
|---|---|---|---|
| **Assignment status changed** | `status_changed` | Every status change, automatic or manual — the assignment is created with a due date, becomes Overdue, Prolonged, Completed, Droppedout, Paused, … | "Comment: Status changed to <internal label>", e.g. *Status changed to overdue*. |
| **Manual change** | `manual_change` | Someone saved the status change form ([03 — Edit assignment](03-edit-assignment.md)), the TU Wien **Save Comment** form, or the TU Wien **Grant Extension / Deny Extension** form. | "Reason: <Sickness / Holidays / Other>", "Comment: <text>", and the chosen status name. TU Wien supervisor decisions carry the annotation "Request confirmed: <comment>" / "Request denied: <comment>". |
| **Mail send** | `mail_send` | A rule message was sent to the assignee (or other recipients) — warnings, overdue notice, on-status-change messages, request notifications. | "The message with the name <message name> has been sent". |
| **User enrolled in course** | `course_enrolled` | The assignee was **booked into a booking option** that is a target (or carries a target competency) — despite the label, this is the booking event. | – |
| **Course marked as completed** | `course_completed` | A Moodle course completion, a course completion reset to *incomplete*, or a course reset was processed for a course target. | – |
| *competency_completed* (no label) | `competency_completed` | A competency of the assignee was rated (competency target). | Short name of the competency. |
| *competency_uncompleted* (no label) | `competency_uncompleted` | An assignment that was Completed dropped back to another status during re-evaluation (a completion was revoked). | – |
| **Competency framework uploaded** | `competency_upload` | The assignee uploaded evidence for a competency target on the detail page. | Name of the evidence. |
| **Request confirmed** | `request_confirmed` | A request (not relevant / prolongation / evidence) was confirmed on the requests dashboard or an evidence was approved. | Request id. |
| **Request denied** | `request_declined` | A request was declined or an evidence rejected. | Request id. |

Defined but currently not written by any part of the plugin: **Message sent** (`message`), **Limit reached** (`limit_reached`), **User action** (`user_action`), **Rule change applied** (`rule_change`), `request_created`. If you see one of these in an old installation it stems from earlier versions.

Two special automatic annotations:

- When a cyclic competency rule is applied to a user who had already completed a matching booking option before the rule existed, the assignment is created as Completed and an **Assignment status changed** entry with the comment *"Assignment was completed on <date>"* documents the historic completion (see [06 — Cyclic assignments](06-cyclic-assignments.md)).
- Import runs and cron re-evaluations that change nothing write **no** history — repeated identical imports leave the history untouched (this is a tested guarantee of the TU Wien adapter).

> **Note:** for a *manual* status change the **Assignment status changed** row records the first user id of the setting **HR userids** as *Created by* when that setting is filled; the accompanying **Manual change** row shows the real actor. Automatic status changes show the cron/system user.

---

## 4. Reading a typical history

Rule: 4-week duration, "Assigned" message on status change, Warning 1 / Warning 2 before the due date, overdue message after it. Newest first, as the table shows it:

| Date | Type | Created by | Details |
|---|---|---|---|
| 20.04.2026 09:14 | Assignment status changed | Anna Example | Comment: Status changed to completed |
| 20.04.2026 09:14 | Course marked as completed | Anna Example | – |
| 15.04.2026 10:02 | Assignment status changed | HR user | Comment: Status changed to prolonged |
| 15.04.2026 10:02 | Manual change | Maria Manager | Reason: Sickness · Comment: was ill 24.03.–10.04. · Prolonged |
| 31.03.2026 00:10 | Mail send | System | The message with the name Taskflow - Overdue has been sent |
| 31.03.2026 00:00 | Assignment status changed | System | Comment: Status changed to overdue |
| 25.03.2026 00:00 | Mail send | System | The message with the name Taskflow - Warning 2 has been sent |
| 20.03.2026 00:00 | Mail send | System | The message with the name Taskflow - Warning 1 has been sent |
| 05.03.2026 14:31 | User enrolled in course | Anna Example | – |
| 05.03.2026 14:31 | Assignment status changed | System | Comment: Status changed to enrolled |
| 02.03.2026 08:05 | Mail send | System | The message with the name Taskflow - Assigned has been sent |
| 02.03.2026 08:00 | Assignment status changed | System | Comment: Status changed to assigned |

At TU Wien the *enrolled* pair on 05.03. is missing (status excluded) and the 31.03. row reads *Status changed to prolonged* with the overdue rows following two weeks later.

---

## 5. Adapter differences

- **Standard/KSW:** history card on the edit page for everybody who may open it; the table's **Comment** column shows the last manual-change comment.
- **TU Wien (tuines):** history card only in the admin variant; supervisors see no history. Additional **comment history** table restricted to commented *Manual change* rows; the **Comment** column of the assignments table shows timestamp and text of the latest commented history row. The supervisor's Grant/Deny decisions are logged as *Manual change* with the "Request confirmed/denied" annotation, not as *Request confirmed/denied* rows (those come from the requests dashboard).

---

## Related

- [03 — Edit assignment](03-edit-assignment.md) — the forms that write *Manual change*.
- [01 — Status lifecycle](01-status-lifecycle.md) — the transitions behind *Assignment status changed*.
- [Messages](../messages/README.md) — the messages behind *Mail send*.
- [Requests](../requests/README.md) — *Request confirmed / denied*.
- [Competencies and certificates](../competencies_and_certificates/README.md) — *Competency framework uploaded*.
