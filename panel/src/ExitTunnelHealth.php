<?php

namespace App;

use App\Models\Setting;
use App\Singbox\ExitOutboundBuilder;

/**
 * Реальная проверка туннеля до exit-сервера, а не самого сервера: просим
 * локальный sing-box (Clash API) сходить на PROBE_URL именно через outbound
 * exit-N и, если есть резервные порты, через каждый порт отдельно.
 *
 * Зачем: DPI на пути до exit может начать портить Reality-рукопожатия на
 * :443 — сервер был «online» по SSH, ping ходил, а весь трафик через exit
 * висел. Старый health_check пинговал через WG-интерфейс, которого у
 * sing-box-exit'ов нет, и ничего не заметил.
 *
 * Письмо админу (получатель — как у ServerAlerts) шлётся на смену состояния:
 *  - туннель лёг (DOWN_AFTER проверок подряд) / снова работает;
 *  - часть портов перестала проходить, но urltest-группа уже увела трафик
 *    на живой порт (предупреждение — пора проверить/добавить порты).
 */
class ExitTunnelHealth
{
    /** Сколько неудачных проверок подряд (раз в минуту) считать падением. */
    public const DOWN_AFTER = 3;
    private const TIMEOUT_MS = 5000;

    /** Задержка через outbound $tag в мс, null — не прошло. */
    public static function probe(string $tag, int $timeoutMs = self::TIMEOUT_MS): ?int
    {
        $url = 'http://' . TrafficCollector::CLASH_LISTEN . '/proxies/' . rawurlencode($tag)
            . '/delay?timeout=' . $timeoutMs . '&url=' . rawurlencode(ExitOutboundBuilder::PROBE_URL);
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . TrafficCollector::secret() . "\r\n",
            'timeout' => $timeoutMs / 1000 + 2,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return (is_array($data) && isset($data['delay']) && (int) $data['delay'] > 0) ? (int) $data['delay'] : null;
    }

    /**
     * Проверка одного exit'а: общий результат + по портам.
     *
     * @return array{ok:bool, latency:?int, ports: array<int,?int>}
     */
    public static function check(array $es): array
    {
        $id = (int) $es['id'];
        $alt = ExitOutboundBuilder::altPorts($es);
        $ports = [];
        if ($alt) {
            foreach (array_merge([(int) $es['endpoint_port']], $alt) as $port) {
                $ports[$port] = self::probe(ExitOutboundBuilder::memberTag($id, $port));
            }
            $alive = array_filter($ports, fn($d) => $d !== null);
            return ['ok' => (bool) $alive, 'latency' => $alive ? min($alive) : null, 'ports' => $ports];
        }
        $latency = self::probe(ExitOutboundBuilder::tag($id));
        return ['ok' => $latency !== null, 'latency' => $latency, 'ports' => [(int) $es['endpoint_port'] => $latency]];
    }

    /**
     * Учитывает результат проверки и решает, слать ли письмо. Состояние
     * (счётчик неудач, текущий статус, мёртвые порты) — в Setting, чтобы
     * письмо уходило один раз на событие, а не каждую минуту.
     *
     * @return ?array{subject:string, body:string} письмо, если нужно (для тестов), уже отправленное
     */
    public static function record(array $es, array $result): ?array
    {
        $key = 'exit_tunnel_state_' . (int) $es['id'];
        $state = json_decode((string) Setting::get($key, ''), true) ?: ['status' => 'unknown', 'fails' => 0, 'dead' => []];

        [$dead, $streaks] = self::deadPorts($result['ports'], (array) ($state['dead'] ?? []), (array) ($state['streaks'] ?? []));
        $fails = $result['ok'] ? 0 : ((int) $state['fails'] + 1);
        $status = $result['ok'] ? 'up' : ($fails >= self::DOWN_AFTER ? 'down' : $state['status']);

        $mail = self::transitionMail($es, $state, $status, $dead, $result);
        Setting::set($key, json_encode(['status' => $status, 'fails' => $fails, 'dead' => $dead, 'streaks' => $streaks]));

        if ($mail && ServerAlerts::enabled() && ($to = ServerAlerts::recipient())) {
            try {
                Mailer::send($to, $mail['subject'], $mail['body']);
                Models\AuditLog::record('exit.tunnel_alert', $es['name'] . ': ' . $mail['subject']);
            } catch (\Throwable $e) {
                error_log('ExitTunnelHealth mail failed: ' . $e->getMessage());
            }
        }
        return $mail;
    }

    /** Чистая функция: какое письмо (если вообще) нужно при переходе состояния. */
    public static function transitionMail(array $es, array $state, string $status, array $dead, array $result): ?array
    {
        $name = (string) ($es['name'] ?? ('#' . ($es['id'] ?? '?')));
        $host = (string) ($es['endpoint_host'] ?? '');
        $when = gmdate('Y-m-d H:i') . ' UTC';
        $portsLine = self::portsLine($result['ports']);

        if ($state['status'] === 'up' && $status === 'down') {
            return [
                'subject' => "[VPS Router] Туннель до exit НЕ РАБОТАЕТ: {$name}",
                'body' => "Трафик через exit «{$name}» ({$host}) не проходит уже "
                    . self::DOWN_AFTER . " проверки подряд.\nВремя: {$when}\nПорты: {$portsLine}\n\n"
                    . "Сам сервер может отвечать по SSH/ping — проверяется именно туннель (sing-box → exit).\n"
                    . "Частая причина — DPI на пути до exit режет порт/рукопожатие: добавьте exit'у резервные\n"
                    . "порты (protocol_params.alt_ports), переприменить exit и сделать Apply.\n",
            ];
        }
        if ($state['status'] === 'down' && $status === 'up') {
            return [
                'subject' => "[VPS Router] Туннель до exit снова работает: {$name}",
                'body' => "Трафик через exit «{$name}» ({$host}) снова проходит.\nВремя: {$when}\nПорты: {$portsLine}\n",
            ];
        }
        // Группа жива, но набор мёртвых портов изменился в худшую сторону —
        // urltest уже переключился, но запас портов тает.
        if ($status === 'up' && count($result['ports']) > 1) {
            $newlyDead = array_diff($dead, (array) ($state['dead'] ?? []));
            if ($newlyDead && $state['status'] !== 'unknown') {
                return [
                    'subject' => "[VPS Router] Exit {$name}: порт(ы) " . implode(', ', $newlyDead) . ' перестали проходить',
                    'body' => "Часть портов exit «{$name}» ({$host}) больше не пропускает трафик; sing-box уже\n"
                        . "автоматически перевёл трафик на рабочий порт, для пользователей всё работает.\n"
                        . "Время: {$when}\nПорты: {$portsLine}\n\n"
                        . "Если живых портов останется мало — добавьте новые резервные порты.\n",
                ];
            }
        }
        return null;
    }

    /**
     * Гистерезис по портам: одиночные таймауты пробы под нагрузкой — норма,
     * поэтому порт считается мёртвым после DOWN_AFTER неудач подряд и живым
     * снова — после DOWN_AFTER удач подряд. Серия: >0 — удачи, <0 — неудачи.
     *
     * @param array<int,?int> $ports
     * @return array{0:int[],1:array<int,int>} [мёртвые порты, новые серии]
     */
    public static function deadPorts(array $ports, array $prevDead, array $prevStreaks): array
    {
        $dead = array_map('intval', $prevDead);
        $streaks = [];
        foreach ($ports as $port => $delay) {
            $s = (int) ($prevStreaks[$port] ?? 0);
            $s = $delay === null ? min($s, 0) - 1 : max($s, 0) + 1;
            $streaks[$port] = $s;
            if ($s <= -self::DOWN_AFTER && !in_array($port, $dead, true)) {
                $dead[] = $port;
            } elseif ($s >= self::DOWN_AFTER) {
                $dead = array_values(array_diff($dead, [$port]));
            }
        }
        // Порты, которых больше нет в конфиге, забываем.
        $dead = array_values(array_intersect($dead, array_keys($ports)));
        sort($dead);
        return [$dead, $streaks];
    }

    /** @param array<int,?int> $ports */
    private static function portsLine(array $ports): string
    {
        $parts = [];
        foreach ($ports as $port => $delay) {
            $parts[] = $port . ($delay === null ? ' — нет' : " — {$delay} мс");
        }
        return implode('; ', $parts);
    }
}
