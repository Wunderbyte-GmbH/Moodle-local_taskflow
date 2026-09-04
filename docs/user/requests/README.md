[Back to user documentation index](../README.md)

# Requests — Overview

A **request** is a self-service ticket that an assignee raises about one of their own assignments: "this is not
relevant for me", "I need more time", or "here is evidence that I already have this competency". Each request goes to a
**receiver** — the assignee's supervisor (and deputies) or the HR users — who confirms or declines it. Confirming can
change the assignment (status *Not relevant*, competency granted); every step is logged in the assignment history and can
trigger request messages.

Requests are the only way for employees to influence their assignments themselves. Supervisors and admins change
assignments directly on the edit-assignment page ([Edit assignment](../assignments/03-edit-assignment.md)).

## Quick path: enable a request type and process a request

1. *Site administration → Plugins → Local plugins → Wunderbyte Taskflow →* heading **Taskflow Request Settings**: tick
   the request types you want to allow site-wide (**Allow user to request assignment not-relevant status**, **Allow user
   to request extension of assignment duedate**, **Allow upload evidence of competencies by users**).
2. Enter the **HR userids** (comma-separated Moodle user ids) if requests should go to HR; make sure supervisors are
   maintained in the supervisor profile field if requests should go to supervisors
   ([Units and users](../units_and_users/README.md)).
3. Open the rule (`/local/taskflow/editrule.php?id=<id>`), step **Requests**: for each enabled type set **Requests go
   to:** *Not allowed*, *Supervisor* or *HR* ([Rules — Requests step](../rules/05-requests-step.md)).
4. Optionally create *Request* message templates (*Request opened*, *Request closed*) and attach them in the rule's
   **Messages** step ([Messages](../messages/README.md)).
5. The assignee opens `/local/taskflow/assignment.php?id=<assignmentid>` and clicks **Not relevant for me**, **Request
   Prolongation** or **Evidence**.
6. The receiver processes the request in the requests dashboard (shortcode `[requests]`, embedded in the admin and
   supervisor dashboards) with the check / thumbs-down buttons, or — for evidence — with the **Status** button on the
   assignment page.

## The three request types

| Request type (title in the rule editor) | Setting that enables it | Button on the assignment page | Request type id | What the assignee enters |
|---|---|---|---|---|
| **Assignment not-relevant status** | `allowselfnotrelevant` — *Allow user to request assignment not-relevant status* | **Not relevant for me** | 1 | A **Comment**. The modal says "Your {receiver} will review your request and inform you of the decision." (receiver = *Supervisor*, *HR* or *Request administrator*). |
| **Assignment duedate extension** | `allowselfextension` — *Allow user to request extension of assignment duedate* | **Request Prolongation** | 2 | A **Comment**. |
| **Upload evidence of competencies** | `allowuploadevidence` — *Allow upload evidence of competencies by users* | **Evidence** (per competency target, only while no evidence exists) | 3 | **Title** (max. 100 chars), **Comment**, **URL**, **Files**, optional **Valid until date**. Creates a Moodle competency evidence plus the request. |

Rules:

* A type must be enabled **globally** (setting) **and** per rule (**Requests go to:** ≠ *Not allowed*) before the button
  appears. The rule editor only lists globally enabled types.
* The buttons are shown on the core assignment page to whoever can open it (assignee, supervisor, or
  `local/taskflow:viewassignment`); the request is always created **for the assignee** of that assignment. Creating a
  request requires the capability `local/taskflow:createrequests` (granted to every authenticated user by default);
  without it the form reports "no permissions" and nothing is stored.
* While an **untreated** request of the same type exists for the assignment, the button is disabled; submitting anyway
  is rejected ("The request to set this assignment as "not relevant" has already been effectured and has not yet been
  treated…" / "Duplicate"). Evidence: one open upload request per competency.
* The **tuines** adapter uses its own assignment template that renders only the evidence buttons; there, extensions are
  handled by the supervisor's *Grant Extension / Deny Extension* form instead of a request
  ([Adapters — tuines](../adapters/tuines.md)).

## Who receives a request

| **Requests go to:** (rule) | Receiver id | Users |
|---|---|---|
| *Supervisor* | 0 | The assignee's supervisor (Moodle id in the profile field with the function *Supervisor*). With **Send mails to deputy** (`sendmailstodeputy`) on, the supervisor's deputies (profile field with the function *Deputy*, comma-separated ids) are added as further receivers of the *Request opened* message. |
| *HR* | 1 | All users listed in the setting **HR userids** (`local_taskflow/hrusers`). |
| *Not allowed* | — | Button not shown; request cannot be created. |

The receiver is stored on the request when it is created (`forhr` = 0 or 1). Changing the rule later does not move
existing requests.

## Workflow per request type

| | Assignment not-relevant status | Assignment duedate extension | Upload evidence of competencies |
|---|---|---|---|
| **Who can create** | The assignee (needs `local/taskflow:createrequests`), via the assignment page. | Same. | Same; `local/taskflow:uploaduserevidence` additionally allows others to open the evidence form for a user. |
| **Who receives** | Supervisor (+ deputies) or HR per rule. | Supervisor (+ deputies) or HR per rule. | Supervisor (+ deputies) or HR per rule; in practice reviewed on the assignment page by a user who is not the assignee and has `local/taskflow:issupervisor`, or by a user with `local/taskflow:editmessages`. |
| **Where it is treated** | Requests dashboard → **Confirm request** (check icon, modal "Are you sure to confirm this request?") or **Decline request** (thumbs-down, "Are you sure you want to decline this request?"). Requires `local/taskflow:treatrequests`. | Requests dashboard → confirm (modal "Did you really prolong your employee manually?") or decline. Requires `local/taskflow:treatrequests`. | Assignment page → **Status** button on the evidence → **User evidence status** *Under review* / *Approved* / *Rejected*, optional **Valid until date**. Requires `local/taskflow:editmessages` for the status mode. |
| **Effect of confirm on the assignment** | Status → **Not relevant** (`notrelevant`, id −2, inactive). The assignment stays *Not relevant* through later imports and re-evaluations. | **None automatically.** The request is only marked confirmed. The due date must be extended separately: on the edit-assignment page ([Edit assignment](../assignments/03-edit-assignment.md)) — in tuines via **Grant Extension**, which sets status *Prolonged* and due date + extension period. | The competency is **granted** to the user (competency target completed). If all targets are completed the assignment becomes *Completed*; with several competencies *At least one target completed*. The evidence record shows *Approved*. |
| **Effect of decline** | Request marked declined; assignment unchanged. | Request marked declined; assignment unchanged. | *Rejected*: request marked declined, competency removed again if it had been granted. *Under review*: request stays open, competency removed. |
| **History entries** | *Request confirmed* / *Request denied* on the assignment; plus *Assignment status changed* when confirmed. | *Request confirmed* / *Request denied*. | *Competency framework uploaded* when the evidence is created; *Request confirmed* / *Request denied* when approved / rejected. |
| **Notifications** | *Request opened* template → receiver; *Request closed* template → assignee (both only if attached to the rule). | Same. | Same (the evidence upload creates a request, so *Request opened* fires; approval/rejection fires *Request closed*). |
| **Assignee can delete** | — | — | **Delete** button on the evidence: removes evidence, competency link and the request. |

### Request states

| State | Shown as | Meaning |
|---|---|---|
| `treated = 0` | **Open** | Waiting for a decision; buttons active, external-link icon to the assignment. |
| `treated = 2` | **Confirmed** | Green check icon. |
| `treated = 1` | **Declined** | Red cross icon. |

## The requests dashboard

The dashboard is a table rendered by the shortcode `[requests]` (requires `local/taskflow:viewrequests`; optional
`password=` if **Shortcodes password** is set, see [Shortcodes](../shortcodes/README.md)). It is embedded in the Taskflow
dashboard: on the **Admin dashboard** and **Supervisor dashboard** tabs of `/local/taskflow/index.php` and in the
adapter dashboards ([Dashboard](../dashboard/README.md)).

| Column | Content |
|---|---|
| **Requesting user** | First and last name of the assignee. |
| **Assignment** | Rule name, linked to `/local/taskflow/assignment.php?id=…` (new tab). |
| **Status** | The request type: *Not relevant*, *Request Prolongation*, *Recognition of evidence*. |
| **Actions** | Open: **Confirm request** (check) and **Decline request** (thumbs-down) for the not-relevant and extension types, plus an external-link icon to the assignment; evidence requests have no dashboard actions (they are treated on the assignment page). Confirmed: green check. Declined: red cross. |
| **Time created** | Date and time of the request. |
| **Comment** | The assignee's comment. |

Filters: **Status** (*Open* / *Confirmed* / *Declined*) and a **Time created** date picker. Default sort: newest first;
10 rows per page (argument `perpage=`).

### Which requests a viewer sees

| Viewer | Sees |
|---|---|
| A supervisor | Requests of users whose supervisor field points to them, and requests of users whose supervisor lists the viewer as **deputy** — only requests routed to *Supervisor* (`forhr = 0`). |
| A user listed in `bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers` (mod_booking confirmation extension) | The HR variant of the dashboard: all requests routed to *HR* (`forhr = 1`). |
| Everyone else | "No records found." |
| Holder of `local/taskflow:viewallrequests` | May see **all** requests, but only when the dashboard is rendered with the `all` switch. |

> **Note:** The HR dashboard is selected by the mod_booking setting above, not by `local_taskflow/hrusers`; the two
> lists should contain the same people. The `all` switch is checked by the dashboard classes but the `[requests]`
> shortcode does not pass its arguments on, so `all=1` currently has no effect when used as a shortcode argument.

Shortcode arguments: `noheader=1` (no heading), `perpage=<n>`, `deputyselect=1` (shows the deputy selector of the
mod_booking confirmation extension above the table, for users with `mod/booking:assigndeputies`).

## Capabilities involved

| Capability | Default archetypes | Needed for |
|---|---|---|
| `local/taskflow:createrequests` — *Create requests* | manager, coursecreator, editingteacher, teacher, user | Creating any request (checked when the request is stored). |
| `local/taskflow:viewrequests` — *View requests* | manager | Rendering the `[requests]` shortcode / dashboard. |
| `local/taskflow:treatrequests` — *Treat requests* | manager | Confirm / decline buttons in the dashboard. |
| `local/taskflow:viewallrequests` — *View all requests* | manager | The `all` mode of the dashboard. |
| `local/taskflow:uploaduserevidence` — *Upload certificate* | manager | Opening the evidence form for another user. |
| `local/taskflow:editmessages` — *Edit messages* | manager | Setting the evidence status (approve / reject). |
| `local/taskflow:issupervisor` — *Is supervisor* | manager (and the auto-assigned supervisor role) | Seeing the evidence action buttons on someone else's assignment page. |

Supervisors therefore need `viewrequests` and `treatrequests` in their role to work with the dashboard; see
[Capabilities](../capabilities/README.md) for a recommended role setup.

## Request messages

Request messages are templates of type **Request** ([Message templates](../messages/01-message-templates.md)):

| Template anchor | Sent when | To | Content hints |
|---|---|---|---|
| *Request opened* | The request is created (adhoc task, offset from **Send when?**). | The rule's receiver: supervisor (+ deputies with `sendmailstodeputy`) or HR users. | `<firstname> <lastname>` = assignee, `<targets>`, `<due_date>`, `<due_date_with_extension>` (the date an extension would give). |
| *Request closed* | The request is confirmed or declined. | The assignee. | `<status>` shows the (possibly changed) assignment status. The template cannot distinguish confirm from decline; write neutral text or use two rules. |

Both are sent by e-mail and Moodle notification, logged in history as *Mail send*, and are never deduplicated (every
request produces its messages). CC settings of the template apply. See the recipe *Let employees request an extension,
approved by the supervisor* in the [Messages overview](../messages/README.md#recipes).

## Related

* [Rules — Requests step](../rules/05-requests-step.md)
* [Messages](../messages/README.md) and [Message templates](../messages/01-message-templates.md)
* [Assignments — Assignment detail page](../assignments/02-assignment-detail-page.md) — the buttons
* [Assignments — Edit assignment](../assignments/03-edit-assignment.md) — extending a due date, tuines grant/deny form
* [Assignments — Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md)
* [Competencies and certificates](../competencies_and_certificates/README.md) — evidence review in detail
* [Shortcodes](../shortcodes/README.md), [Capabilities](../capabilities/README.md), [Settings](../settings/README.md)

## For AI / explain-docs routing

Questions that belong here: the *Not relevant for me*, *Request Prolongation* and *Evidence* buttons; enabling request
types; who receives and approves a request; the requests dashboard, its columns, filters and confirm/decline actions; what
confirming or declining changes on the assignment; why a request button is missing or disabled; request capabilities.
Questions about the *text* and *timing* of the *Request opened / Request closed* mails belong to
[Messages](../messages/README.md). Questions about how a supervisor or admin changes a due date or status directly
belong to [Edit assignment](../assignments/03-edit-assignment.md). Questions about the evidence review form fields and
certificates belong to [Competencies and certificates](../competencies_and_certificates/README.md). The assignment chat
is [Internal communication](../messages/03-internal-communication.md), not a request.
