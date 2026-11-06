<?php

function ecms_departments(): array
{
    return [
        'Academic Affairs',
        'Student Affairs',
        'Facilities & Maintenance',
        'Information Technology (ICT)',
        'Library Services',
        'Residential College / Hostel',
        'Security',
        'Cafeteria / Food Services',
    ];
}

function ecms_available_departments(mysqli $db): array
{
    $result = $db->query("SELECT department FROM users WHERE role = 'Staff Department' AND department IS NOT NULL");
    $used = array_column($result->fetch_all(MYSQLI_ASSOC), 'department');
    return array_values(array_filter(ecms_departments(), static fn (string $department): bool => !in_array($department, $used, true)));
}
