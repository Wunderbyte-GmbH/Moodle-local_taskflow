[Back to user documentation index](../README.md)

# Shortcodes — Reference

Taskflow registers **6 shortcodes** that render its dashboards and tables on any Moodle text area that runs the shortcodes filter (a Page or Label, a course section summary, a Text block, a Dashboard block …). This is the recommended way to give employees, supervisors and HR their own entry points without using `/local/taskflow/index.php`.

> Shortcodes are rendered for the **viewing** user: `[myassignments]` shows the assignments of whoever opens the page, `[supervisorassignments]` the team of whoever opens the page.

---

## Quick setup path

1. Enable the **Shortcodes** filter: *Site administration → Plugins → Filters → Manage filters* (the plugin is `filter_shortcodes`).
2. Optionally set a password under *Site administration → Plugins → Local plugins → Wunderbyte Taskflow → Shortcodes Settings → Password* (`shortcodespassword`).
3. Open a text area that is filtered (e.g. a **Text** block on the Dashboard, or a Page resource) and insert a shortcode, e.g. `[myassignments]`.
4. Save and open the page with a user who has the required capability.

---

## Table of contents

1. [Global rules](#1-global-rules)
2. [Supported shortcodes](#2-supported-shortcodes)
3. [Arguments of the assignment-table shortcodes](#3-arguments-of-the-assignment-table-shortcodes)
4. [`[assignmentsdashboard]`](#4-assignmentsdashboard)
5. [`[myassignments]`](#5-myassignments)
6. [`[supervisorassignments]`](#6-supervisorassignments)
7. [`[rulesdashboard]`](#7-rulesdashboard)
8. [`[requests]`](#8-requests)
9. [`[assignmentsavailability]`](#9-assignmentsavailability)
10. [Using shortcodes with block_multiblock](#10-using-shortcodes-with-block_multiblock)
11. [Examples](#11-examples)
12. [Related](#12-related)

---

## 1. Global rules

### Password protection (`shortcodespassword`)

If the setting **Password** under **Shortcodes Settings** is filled, every Taskflow shortcode must carry a matching `password="…"` argument. Otherwise the shortcode renders the warning *Shortcode "<name>" is password protected*. With an empty setting no password is needed.

```text
[myassignments password="top_secret123"]
```

### Required capabilities

Each shortcode checks one capability in the system context (see the table below). If the viewing user lacks it, the shortcode renders **nothing** on a production site; with debugging enabled it shows *The following capability/capabilites are necessary to access this shortcode: …*. `[myassignments]` and `[assignmentsavailability]` need no capability beyond being logged in.

### Adapter overrides

Headings, descriptions and notice texts come from the language strings and can be overridden by the active adapter. With the INES adapter the headlines of the assignment tables are suppressed entirely.

### Arguments

Argument values are written `name="value"`. Unknown arguments are ignored. Boolean-like arguments are "on" when present with a non-empty value (`toclarify="1"`).

---

## 2. Supported shortcodes

| Shortcode | Main use case | Who sees content | Required capability |
|-----------|---------------|------------------|---------------------|
| `[assignmentsdashboard]` | Table of **all** assignments of the site (HR / admin view) | HR, admins | `local/taskflow:editassignment` |
| `[myassignments]` | The viewing user's own assignments | every logged-in user | none |
| `[supervisorassignments]` | Assignments of the viewing user's team (direct reports + teams they are deputy of) | supervisors, deputies | `local/taskflow:issupervisor` |
| `[rulesdashboard]` | Rules table with edit/delete and **Create rule** | HR, admins | `local/taskflow:viewrules` (create button additionally needs `createrules`) |
| `[requests]` | Requests dashboard: open/confirmed/declined requests with confirm/decline actions | supervisors, deputies, HR | `local/taskflow:viewrequests` (actions need `treatrequests`) |
| `[assignmentsavailability]` | Small warning box "Demand for action" when the viewer has open assignments or, as supervisor, overdue team assignments; links to the two dashboards | every logged-in user | none (supervisor part: `issupervisor`) |

The KSW adapter additionally registers `[bookingoptiondescription]`; see [KSW adapter](../adapters/ksw.md).

---

## 3. Arguments of the assignment-table shortcodes

`[assignmentsdashboard]`, `[myassignments]` and `[supervisorassignments]` render the same assignments table (see [Dashboard](../dashboard/README.md) for the columns) and share these arguments:

| Argument | Values | Default | Meaning |
|----------|--------|---------|---------|
| `active` | `0`, `1`, `2` | `2` (`[myassignments]`: `1`) | Which assignments to list: `1` = active only, `0` = inactive only (paused, dropped out, not relevant, planned), `2` = all. |
| `status` | comma-separated status **ids**, e.g. `status="0,10"` | all | Restrict to these statuses (ids: see [Status lifecycle](../assignments/01-status-lifecycle.md)). `[assignmentsdashboard]`, `[myassignments]`. |
| `toclarify` | `1` | off | Show only assignments that need attention. For `[assignmentsdashboard]`/`[myassignments]`: status from *Overdue* up to (excluding) *Completed*; for `[supervisorassignments]`: *Overdue* assignments with at most one overrun and at most two prolongations. Changes the heading to **To clarify** — "Overdue assignments of your employees". |
| `columns` | comma-separated column keys | all | Show only these columns, in this order. Keys: `id`, `fullname`, `targets`, `rulename`, `supervisor`, `status`, `statussortkey`, `active`, `usermodified`, `usermodified_fullname`, `timecreated`, `timemodified`, `comment`, `testmoodleid`, `info`, `duedate`, `lastinternalcomment`, plus `custom_<shortname>` for fields selected in **Display optional user profile field**. The `actions` column is always shown. |
| `filter` | comma-separated: `status`, `rulename`, `completed` | none | Adds filter controls: a **Status** dropdown, a **Rulename** dropdown and/or the toggle **Hide completed assignments**. Also shows the record counter; filters start inactive. |
| `filterontop` | `true` / `1` | off | Render the filters above the table instead of in the sidebar. |
| `sortby` | a column key | `timecreated` | Default sort column (the column is added if it is not visible). |
| `sortorder` | `asc` / `desc` | `asc` when `sortby` is set, otherwise newest first | Default sort direction. |
| `requirelogin` | `false` | login required | Disables the table's own login enforcement (the page itself may still require login). |
| `chart` | `1` | off | Instead of the table render a doughnut chart of the viewer's active assignments (overdue / assigned / completed). Used by the dashboard's stats card. |
| `noheading` | `1` | off | Suppress the headline (**My Assignments**, **Assignment view**, **Supervisor view**). |
| `nodescription` | `1` | off | Suppress the description line (`[myassignments]` only). |
| `description` | a language-string key | default text | `[assignmentsdashboard]`, `[supervisorassignments]`: use the text of this Taskflow language string as description, e.g. `description="dashboarddescriptionallassignments"` ("Here you find all trainings of your staff") or `dashboarddescriptionclarify`. |
| `password` | the configured password | – | Required when **Shortcodes Settings → Password** is set. |

The table supports full-text search on name and rule name, paging with a row-count selector, and — for users with `local/taskflow:downloaddashboard` — download buttons.

---

## 4. `[assignmentsdashboard]`

All assignments of all users. Requires `local/taskflow:editassignment`. Headline **Assignment view**, description "Assignment view" text unless `description` is given.

Arguments: everything from section 3. `active` defaults to `2` (all).

```text
[assignmentsdashboard filter="status,rulename,completed" filterontop="1" columns="fullname,rulename,status,duedate,supervisor"]
[assignmentsdashboard toclarify="1" sortby="duedate" sortorder="asc"]
```

---

## 5. `[myassignments]`

The assignments of the viewing user. No capability needed. Headline **My Assignments**, description "Your assignments". Above the table an information box may appear (e.g. about the assignee's current situation) and the row actions open `/local/taskflow/assignment.php`.

Arguments: everything from section 3, plus:

| Argument | Values | Default | Meaning |
|----------|--------|---------|---------|
| `userid` | a Moodle user id | the viewing user | Show the assignments of another user. Use only in trusted contexts (the shortcode does not check permissions on the other user); the dashboard uses this internally for the per-user tabs. |

`active` defaults to `1` here — completed assignments stay visible (completed is an active status), paused/dropped-out/not-relevant ones are hidden unless you set `active="2"`.

```text
[myassignments]
[myassignments active="2" filter="completed" noheading="1"]
```

---

## 6. `[supervisorassignments]`

Assignments of the viewing user's team: users whose supervisor field points to the viewer, plus the teams of every supervisor who lists the viewer as **deputy**. Requires `local/taskflow:issupervisor`. Headline **Supervisor view**, description "All assignments of supervisor".

Arguments: everything from section 3 (`status` is **not** used here; use `assignmentstatus`), plus:

| Argument | Values | Default | Meaning |
|----------|--------|---------|---------|
| `assignmentstatus` | comma-separated status **labels**: `assigned`, `enrolled`, `paused`, `prolonged`, `partially_completed`, `overdue`, `reprimand`, `sanction`, `completed`, `droppedout`, `planned`, `notrelevant` | all | Restrict to these statuses. |
| `counters` | `label;operator;number`, several separated by comma, e.g. `counters="overdue;>=;2,prolonged;<;2"` | none | Filter on the counters `overduecounter` / `prolongedcounter`. `label` is `overdue` or `prolonged`, `operator` one of `=`, `>`, `<`, `>=`, `<=`, `<>`, `!=`. |
| `deputyselect` | `1` | off | If `mod_booking` with the *confirmation_supervisor* extension is installed and enabled, and the viewer has `mod/booking:assigndeputies`, shows the deputy display and deputy selector above the table. |

```text
[supervisorassignments]
[supervisorassignments toclarify="1"]
[supervisorassignments assignmentstatus="overdue,prolonged" counters="overdue;>=;1" sortby="duedate"]
```

---

## 7. `[rulesdashboard]`

The rules table (**Rules dashboard**): rule name, description, active yes/no, edit and delete actions; the **Create rule** button (`/local/taskflow/editrule.php?id=0`) appears for users with `local/taskflow:createrules`. Requires `local/taskflow:viewrules`. Deleting a rule here deletes all of its assignments (see [Rules](../rules/README.md)).

Arguments: only `password`.

```text
[rulesdashboard]
```

---

## 8. `[requests]`

The requests dashboard: requester, assignment (linked), request type, action, created date and comment, with filters **Open / Confirmed / Declined** and a date picker. Requires `local/taskflow:viewrequests`; the **Confirm request** / **Decline request** actions require `local/taskflow:treatrequests`.

Which requests are listed:

- Viewer is an **HR user** (configured in `bookingextension_confirmation_supervisor` → HR users): requests routed to HR.
- Otherwise: requests of the viewer's direct reports and of the teams where the viewer is deputy, routed to the supervisor.
- With `all="1"` and `local/taskflow:viewallrequests`: every request.

| Argument | Values | Default | Meaning |
|----------|--------|---------|---------|
| `all` | `1` | off | Show all requests (needs `local/taskflow:viewallrequests`). |
| `perpage` | number | `10` | Rows per page. |
| `noheader` | `1` | off | Hide the **Requests** card header. |
| `deputyselect` | `1` | off | Same deputy display/selector as for `[supervisorassignments]`. |
| `password` | | | See section 1. |

```text
[requests]
[requests all="1" perpage="25"]
```

Details of the workflow: [Requests](../requests/README.md).

---

## 9. `[assignmentsavailability]`

A compact notice for the Dashboard: renders a warning box headed **Demand for action** when

- the viewer has active assignments in status *Assigned*, *At least one target completed*, *Prolonged*, *Overdue*, *Reprimand* or *Sanctioned* — text "assignments available" with a link, and/or
- the viewer has `local/taskflow:issupervisor` and their team has assignments *to clarify* — a second text with a link.

If neither applies the shortcode outputs nothing, so it can sit permanently on a landing page.

| Argument | Values | Meaning |
|----------|--------|---------|
| `hrefmy` | URL | Link target inserted in the "your assignments" text, e.g. the page with `[myassignments]`. |
| `hrefsv` | URL | Link target inserted in the supervisor text, e.g. the page with `[supervisorassignments]`. |

The texts (`assignmentsavailablemy`, `assignmentsavailablesupervisor`) are typically overridden per adapter. No password check is applied to this shortcode.

```text
[assignmentsavailability hrefmy="/course/view.php?id=5#multiblock-1-0" hrefsv="/course/view.php?id=5#multiblock-1-1"]
```

---

## 10. Using shortcodes with block_multiblock

A common layout is one `block_multiblock` with tabs "My assignments", "My team", "Requests", each tab containing a Text block with one shortcode. Taskflow supports this: the assignment tables add a `taskflow_multiblock` parameter to their links, and when the user returns from `/local/taskflow/assignment.php` or `editassignment.php` the page reopens the tab they came from (the tab id is appended as `#multiblock-…` to the return URL). No configuration is needed; the JavaScript is loaded automatically with every assignments table.

---

## 11. Examples

| Goal | Shortcode |
|------|-----------|
| Employee landing page | `[assignmentsavailability hrefmy="/my/"]` followed by `[myassignments filter="completed"]` |
| Supervisor page, overdue first | `[supervisorassignments toclarify="1"]` then `[supervisorassignments description="dashboarddescriptionallassignments"]` |
| HR overview with filters on top | `[assignmentsdashboard filter="status,rulename,completed" filterontop="true" sortby="duedate"]` |
| HR request queue | `[requests all="1" perpage="20"]` |
| Rule management page | `[rulesdashboard]` |
| Password-protected site | add `password="…"` to every shortcode above |

---

## 12. Related

- [Dashboard](../dashboard/README.md) — the same tables on `/local/taskflow/index.php`, column reference
- [Status lifecycle](../assignments/01-status-lifecycle.md) — status ids and labels for `status` / `assignmentstatus`
- [Requests](../requests/README.md)
- [Capabilities](../capabilities/README.md)
- [Settings](../settings/README.md) — **Shortcodes Settings**, **Display optional user profile field**

---

**For AI / explain-docs routing:** this chapter answers *"how do I put the assignments / requests / rules table on a page, which arguments exist, why does a shortcode show nothing"*. What the table columns mean and how the tabbed dashboard page works is in [Dashboard](../dashboard/README.md); what a status means is in [Status lifecycle](../assignments/01-status-lifecycle.md); who counts as supervisor or deputy is in [Units and users](../units_and_users/README.md); how requests are treated is in [Requests](../requests/README.md).
