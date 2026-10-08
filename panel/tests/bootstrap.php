<?php

// Изолированное окружение для тестов: своя SQLite БД во временном каталоге,
// не пересекается с реальным /var/lib/panel/panel.db.

$testDir = sys_get_temp_dir() . '/vps_router_panel_tests_' . getmypid();
@mkdir($testDir, 0700, true);
@mkdir($testDir . '/sing-box', 0700, true);
@mkdir($testDir . '/amnezia', 0700, true);

$configPath = $testDir . '/config.php';
file_put_contents($configPath, '<?php return ' . var_export([
    'db_path' => $testDir . '/panel.db',
    'app_secret' => bin2hex(random_bytes(32)),
    'singbox_config_path' => $testDir . '/sing-box/config.json',
    'singbox_ruleset_dir' => $testDir . '/sing-box/rule-sets',
    'amnezia_conf_dir' => $testDir . '/amnezia',
    'amnezia_interfaces_list' => $testDir . '/amnezia/interfaces.list',
    'apply_script' => 'true',
    'reality_listen_ip' => '203.0.113.10',
    'reality_listen_port' => 443,
    'session_name' => 'panel_sess_test',
], true) . ';');

putenv('PANEL_CONFIG_PATH=' . $configPath);
$_SERVER['PANEL_CONFIG_PATH'] = $configPath;

require __DIR__ . '/../src/bootstrap.php';

register_shutdown_function(function () use ($testDir) {
    foreach (glob($testDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($testDir . '/*/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($testDir . '/sing-box');
    @rmdir($testDir . '/amnezia');
    @rmdir($testDir);
});
