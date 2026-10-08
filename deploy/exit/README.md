# Exit-узел — настройка

Exit-узел — это «выходной» сервер: он принимает туннель (AmneziaWG/WireGuard) от
узла-роутера и выпускает его трафик в интернет под своим IP. Больше он ничего не
обслуживает — ни панели, ни sing-box.

> Обычно всё это панель делает **сама** при автопровижининге (карточка сервера →
> «Установить/настроить»). Ручные шаги ниже нужны, только если вы настраиваете
> exit вручную или хотите понять, что происходит под капотом.

## 1. Использовать существующий WireGuard/AmneziaWG-сервер

Если сервер уже поднят (например приложением Amnezia), найдите его конфиг
(обычно `/etc/amnezia/amneziawg/*.conf` или `/etc/wireguard/*.conf`). Не
пересоздавайте инстанс с нуля — просто добавьте нового peer (узел-роутер).

```bash
sudo wg show
sudo cat /etc/amnezia/amneziawg/<iface>.conf   # или /etc/wireguard/<iface>.conf
```

## 2. Сгенерировать ключи для узла-роутера (если ещё нет)

```bash
wg genkey | tee router.key | wg pubkey > router.pub
wg genpsk > router.psk    # опционально preshared key
```

Приватный ключ (`router.key`) и адрес (например `10.90.0.2/32`) вносятся в панель
на узле-роутере (страница «Exit-серверы»). Публичный ключ (`router.pub`) — в
секцию peer'а ниже, на стороне exit-узла.

## 3. Добавить peer в конфиг сервера

```ini
[Peer]
PublicKey = <содержимое router.pub>
PresharedKey = <содержимое router.psk>   # если используете
AllowedIPs = 10.90.0.2/32
```

Применить без разрыва остальных клиентов:

```bash
sudo wg syncconf <iface> <(wg-quick strip <iface>)
```

## 4. Включить форвардинг и NAT

```bash
# /etc/sysctl.d/99-forward.conf
net.ipv4.ip_forward = 1
net.ipv6.conf.all.forwarding = 1
```

```bash
sudo sysctl --system

# NAT (проверить текущие: sudo iptables -t nat -L -n -v)
sudo iptables -t nat -A POSTROUTING -o <wan-interface> -j MASQUERADE
# сохранить правила (netfilter-persistent / iptables-persistent)
```

## 5. Проверка со стороны узла-роутера

После того как на узле-роутере поднят интерфейс `awg-exN` (панель делает это сама
при «Применить»):

```bash
sudo wg show awg-exN            # должен появиться handshake
ping -I awg-exN 8.8.8.8
```

## Добавление ещё одного резервного exit в будущем

Повторите шаги 1–5 на новом сервере и добавьте его в панель (страница
«Exit-серверы» → «Добавить»), указав endpoint и публичный ключ. Панель сама
сгенерирует локальный конфиг для узла-роутера и поднимет туннель — на стороне
нового exit ничего дополнительно менять не нужно. Можно объединить несколько
exit в **набор серверов** (Server Set) с автопереключением (failover).
