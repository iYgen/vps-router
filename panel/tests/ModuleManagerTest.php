<?php

namespace Tests;

use App\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class ModuleManagerTest extends TestCase
{
    private function writeArchive(string $id, array $extra = [], array $files = null): string
    {
        $manifest = [
            'id' => $id, 'name' => 'Test ' . $id, 'version' => '1.0.0',
            'type' => 'router', 'description' => 'test',
            'provides' => ['exports' => [['format' => 'fmt-' . $id, 'label' => 'L ' . $id]]],
        ] + $extra;
        $bundle = ['manifest' => $manifest, 'files' => $files ?? ['module.json' => json_encode($manifest)]];
        $name = $id . '.vmod';
        file_put_contents(ModuleManager::archivesDir() . '/' . $name, json_encode($bundle));
        return $name;
    }

    public function testInstallActivateDeactivate(): void
    {
        $id = 'test-life-' . substr(md5((string) random_int(0, 1 << 30)), 0, 6);
        $archive = $this->writeArchive($id);

        $mod = ModuleManager::install($archive);
        $this->assertSame($id, $mod['id']);
        $this->assertFalse($mod['active'], 'после установки — выключен');
        // Файлы распакованы.
        $this->assertFileExists(ModuleManager::modulesDir() . '/' . $id . '/module.json');

        ModuleManager::activate($id);
        $this->assertTrue(ModuleManager::get($id)['active']);
        ModuleManager::deactivate($id);
        $this->assertFalse(ModuleManager::get($id)['active']);

        ModuleManager::remove($id, true);
    }

    public function testSettingsSurviveRemoveAndReinstall(): void
    {
        $id = 'test-set-' . substr(md5((string) random_int(0, 1 << 30)), 0, 6);
        $archive = $this->writeArchive($id);
        ModuleManager::install($archive);
        ModuleManager::saveSettings($id, ['token' => 'secret-123', 'n' => 7]);

        // Удаляем, СОХРАНЯЯ настройки.
        ModuleManager::remove($id, true);
        $this->assertNull(ModuleManager::get($id), 'модуль удалён');
        $this->assertTrue(ModuleManager::hasSettings($id), 'настройки сохранены');
        // Файлы удалены, но архив остался.
        $this->assertDirectoryDoesNotExist(ModuleManager::modulesDir() . '/' . $id);
        $this->assertFileExists(ModuleManager::archivesDir() . '/' . $archive, 'архив-установщик сохраняется');

        // Повторная установка подтягивает старые настройки (не перезаписывает).
        ModuleManager::install($archive);
        $this->assertSame('secret-123', ModuleManager::settings($id)['token']);

        // Удаляем БЕЗ сохранения — настройки стираются.
        ModuleManager::remove($id, false);
        $this->assertFalse(ModuleManager::hasSettings($id));
    }

    public function testReinstallDoesNotOverwriteKeptSettings(): void
    {
        $id = 'test-keep-' . substr(md5((string) random_int(0, 1 << 30)), 0, 6);
        $archive = $this->writeArchive($id);
        ModuleManager::install($archive);
        ModuleManager::saveSettings($id, ['k' => 'v1']);
        ModuleManager::remove($id, true);
        ModuleManager::install($archive); // install НЕ трогает module_settings
        $this->assertSame('v1', ModuleManager::settings($id)['k']);
        ModuleManager::remove($id, false);
    }

    public function testPathTraversalRejected(): void
    {
        $id = 'test-evil-' . substr(md5((string) random_int(0, 1 << 30)), 0, 6);
        $archive = $this->writeArchive($id, [], ['../../evil.php' => '<?php echo 1;']);
        $this->expectException(\RuntimeException::class);
        ModuleManager::install($archive);
    }

    public function testBuiltinRouterExportsAndEnforcement(): void
    {
        // ensureBuiltins ставит+активирует router-keenetic/mikrotik/openwrt.
        ModuleManager::all();
        $this->assertTrue(ModuleManager::isExportFormatActive('mikrotik'));
        $this->assertTrue(ModuleManager::isExportFormatActive('bat'));

        ModuleManager::deactivate('router-mikrotik');
        $this->assertFalse(ModuleManager::isExportFormatActive('mikrotik'), 'выключенный модуль — формат недоступен');
        $this->assertTrue(ModuleManager::isExportFormatActive('bat'), 'другие модули не задеты');

        ModuleManager::activate('router-mikrotik'); // вернуть как было
        $this->assertTrue(ModuleManager::isExportFormatActive('mikrotik'));
    }

    public function testFeatureGating(): void
    {
        ModuleManager::all(); // seed builtins
        // Встроенные feature-модули активны по умолчанию.
        $this->assertTrue(ModuleManager::featureActive('route-presets'));
        $this->assertTrue(ModuleManager::featureActive('update-check'));
        // Незнакомая фича (нет модуля) — не гейтится.
        $this->assertTrue(ModuleManager::featureActive('nonexistent-feature'));

        ModuleManager::deactivate('feature-route-presets');
        $this->assertFalse(ModuleManager::featureActive('route-presets'), 'выключенная фича скрыта');
        $this->assertTrue(ModuleManager::featureActive('update-check'), 'другие не задеты');

        ModuleManager::activate('feature-route-presets');
        $this->assertTrue(ModuleManager::featureActive('route-presets'));
    }

    public function testRemovedBuiltinFeatureStaysGated(): void
    {
        ModuleManager::all(); // seed
        // Удаляем встроенный feature-модуль — фича должна СКРЫТЬСЯ (каталог знает о ней).
        ModuleManager::remove('feature-free-exits', true);
        $this->assertFalse(ModuleManager::featureActive('free-exits'), 'удалённая каталожная фича скрыта');
        // Ставим обратно из сохранённого архива и активируем — фича возвращается.
        ModuleManager::install('feature-free-exits.vmod');
        ModuleManager::activate('feature-free-exits');
        $this->assertTrue(ModuleManager::featureActive('free-exits'));
    }
}
