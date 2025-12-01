```mermaid
    classDiagram
        class shortcodes {
            +assignmentsdashboard()
            +myassignments()
            +supervisorassignments()
        }

        class assignmentsdashboard {
            -AssignmentDataProvider provider
            +get_assignmentsdashboard()
            +set_my_table_heading()
            +set_supervisor_table_heading()
        }

        class AssignmentDataProvider {
            <<interface>>
            +get_table_data()
        }

        class MyAssignmentsProvider {
            +get_table_data()
        }

        class SupervisorAssignmentsProvider {
            +get_table_data()
        }

        shortcodes --> MyAssignmentsProvider : uses in myassignments()
        shortcodes --> SupervisorAssignmentsProvider : uses in supervisorassignments()
        shortcodes --> assignmentsdashboard : creates and calls

        MyAssignmentsProvider ..|> AssignmentDataProvider
        SupervisorAssignmentsProvider ..|> AssignmentDataProvider
        assignmentsdashboard --> AssignmentDataProvider : uses via DI
```
Benefits of This Design

- Decouples data source logic from rendering
- Easier testing: you can mock AssignmentDataProvider
- Flexible: you can add ArchivedAssignmentsProvider, HRDashboardProvider, etc., without modifying assignmentsdashboard