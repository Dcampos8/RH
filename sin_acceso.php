<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branding.php';
require_login();
$branding = branding_settings();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso limitado · Checador</title><link rel="stylesheet" href="assets/app.css"></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main><div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Recursos Humanos · CHECADOR</div></div><section class="card"><h1>Acceso limitado</h1><p>Tu cuenta todavía no tiene secciones asignadas. Pide al administrador que habilite los permisos que necesitas.</p><?php if (has_permission('sync_run')): ?><form method="post" action="sync.php"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Sincronizar reloj</button></form><?php endif; ?><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Cerrar sesión</button></form></section></main></body></html>
