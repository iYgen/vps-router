<?php

namespace App;

use App\Models\PolicyProfile;
use App\Models\PolicyVersion;
use App\Models\Rule;

/**
 * Строит canonical Client Routing Policy (docs/infrastructure-ui.md раздел
 * 4/46) из уже существующих rule_groups/rules, назначенных профилю через
 * policy_profile_routes. Читает ТЕ ЖЕ данные, что SingboxConfigBuilder, но
 * рендерит в другой, платформо-независимый формат — никогда не пишет
 * sing-box config (раздел 47 дока: это отдельный слой).
 *
 * geosite-правила пропускаются с явным warning: у renderer'ов (Keenetic и
 * т.п.) нет доступа к sing-box binary geosite-спискам, разворачивать их в
 * домены здесь не на чем — честнее пропустить, чем придумать поведение.
 */
class ClientPolicyBuilder
{
    /**
     * @return array{policy: array, warnings: string[]}
     */
    public function build(int $profileId): array
    {
        $profile = PolicyProfile::find($profileId);
        if (!$profile) {
            throw new \InvalidArgumentException('Профиль не найден');
        }

        $warnings = [];
        $rules = [];
        $priority = 1000;

        foreach (PolicyProfile::routes($profileId) as $group) {
            if (!$group['enabled']) {
                continue;
            }
            $target = null;
            if (!empty($group['server_set_id'])) {
                $target = 'server_set:' . $group['server_set_id'];
            } elseif (!empty($group['exit_server_id'])) {
                $target = 'exit_server:' . $group['exit_server_id'];
            }
            if ($target === null) {
                $warnings[] = "Маршрут «{$group['name']}» не назначен ни на exit-сервер, ни на набор — пропущен.";
                continue;
            }

            $groupRules = Rule::forGroup((int) $group['id']);
            if (empty($groupRules)) {
                $warnings[] = "Маршрут «{$group['name']}» включён, но не содержит правил — пропущен.";
                continue;
            }

            foreach ($groupRules as $rule) {
                if ($rule['type'] === 'geosite') {
                    $warnings[] = "Маршрут «{$group['name']}»: geosite-правило «{$rule['value']}» не переносится в Client Policy (нет разворачивания geosite в домены для внешних consumer'ов) — добавьте точные домены, если нужно применить и здесь.";
                    continue;
                }

                $rules[] = [
                    'priority' => $priority,
                    'enabled' => true,
                    'matcher' => ['type' => $rule['type'], 'value' => $rule['value']],
                    'action' => ['type' => 'proxy', 'target' => $target],
                    'source_route' => $group['name'],
                ];
            }
            $priority -= 10;
        }

        $version = PolicyVersion::nextVersionNumber($profileId);
        $policy = [
            'version' => $version,
            'profile' => $profile['name'],
            'default_action' => $profile['default_action'],
            'rules' => $rules,
        ];
        $policy['hash'] = hash('sha256', json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['policy' => $policy, 'warnings' => $warnings];
    }

    /** Строит и сохраняет новую версию (черновик — published_at=NULL). Не синхронизирует устройства сама. */
    public function buildAndSaveDraft(int $profileId, ?string $createdBy): array
    {
        $result = $this->build($profileId);
        $version = (int) $result['policy']['version'];
        PolicyVersion::create($profileId, $version, $result['policy'], $createdBy, false);
        return $result;
    }

    /** Публикует уже посчитанную (или свежесобранную) политику — published_at проставляется. */
    public function publish(int $profileId, ?string $createdBy): array
    {
        $result = $this->build($profileId);
        $version = (int) $result['policy']['version'];
        PolicyVersion::create($profileId, $version, $result['policy'], $createdBy, true);
        return $result;
    }
}
