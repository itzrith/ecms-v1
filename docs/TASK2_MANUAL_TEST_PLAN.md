# ECMS V1 — Task 2 Manual Test Plan

**Purpose:** Record manual verification for the approved Education Complaint Management System requirements. Run in the local XAMPP environment and record actual results; this plan does not claim tests have already passed.

## Setup

- System: ECMS V1 at `http://localhost/ecms_v1/`
- Database: `ecms_edu`
- Record PHP/MySQL versions, date, browser, tester, and starting data before the run.
- Use test accounts for Student, MPP Welfare, Staff Department, and Administrator.
- Use harmless sample evidence. Do not use private or real student information in screenshots/video.

## Test cases

| ID | Role | Action | Expected result | Actual result / evidence |
|---|---|---|---|---|
| TC-01 | Student | Register using valid details | Account is created in Pending state and cannot enter protected pages until activated | |
| TC-02 | Administrator | Activate a pending student; student logs in | Student can access student pages after activation | |
| TC-03 | Student | Sign in with correct and incorrect credentials | Correct credentials open student dashboard; incorrect credentials show a safe error | |
| TC-04 | Student | Submit a complaint with category, details, priority where permitted, and evidence | Complaint is saved, gets a reference, and appears in history | |
| TC-05 | Student | Open complaint detail and add/view evidence | Only permitted files are accepted; authorized image/PDF evidence opens in the browser | |
| TC-06 | Student | Search/filter own complaint history by status, category, and date; move to next page when available | Results match filters; page controls preserve filters; last-updated date is visible | |
| TC-07 | MPP Welfare | Review a submitted complaint, set priority/status, assign a department and staff | Assignment is recorded and correct staff is notified | |
| TC-08 | MPP Welfare | View complaint queue and filter records | MPP can view and triage complaints, including unassigned complaints | |
| TC-09 | Staff Department | Open an assigned complaint and update progress/status with remarks | Progress history is recorded and visible to the student; unrelated department cases are inaccessible | |
| TC-10 | Staff Department | Try to open another department's complaint/evidence by changing the URL ID | Access is denied | |
| TC-11 | Student | Open notifications, mark/read notification, and check complaint history | Notifications show the relevant complaint update and read state persists | |
| TC-12 | Administrator | Add a user with valid details | User record is created as Pending and appears in the user list | |
| TC-13 | Administrator | Edit a user's name, email, student ID or department | Valid changes save; duplicate email/student ID/department is rejected with a clear message | |
| TC-14 | Administrator | Activate/deactivate a user and attempt login | Active account can sign in; pending/deactivated account cannot access protected pages | |
| TC-15 | Administrator | Generate report with date/category/status/priority/department filters | KPI totals, four breakdown charts, and complaint records match the selected filters | |
| TC-16 | Administrator | Export Excel and CSV; print/save report as PDF | Excel workbook opens with Summary and Complaint records sheets; CSV opens; PDF is readable and summary precedes records | |
| TC-17 | All roles | Try direct access to another role's restricted page | Access is denied or redirected to the appropriate page | |
| TC-18 | Administrator | Review user totals and dashboard statistics | Total users and complaint statistics reflect current database records | |

## Evidence to capture

- Login and role-specific dashboard.
- Complaint submission and generated reference.
- MPP assignment and staff progress update.
- Student-visible status/progress and notification.
- Admin user creation/edit/status management.
- Filtered complaint history and pagination.
- Report filters, report summary/charts, Excel workbook, CSV, and print preview/PDF.
- Any failed test with the visible error and steps to reproduce.

## Demo/troubleshooting recording outline (2–3 minutes)

1. State the local setup and demonstrate a student submitting one complaint.
2. Show MPP review/assignment, then staff progress update.
3. Show the student tracking the update and notification.
4. Show the administrator managing access and generating an Excel/PDF report.
5. If a step fails, show the error, note the test ID, and explain the next diagnostic step.

Use only actual observed results in the submitted recording and report.
