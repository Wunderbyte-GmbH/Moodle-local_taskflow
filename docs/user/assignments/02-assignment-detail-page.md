[Back to chapter overview](README.md)

# 02 — Assignment detail page

> **Primary page** for: `/local/taskflow/assignment.php?id=<assignmentid>` — the page an **employee** opens to see what they have to do, and a **supervisor** opens to see an employee's assignment. Editing status and due date happens on a different page: [03 — Edit assignment](03-edit-assignment.md). How requests are processed after the button was clicked is in [Requests](../requests/README.md).

---

## Table of contents

1. [How to get there and who may open it](#1-how-to-get-there-and-who-may-open-it)
2. [What the page shows](#2-what-the-page-shows)
3. [Targets and completion](#3-targets-and-completion)
4. [Buttons: Not relevant for me / Request Prolongation](#4-buttons-not-relevant-for-me--request-prolongation)
5. [Evidence for competency targets](#5-evidence-for-competency-targets)
6. [Possible courses](#6-possible-courses)
7. [Internal Chat](#7-internal-chat)
8. [Re-evaluating an assignment: checkstatus](#8-re-evaluating-an-assignment-checkstatus)
9. [Adapter differences](#9-adapter-differences)

---

## 1. How to get there and who may open it

- From every assignments table: the info icon (Standard/KSW) or the link **Go to training** (TU Wien) in the **Actions** column opens `/local/taskflow/assignment.php?id=<assignmentid>&returnurl=<page>`. The table adds `taskflow_multiblock=…` so that the **Back** button returns to the right tab of a block_multiblock page.
- From a message: the placeholders `<targets>` / `<opentargets>` link competency targets to this page (see [Messages — 02 Placeholders](../messages/02-placeholders.md)).
- Directly by URL: [/local/taskflow/assignment.php?id=<assignmentid>](/local/taskflow/assignment.php?id=<assignmentid>).

The page is rendered when the viewer

- is the **assignee** of the assignment, or
- is the assignee's **supervisor** (the user stored in the assignee's supervisor profile field; deputies do *not* qualify here), or
- has the capability `local/taskflow:viewassignment` (system level, see [Capabilities](../capabilities/README.md)).

Everyone else sees an error notification. Opening the page records that the viewer has *seen* the assignment (used for the chat "new message" indicators).

If the id does not exist the page shows *"Assignment <id> not found"*.

---

## 2. What the page shows

From top to bottom (Standard/KSW template; TU Wien differences in section 9):

| Element | Content |
|---|---|
| **Back** | Returns to the page you came from (`returnurl`), otherwise one step back in the browser history. |
| Heading | **Assignment to** <full name of the assignee>, with the profile picture. |
| Request buttons | **Not relevant for me** and **Request Prolongation** — only the ones the rule allows (section 4). |
| Description | The rule's description text (from the rule step, see [Rules — 01 Rule step](../rules/01-rule-step.md)). |
| **Due date until -** <date> | The current due date, or *"Due date is not set yet"* for Planned / Paused / Droppedout / Not relevant assignments. |
| **Contact supervisor in case of problems:** <name> | Mail link to the supervisor. Empty if no supervisor is configured for the user. |
| **Targets:** | One entry per target (section 3). |
| **Possible courses:** | Only for competency targets (section 6). |
| **Internal Chat** | Only if enabled (section 7). |
| My assignments table | Below the card the assignee's own **My Assignments** table is embedded, so that they can jump to their other assignments. |

The page does not show the status name itself; the status is visible in the assignments table and on the edit page.

---

## 3. Targets and completion

Each target of the assignment is listed with its **type** (Booking option / Moodle course / Competency), its **name**, and either **completed** or **Not completed**. The flag comes from the assignment's own target list, which is updated whenever a completion event is processed (see [01 — Status lifecycle](01-status-lifecycle.md), section 4).

Where the target name links to:

| Target type | Link | Completion is detected when… |
|---|---|---|
| Booking option | `/mod/booking/optionview.php?optionid=…` with a return link to this page — the user can book (or see their booking) there | the booking option's activity completion is set for the user (or, for cyclic rules, a completion younger than the validation duration exists) |
| Moodle course | `/course/view.php?id=…` | Moodle course completion is reached |
| Competency | this page | the user completed a booking option that carries the competency, or an uploaded evidence was **approved** and is still valid |

Details on how each type is enrolled/booked and detected are in [Rules — 03 Targets](../rules/03-targets.md). With **complete before next** chains only the first open target is enrolled; the later targets appear in the list as *Not completed* until their predecessor is done ([06 — Cyclic assignments](06-cyclic-assignments.md), section 5).

---

## 4. Buttons: Not relevant for me / Request Prolongation

Two self-service buttons appear in the header when the **rule's Requests step** allows the request type (receiver *Supervisor* or *HR*; see [Rules — 05 Requests step](../rules/05-requests-step.md)):

| Button | Opens | What happens |
|---|---|---|
| **Not relevant for me** | a modal with the note *"Your <supervisor / request administrator> will review your request and inform you of the decision."* and a **Comment** field | Creates a request of type *Not relevant*. When the receiver **confirms**, the assignment becomes **Not relevant** (inactive, due date cleared). When declined, nothing changes. |
| **Request Prolongation** | a modal **Request Prolongation** with a **Comment** field | Creates a request of type *Assignment duedate extension*. Confirming the request does **not** change the assignment by itself — the supervisor (TU Wien) or admin extends the due date on the edit page. |

A button is **disabled** while an untreated request of the same type exists for this assignment. Sending a request requires `local/taskflow:createrequests` (granted to authenticated users by default). Receivers, notifications, the requests dashboard and the effect of confirm/decline are described in [Requests](../requests/README.md).

Both buttons are hidden when the site setting for the request type is off or the rule does not allow it. The **Not relevant** status is final from Taskflow's point of view: imports and rule re-evaluations never reactivate a Not relevant assignment (only an admin can, via the edit page).

---

## 5. Evidence for competency targets

If the site setting **Allow upload evidence of competencies by users** (`allowuploadevidence`) is on, each **competency** target gets evidence controls:

- No evidence yet: button **Evidence** (upload icon) opens the evidence form (title, description, URL, files, **Valid until date**). Submitting creates a Moodle user evidence, links it to the assignment with status **Under review**, creates a request of type *Recognition of evidence* and writes a history entry *Competency framework uploaded*.
- Evidence exists: the evidence card shows name, status badge (**Under review** / **Approved** / **Rejected**), description, upload date, link and files, plus the buttons **Edit** and **Delete** for the assignee and **Status** for supervisors (`local/taskflow:issupervisor`) and admins to approve or reject.
- **Approved** evidence marks the competency target as completed on the next evaluation (it is triggered immediately after approval) and confirms the evidence request; **Rejected** removes the competency link and declines the request.

The review workflow, validity date and the **My certificates** page are documented in [Competencies and certificates](../competencies_and_certificates/README.md).

---

## 6. Possible courses

For every **competency** target the page adds a card **Possible courses: <competency name>** listing the booking options that carry this competency (the same list mod_booking uses for "similar options"). Completing any of them completes the target. If no booking option declares the competency the card reads *"No courses available"*. Booking-option and course targets do not get this card.

---

## 7. Internal Chat

When the setting **Internal communication between supervisors and users** (`allowinternalcommunication`, default on) is enabled and the viewer is the assignee or their supervisor, the page ends with the **Internal Chat** card: the message history (own messages right, others left) and a text field with **Send message**. Messages are stored per assignment; the last message is previewed in the assignments table, and unseen messages are collected into a daily digest e-mail. Everything about the chat — preview length, read tracking, digest task and notification preferences — is in [Messages — 03 Internal communication](../messages/03-internal-communication.md).

---

## 8. Re-evaluating an assignment: checkstatus

Appending `&action=checkstatus` to the URL — [/local/taskflow/assignment.php?id=<assignmentid>&action=checkstatus](/local/taskflow/assignment.php?id=<assignmentid>&action=checkstatus) — makes Taskflow re-run the assignment pipeline for this user and the rules of the assignment's unit (including inherited rules of parent units) before rendering the page. Use it when a completion happened outside the normal events (for example a course completion written directly to the database) and the status looks stale. It applies the same recomputation as a rule re-evaluation: statuses like Enrolled or At least one target completed may be recalculated, Overdue/Prolonged/Completed/Paused/Not relevant stay, **Keep changes** is respected. There is no button for this action in the UI.

> **Note:** the action currently runs even for users who are not allowed to see the page (they still get the permission error, but the re-evaluation is executed).

---

## 9. Adapter differences

**Standard/KSW:** the page as described above; table link is the info icon.

**TU Wien (tuines):** the adapter ships its own template for this page. Differences:

- The table link is labelled **Go to training** (German *Zur Schulung*).
- The header line reads **Due date until -** <date> and **Contact supervisor in case of problems:** <mail link>; targets are listed as *<name> (completed | Not completed)* without the type prefix.
- The request buttons are not part of the TU Wien template; extensions are handled by the supervisor's **Grant Extension / Deny Extension** form on the edit page ([03 — Edit assignment](03-edit-assignment.md)).
- Evidence buttons (**Evidence**, **Status**, **Edit**, **Delete**), possible-course cards and the **Internal Chat** card work as above (the chat digest mail carries the TU Wien wording *"ines - You have new chat messages"*).

---

## Related

- [03 — Edit assignment](03-edit-assignment.md) — where status and due date are changed.
- [Requests](../requests/README.md) — what happens after **Not relevant for me** / **Request Prolongation**.
- [Competencies and certificates](../competencies_and_certificates/README.md) — evidence review.
- [Messages — 03 Internal communication](../messages/03-internal-communication.md) — the chat.
- [Dashboard](../dashboard/README.md) — the tables that link here.
