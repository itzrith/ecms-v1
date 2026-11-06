USE ecms_edu;

-- Run once on an existing ECMS database after resolving any staff rows
-- whose department is NULL or blank.
ALTER TABLE users
  ADD CONSTRAINT chk_staff_department_required CHECK (
    role <> 'Staff Department' OR (department IS NOT NULL AND CHAR_LENGTH(TRIM(department)) > 0)
  );
