[Back to chapter overview](README.md)

# Dashboard page — HR overview and team overview

The **dashboard page** (`/local/taskflow/dashboard.php`) arranges the existing dashboard building blocks
(assignments table, status chart, requests table, booking approvals) in the layout of the
[person page](01-person-page.md) and the [organisation page](../units_and_users/01-organisation-page.md).
It adds nothing new functionally: every table, filter, chart and action is the one `/local/taskflow/index.php`
already uses. What is new is the arrangement, the counters, and the possibility to act on several rows at once.

---

## Quick path

1. Open `/local/taskflow/dashboard.php`. HR users land on **HR dashboard**, supervisors on **Team**; the tabs
   switch between the two when you may see both (`?view=hr`, `?view=team`).
2. Read the counters at the top.
3. Tick rows in a table and use the buttons above it: **Send reminder**, **Extend due date**, **Pause**,
   **Set not relevant** for assignments, **Confirm** / **Decline** for requests. Each button asks for
   confirmation first.
4. Use the navigation strip to jump to the **Organisation chart** or to a person (**Switch person**).

## Who sees which view

| View | Who | Same rule as |
|------|-----|--------------|
| HR dashboard | `local/taskflow:editassignment`, or listed as HR user of the booking extension *confirmation supervisor* | the *Admin- Dashboard* tab of `index.php` |
| Team | `local/taskflow:issupervisor` | the *Supervisor* tab of `index.php` |

## HR dashboard

| Section | Content |
|---------|---------|
| Counters | active assignments, completed, due within 14 days, overdue, open requests, units and rules (links to the organisation chart) |
| Needs attention | the assignments table filtered to status *Overdue* |
| Status of active assignments | the status chart |
| All assignments | the assignments table with the filters *Status*, *Rulename*, *Hide completed* |
| Rules per unit | the unit tree with members, own rules and inherited rules; links to the organisation chart |
| Open requests | the requests table over **all** requests (needs `local/taskflow:viewallrequests`) |

## Team

| Section | Content |
|---------|---------|
| Counters | persons in the team, completed, open, overdue, open requests of the team |
| My team | one tile per team member: overdue persons first, then by progress; each tile opens the person page |
| Assignments of my team | the supervisor assignments table (own team and deputised teams) |
| Status, open requests, bookings to approve | the status chart, the requests addressed to you, the booking approvals of the booking extension *confirmation supervisor* (when enabled) |
| Latest notes | the five newest [notes](01-person-page.md) about your team members you may read |

## Acting on several rows

The tables show checkboxes and, above the rows, one button per bulk action. Every action runs through the same
service as the single edit, and checks the rights **per row**: rows you may not change are skipped and counted in
the result message.

| Button | What happens | Existing function used |
|--------|--------------|------------------------|
| Send reminder | choose a message template; it is sent to the persons of the selected assignments; messages already sent for an assignment are not sent again | the message classes of the scheduled sending |
| Extend due date | moves the due date by the **extension period** of the assignment's rule (from today when it is already overdue); rules without extension period are skipped | the manual assignment update (as in *Edit assignment*) |
| Pause | sets the status *Paused* | the manual assignment update |
| Set not relevant | sets the status *Not relevant* | the manual assignment update |
| Confirm / Decline (requests) | treats the selected requests | the request treatment of the requests table |

Who may act on an assignment row: users with `local/taskflow:editassignment`, and the supervisor of the person —
the same rule as the edit icon in the actions column.

## For adapters

The HR and team views are resolved through `adapter_view_resolver` like the person and organisation pages:
`taskflowadapter_<adapter>\output\hroverview` and `…\teamoverview` replace the core views
(see [ADAPTER_API](../../developer-guides/ADAPTER_API.md)).

## Related

- [Dashboard](README.md) — `index.php` with its tabs and the assignments table
- [Person page](01-person-page.md)
- [Organisation page](../units_and_users/01-organisation-page.md)
- [Requests](../requests/README.md)

---

## For AI / explain-docs routing

Questions that belong here: what the dashboard page shows, the difference between HR dashboard and team view, how
to pause, extend or remind several assignments at once, how to confirm several requests, why a selected row was
skipped.
