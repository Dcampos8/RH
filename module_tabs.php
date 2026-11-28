<?php
declare(strict_types=1);

function render_module_tabs(string $activeModule): void
{
    $canUseChecador = is_admin_user();
    foreach (['report_view', 'employee_view', 'employee_create', 'schedule_view', 'schedule_manage', 'platform_users_manage', 'settings_manage', 'sync_run'] as $permission) {
        if (has_permission($permission)) {
            $canUseChecador = true;
            break;
        }
    }

    $checadorRoute = 'sin_acceso.php';
    foreach ([
        'report_view' => 'index.php',
        'employee_view' => 'empleados.php',
        'schedule_view' => 'horarios.php',
        'platform_users_manage' => 'users.php',
        'settings_manage' => 'settings.php',
        'sync_run' => 'sin_acceso.php',
    ] as $permission => $route) {
        if (has_permission($permission)) {
            $checadorRoute = $route;
            break;
        }
    }
    ?>
    <nav class="module-tabs" aria-label="Módulos principales" role="tablist">
        <?php if ($canUseChecador): ?>
            <a role="tab" href="<?=h($checadorRoute)?>" <?=$activeModule === 'checador' ? 'aria-current="page"' : ''?>>Checador</a>
        <?php endif; ?>
        <?php if (has_permission('legal_notifications_view')): ?>
            <a role="tab" href="notificaciones_legales.php" <?=$activeModule === 'legal' ? 'aria-current="page"' : ''?>>Notificaciones legales</a>
        <?php endif; ?>
        <form class="module-logout" method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </nav>
    <?php
}
