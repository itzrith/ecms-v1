# Education Complaint Management System (ECMS V1)

ECMS is a PHP and MySQL web application for managing education-related complaints through Student, MPP Welfare, Staff Department and Administrator workflows.

## Requirements

- XAMPP with PHP and MariaDB/MySQL
- A web browser

## Local setup

1. Copy this project folder into the XAMPP `htdocs` folder and name it `ecms_v1`.
2. Copy `config/database.example.php` to `config/database.php`.
3. Start Apache and MySQL in XAMPP.
4. In phpMyAdmin, create a new empty database named `ecms_edu`.
5. Import `database/schema.sql` into the empty database. This creates the tables and complaint categories; it does not include user accounts or complaint records.
6. Open `http://localhost/ecms_v1/setup_admin.php` and create the first Administrator account.
7. Sign in at `http://localhost/ecms_v1/`.
8. Use Administrator > Manage users to create MPP Welfare and Staff Department accounts. Students can register from the login page and wait for Administrator activation.


Link to make administrator account: http://localhost/ecms_v1/setup_admin.php

The database installer is intended for an empty local database. Do not import it into a database containing data you need to keep.

## Main workflow

Student submits a complaint → MPP Welfare reviews and assigns it → Staff Department updates progress or resolves it → Student tracks the outcome. Administrator manages user accounts, monitors complaints and generates reports.

## Privacy and safety

This repository contains the application source and an empty database schema only. It does not include local database credentials, seeded account/password hashes, complaint records or uploaded evidence. Keep `config/database.php` and real user data out of version control. Use test accounts and non-sensitive sample data for demonstrations.
