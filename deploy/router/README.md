# Узел-роутер — установка

Узел-роутер — это главный сервер: на нём живут **PHP-панель**, **sing-box**
(вход устройств + маршрутизация) и **туннели** (AmneziaWG/WireGuard) к
exit-узлам. Существующие сайты и nginx на сервере не затрагиваются — панель и
вход устройств работают на отдельных портах/IP.

## Самый простой путь — автоматический установщик

Из распакованного репозитория на свежем сервере:

```bash
sudo bash deploy/install.sh
```

Он поставит PHP+расширения, sing-box, (опц.) AmneziaWG, развернёт код в
`/var/www/panel`, создаст `/etc/panel/config.php`, настроит nginx-vhost, sudoers,
каталоги, применит миграции. Затем откроете адрес в браузере — запустится
визуальный мастер (создание админа, Reality-ключи, протоколы). См. корневой
[`README.md`](../../README.md).

Ниже — **ручная установка** (если нужен полный контроль или установщик не
подходит под вашу ОС).

---

## Предпосылки (ручной путь)

- Отдельный IPv4 (или порт) для входа устройств, чтобы не конфликтовать с сайтами на 443.
- (Опц.) DNS A-запись для домена панели → IP сервера. Без домена панель работает по IP.
- Данные exit-узла (см. [`../exit/README.md`](../exit/README.md)) — публичный ключ и endpoint.

## 1. Пакеты

```bash
# sing-box (официальный установщик SagerNet)
curl -fsSL https://sing-box.app/install.sh | sh

# AmneziaWG (инструменты + модуль) — если сервер уже использует AmneziaWG,
# инструменты обычно стоят: which awg-quick
# иначе см. https://github.com/amnezia-vpn/amneziawg-tools

# PHP + расширения (пример для Debian/Ubuntu; php-sodium нужен для шифрования
# SSH-ключей серверов, см. panel/src/Secrets.php)
sudo apt install php-fpm php-sqlite3 sqlite3 php-sodium php-mbstring php-curl php-xml composer
```

## 2. Каталоги и права

```bash
sudo mkdir -p /etc/sing-box/rule-sets /etc/amnezia /var/lib/panel /var/www/panel
sudo chown -R www-data:www-data /etc/sing-box /etc/amnezia /var/lib/panel
```

`www-data` владеет этими каталогами напрямую — панель пишет туда конфиги без sudo.
Единственная операция, требующая root (перезапуск сервисов), вынесена в
`apply-router.sh`.

## 3. Код панели

```bash
sudo cp -r panel/* /var/www/panel/
sudo chown -R www-data:www-data /var/www/panel

sudo mkdir -p /etc/panel
sudo cp panel/config.php.example /etc/panel/config.php
sudo nano /etc/panel/config.php     # заполнить реальные значения
sudo chown root:www-data /etc/panel/config.php
sudo chmod 640 /etc/panel/config.php

cd /var/www/panel
sudo -u www-data composer install --no-dev   # ставит phpseclib (SSH-клиент)
sudo -u www-data php bin/create-admin.php admin   # первый администратор
```

## 4. Apply-скрипт и sudoers

```bash
sudo cp deploy/router/apply-router.sh /usr/local/sbin/apply-router.sh
sudo chown root:root /usr/local/sbin/apply-router.sh
sudo chmod 750 /usr/local/sbin/apply-router.sh

sudo cp deploy/router/sudoers.d/panel-apply /etc/sudoers.d/panel-apply
sudo chmod 440 /etc/sudoers.d/panel-apply
sudo visudo -cf /etc/sudoers.d/panel-apply     # проверить синтаксис
```

### 4.1. Автопровижининг (опционально, рекомендуется)

Позволяет ставить sing-box/amneziawg-tools и настраивать exit-узлы кнопками из
панели вместо ручных SSH-команд (см. `docs/infrastructure-ui.md`).

```bash
sudo cp deploy/provision/entry-vps-install.sh /usr/local/sbin/vpsrouter-vps-install.sh
sudo chown root:root /usr/local/sbin/vpsrouter-vps-install.sh
sudo chmod 750 /usr/local/sbin/vpsrouter-vps-install.sh

sudo cp deploy/router/sudoers.d/panel-provision /etc/sudoers.d/panel-provision
sudo chmod 440 /etc/sudoers.d/panel-provision
sudo visudo -cf /etc/sudoers.d/panel-provision

# Скрипты для УДАЛЁННЫХ exit-узлов — панель заливает их по SFTP при провижининге
sudo mkdir -p /usr/local/share/panel/provision
sudo cp deploy/provision/*.sh /usr/local/share/panel/provision/   # включая _lib.sh
sudo chmod 755 /usr/local/share/panel/provision/*.sh
```

### 4.2. Защита IP, на котором живут сайты

`panel-hardening.sh` закрывает снаружи опасные для репутации IP порты (открытый
SOCKS5, FTP, лишний WireGuard) и при загрузке восстанавливает правила firewall
панели (порты протоколов устройств):

```bash
sudo install -m 750 deploy/router/panel-hardening.sh /usr/local/sbin/
sudo install -m 644 deploy/router/panel-hardening.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now panel-hardening
```

### 4.3. Маскировка Reality под свой сайт на этом же сервере

Сканер, постучавшийся в VLESS-порт без ключа, увидит настоящий сайт с валидным
сертификатом. Если системный nginx не умеет TLS 1.3 (нужен Reality), ставится
локальный TLS-фронт только на `127.0.0.1:8443`:

```bash
sudo deploy/router/reality-front-install.sh ваш-домен.tld
```

Затем в панели: Настройки → Camouflage-домен = `ваш-домен.tld`, «Куда отдавать
рукопожатие» = `127.0.0.1:8443` → Сохранить и применить. Ссылки устройств после
смены домена переоткрыть на странице «Устройства».

### Обновление уже установленной панели

```bash
sudo cp -r panel/* /var/www/panel/ && sudo chown -R www-data:www-data /var/www/panel
sudo cp deploy/router/apply-router.sh /usr/local/sbin/apply-router.sh
sudo cp deploy/provision/entry-vps-install.sh /usr/local/sbin/vpsrouter-vps-install.sh
sudo cp deploy/provision/*.sh /usr/local/share/panel/provision/
```

Миграции БД применяются автоматически при первом открытии панели. В
`/etc/panel/config.php` должны быть заданы `provision_install_script` и
`provision_scripts_dir` (см. `config.php.example`).

## 5. nginx vhost для панели

```bash
sudo cp deploy/router/nginx/panel.conf /etc/nginx/sites-available/
sudo ln -s ../sites-available/panel.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ваш-домен.tld   # если есть домен
```

Проверьте, что путь к php-fpm sock в конфиге совпадает с реальным (`ls /run/php/`).

## 6. systemd unit для sing-box

Пакет sing-box ставит unit `sing-box.service` (читает `/etc/sing-box/config.json`):

```bash
sudo systemctl enable sing-box
```

Первый запуск будет неудачным, пока панель не сохранит первый валидный конфиг
(Настройки → Reality-инбаунд → Сохранить) — это ожидаемо.

## 6.1. Один IP: сайты и VPN на общем 443

Если на сервере уже есть сайты на 443 и второго IP нет — 443 можно разделить между
сайтами и VPN по SNI (L4-демукс nginx `stream` + `ssl_preread`), не терминируя TLS
на фронте (сертификаты сайтов сохраняются):

```bash
sudo bash deploy/preflight.sh                 # диагностика: кто держит 443, есть ли панель (ничего не меняет)
sudo bash deploy/router/share-443.sh          # план (dry-run)
sudo bash deploy/router/share-443.sh --apply --vpn-sni vpn.ваш-домен.tld
```

`share-443.sh` делает полный бэкап `/etc/nginx`, ставит stream-демукс (SNI VPN →
sing-box, остальное → сайты на `127.0.0.1:8443`), проверяет `nginx -t`, перезагружает
и делает health-check каждого домена с **авто-откатом** при сбое. Для серверов с
панелью управления (ISPmanager и т.п.) vhost'ы не переписываются автоматически —
скрипт подскажет переключить порт nginx штатной настройкой панели. После разделения
в панели vps_router укажите `reality_listen_ip=127.0.0.1`, `reality_listen_port=11443`.

## 7. Firewall

См. `deploy/router/firewall/` — подставить реальный IP входа устройств. Правила
для существующих сайтов не трогать.

Если планируете port knocking — сначала прочитайте про «Recovery access» в
`docs/infrastructure-ui.md`: без консоли хостера потеря SSH-доступа необратима.

## 8. Health-check по cron

Два независимых скрипта:
- `health_check.php` — доступность exit-узлов (WG-туннели). От него зависит
  реальный failover в наборах серверов (без него `last_health_ok_at` не
  обновляется, и панель откатывается на первого по приоритету вместо failover).
- `health_check_servers.php` — доступность узлов графа (красит online/offline).

```bash
sudo -u www-data crontab -e
* * * * * php /var/www/panel/bin/health_check.php >/dev/null 2>&1
* * * * * php /var/www/panel/bin/health_check_servers.php >/dev/null 2>&1
```

Проверить, что cron их выполняет: `grep panel /var/log/cron | tail`.

Необязательные, но полезные:
```bash
0 6 * * *   php /var/www/panel/bin/risk_scan.php >/dev/null 2>&1          # скан рисков
*/5 * * * * php /var/www/panel/bin/collect_exit_load.php >/dev/null 2>&1  # балансировка пула exit
```

## 9. Первый запуск

1. Открыть панель, залогиниться.
2. Настройки → Reality private/public key + short_id
   (`sing-box generate reality-keypair`, `sing-box generate rand 8 --hex`),
   camouflage-домен, IP входа, порт → Сохранить и применить.
3. Exit-серверы → добавить exit (ключи и endpoint из [`../exit/README.md`](../exit/README.md)).
4. Маршруты → создать группу (например `geosite: youtube` + точечные домены),
   назначить exit.
5. Устройства → создать устройство, скопировать `vless://…` в клиент
   (v2rayNG / NekoBox / Streisand и т.п.).
6. Проверить: выбранный трафик идёт через exit, остальное — напрямую; сайты на
   сервере доступны как раньше.
