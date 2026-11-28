<?php
declare(strict_types=1);

function render_module_tabs(string $activeModule): void
{
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
        <?php if (has_permission('checador_module_view')): ?>
            <a role="tab" class="<?=$activeModule === 'checador' ? 'is-active' : ''?>" href="<?=h($checadorRoute)?>" <?=$activeModule === 'checador' ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"'?>>Checador</a>
        <?php endif; ?>
        <?php if (has_permission('legal_notifications_view')): ?>
            <a role="tab" class="<?=$activeModule === 'legal' ? 'is-active' : ''?>" href="notificaciones_legales.php" <?=$activeModule === 'legal' ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"'?>>Notificaciones legales</a>
        <?php endif; ?>
        <?php if (is_admin_user()): ?>
            <a role="tab" class="<?=$activeModule === 'admin' ? 'is-active' : ''?>" href="permisos.php" <?=$activeModule === 'admin' ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"'?>>Administración</a>
        <?php endif; ?>
        <form class="module-logout" method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </nav>
    <?php
}
