<?php
$pageTitle = $pageTitle ?? 'ECMS';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | ECMS</title>
    <link rel="stylesheet" href="/ecms_v1/assets/style.css?v=25">
</head>
<body<?= !empty($bodyClass) ? ' class="' . h($bodyClass) . '"' : '' ?>>
<?php if (!empty($_SESSION['user_id'])): ?><script>try{if(localStorage.getItem('ecms_v1_sidebar_collapsed')==='true')document.body.classList.add('sidebar-collapsed');}catch(error){}</script><?php endif; ?>
<header class="topbar">
    <div class="topbar-brand-group">
    <?php if (!empty($_SESSION['user_id'])): ?><button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-controls="workspace-sidebar" aria-expanded="true" aria-label="Hide sidebar" title="Hide sidebar"><span aria-hidden="true">☰</span></button><?php endif; ?>
    <a class="brand" href="/ecms_v1/" aria-label="ECMS home">
        <img class="brand-mark" src="/ecms_v1/assets/ecms-mark.svg" alt="">
        <span class="brand-copy">
            <strong>ECMS</strong>
            <small>Education Complaint Management System</small>
        </span>
    </a>
    </div>
    <div class="topbar-right">
        <?php if (!empty($_SESSION['user_id'])): ?>
            <div class="topbar-user"><span class="user-avatar"><?= h(strtoupper(substr($_SESSION['full_name'], 0, 1))) ?></span><span class="user-name"><?= h($_SESSION['full_name']) ?><small><?= h($_SESSION['role']) ?></small></span><a class="text-link" href="/ecms_v1/logout.php">Log out</a></div>
        <?php endif; ?>
    </div>
</header>
<?php if (!empty($_SESSION['user_id'])): ?>
<div class="workspace-layout">
    <aside class="workspace-sidebar" id="workspace-sidebar" aria-label="Main navigation">
        <div class="sidebar-section-label">WORKSPACE</div>
        <?php
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $role = $_SESSION['role'] ?? '';
    $navItems = [['dashboard.php','Overview','⌂']];
    if ($role === 'Student') {
        $navItems = array_merge($navItems, [['complaints.php','My complaints','▤'],['new_complaint.php','Submit complaint','＋'],['notifications.php','Notifications','◉']]);
    } elseif ($role === 'MPP Welfare') {
        $navItems = array_merge($navItems, [['complaints.php','Complaint queue','▤'],['notifications.php','Notifications','◉']]);
    } elseif ($role === 'Staff Department') {
        $navItems = array_merge($navItems, [['complaints.php','My department','▤'],['notifications.php','Notifications','◉']]);
    } elseif ($role === 'Administrator') {
        $navItems = array_merge($navItems, [['complaints.php','All complaints','▤'],['admin_reports.php','Reports','▥'],['admin_users.php','Manage users','♙'],['notifications.php','Notifications','◉']]);
    }
foreach ($navItems as [$url, $label, $icon]):
    $active = $currentPage === $url || ($url === 'complaints.php' && $currentPage === 'complaint_detail.php');
    ?>
        <a class="sidebar-link<?= $active ? ' active' : '' ?>" href="/ecms_v1/<?= h($url) ?>"><span class="sidebar-icon" aria-hidden="true"><?= h($icon) ?></span><span><?= h($label) ?></span><?php if ($url === 'notifications.php'): ?><span class="sidebar-arrow">›</span><?php endif; ?></a>
        <?php endforeach; ?>
        <div class="sidebar-help"><span class="help-dot"></span><strong>Need a hand?</strong><span>Contact the ECMS project team.</span><a href="mailto:support@example.edu">Email support <span>↗</span></a></div>
    </aside>
    <div class="workspace-main">
<?php endif; ?>
<main class="page-shell">
