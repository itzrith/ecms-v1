# ECMS implementation aligned to approved Assignment 2 SRS/SDD

Source of truth: `../brief.txt` (Final Project rubric) and `../assignment2.txt` (approved Project Plan, SRS and SDD). The system uses the specified HTML, CSS, JavaScript, PHP and MySQL web architecture.

## SRS functional requirement mapping

| SRS | Requirement | Implementation |
|---|---|---|
| FR-01 | User registration and login with role access | `register.php`, `login.php`, `includes/functions.php` |
| FR-02 | Student complaint submission | `new_complaint.php` |
| FR-03 | Complaint categorization | `new_complaint.php`, `complaint_detail.php`, `complaints.php` |
| FR-04 | Supporting evidence upload | `new_complaint.php`, `complaint_detail.php`, `download_evidence.php`, `uploads/` |
| FR-05 | Assign to staff/department | `complaint_detail.php`, `includes/departments.php` |
| FR-06 | Complaint priority management | `complaint_detail.php`, `complaints.php` |
| FR-07 | Student status and progress tracking | `complaints.php`, `complaint_detail.php` |
| FR-08 | Complaint history | `complaints.php` (Student view) |
| FR-09 | Notifications and updates | `notifications.php`, complaint submission and status updates |
| FR-10 | Staff response and resolution updates | `complaint_detail.php` |
| FR-11 | Anonymous complaint submission | `new_complaint.php`, `complaint_detail.php` |
| FR-12 | Complaint management, monitoring and reports | `complaints.php`, `dashboard.php`, `admin_users.php`, `admin_reports.php` |

## Role workflows

- **Student:** register → wait for account activation → submit a complaint and evidence → track status, progress and history → read notifications.
- **MPP Welfare:** review new complaints → categorize and prioritize → assign a department/staff member → update status and track progress.
- **Staff Department:** view assigned complaints → record progress and response → update status through resolution.
- **Administrator:** activate/manage accounts → view and monitor all complaints → generate filtered reports.

## Data entities

The database uses the SRS entities: users, complaints, complaint categories, complaint assignments, complaint progress, supporting evidence, notifications and complaint responses. Complaint status values follow the SDD workflow: Submitted, Under Review, Assigned, In Progress and Resolved.

## Scope

Implementation is limited to the approved SRS/SDD workflow and user verification requested for the project. It excludes response-time targets, complaint reopen/rating, notification preferences, private coordination notes, and administrator complaint reset/deletion tools. It also excludes the project-plan exclusions: mobile apps, payments, external complaint/social integrations, automatic investigation or decisions, and public access to confidential complaints.
