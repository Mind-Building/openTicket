<?php
// Auto-load sidebar nav partial if the page didn't set $sidebarContent explicitly
if (!isset($sidebarContent) && isset($showSidebar) && $showSidebar) {
    $__phpSelf = $_SERVER['PHP_SELF'] ?? '';
    $__partialDir = __DIR__ . '/../partials/';
    ob_start();
    if (str_contains($__phpSelf, '/admin/')) {
        include $__partialDir . 'admin_nav.php';
    } elseif (str_contains($__phpSelf, '/presales/')) {
        include $__partialDir . 'presales_nav.php';
    }
    $sidebarContent = ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Ticket System' ?></title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
        }
        /* ── Desktop sidebar ──────────────────────────────────── */
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            margin: 0.25rem 0;
            border-radius: 0.5rem;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        /* ── Offcanvas sidebar (mobile) ───────────────────────── */
        #sidebarOffcanvas {
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }
        #sidebarOffcanvas .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 0.75rem 1rem;
            margin: 0.15rem 0;
            border-radius: 0.5rem;
        }
        #sidebarOffcanvas .nav-link:hover,
        #sidebarOffcanvas .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        /* ── Main content ─────────────────────────────────────── */
        .main-content {
            background: #f8f9fa;
            min-height: 100vh;
        }
    </style>
    <?= $additionalCss ?? '' ?>
</head>
<body>

<?php if (isset($showSidebar) && $showSidebar): ?>

    <!-- ── Mobile top bar (hidden on md+) ──────────────────────── -->
    <div class="d-flex d-md-none align-items-center justify-content-between px-3 py-2"
         style="background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));">
        <span class="text-white fw-semibold">
            <i class="bi bi-ticket-perforated"></i> Ticket System
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white-50 small"><?= htmlspecialchars(\App\Core\Auth::username() ?? '') ?></span>
            <button class="btn btn-outline-light btn-sm" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>
    </div>

    <!-- ── Offcanvas menu (mobile) ──────────────────────────────── -->
    <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="sidebarOffcanvas">
        <div class="offcanvas-header border-bottom border-white border-opacity-25">
            <h5 class="offcanvas-title text-white">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body p-2">
            <ul class="nav flex-column">
                <?= $sidebarContent ?? '' ?>
            </ul>
            <hr class="border-white border-opacity-25 mt-3">
            <a href="<?= url('logout.php') ?>" class="btn btn-outline-light btn-sm w-100">
                <i class="bi bi-box-arrow-right"></i> Abmelden
            </a>
        </div>
    </div>

<?php endif; ?>

    <div class="container-fluid">
        <div class="row">

            <?php if (isset($showSidebar) && $showSidebar): ?>
            <!-- ── Desktop sidebar (hidden on mobile) ──────────── -->
            <nav class="col-md-3 col-lg-2 sidebar p-0 d-none d-md-flex flex-column">
                <div class="p-3">
                    <h4 class="mb-4 text-white">
                        <i class="bi bi-ticket-perforated"></i> Ticket System
                    </h4>
                    <ul class="nav flex-column">
                        <?= $sidebarContent ?? '' ?>
                    </ul>
                </div>
            </nav>
            <?php endif; ?>

            <!-- ── Main content ────────────────────────────────── -->
            <main class="<?= isset($showSidebar) && $showSidebar ? 'col-12 col-md-9 col-lg-10' : 'col-12' ?> main-content p-3 p-md-4">

                <?php if (isset($showHeader) && $showHeader): ?>
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <h1 class="h4 h3-md mb-0"><?= $pageTitle ?? 'Dashboard' ?></h1>
                    <!-- Logout button: only on desktop (mobile has it in the offcanvas) -->
                    <div class="d-none d-md-flex align-items-center gap-2">
                        <span class="text-muted small"><?= htmlspecialchars(\App\Core\Auth::username() ?? '') ?></span>
                        <a href="<?= url('logout.php') ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-box-arrow-right"></i> Abmelden
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($flashMessage)): ?>
                <div class="alert alert-<?= $flashMessage['type'] ?? 'info' ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($flashMessage['message'] ?? '') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?= $content ?? '' ?>
            </main>

        </div>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
    <?php
    $__phpSelf = $_SERVER['PHP_SELF'] ?? '';
    if (str_contains($__phpSelf, '/admin/') || str_contains($__phpSelf, '/presales/')):
    ?>
    <script src="<?= url('libs/js/sortable-tables.js') ?>"></script>
    <?php endif; ?>
    <?= $additionalJs ?? '' ?>
</body>
</html>
