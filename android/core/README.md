# Ядро приложения: sing-box + Xray (`app/libs/libbox.aar`)

Одна gomobile-библиотека с двумя пакетами (один Go-рантайм — две отдельные gomobile-библиотеки
в одном приложении конфликтуют):

- `libbox` из sing-box v1.14.4 — TUN, VLESS+Reality, Hysteria2, Trojan, Shadowsocks;
- `xraylite` (каталог `xraylite/` здесь) — обёртка Xray-core v1.260327.0 (Xray 26.3.27) для VLESS+XHTTP.
  sing-box держит TUN и отдаёт трафик в локальный SOCKS-вход Xray (127.0.0.1, одноразовый логин/пароль).

## Сборка

Нужны Go ≥ 1.26, Android NDK 28, JDK (javac в PATH), `gomobile`/`gobind` из `github.com/sagernet/gomobile@v0.1.12`.

```bash
git clone --branch v1.14.4 https://github.com/SagerNet/sing-box && cd sing-box
mkdir -p experimental/xraylite && cp <repo>/android/core/xraylite/*.go experimental/xraylite/
go get github.com/xtls/xray-core@v1.260327.0 && go mod tidy
go test ./experimental/xraylite          # XHTTP-проверка; CHAIN_DIR/SINGBOX — проверка всей цепочки
gomobile bind -o libbox.aar -target android/arm64,android/arm -androidapi 24 \
  -javapkg=io.nekohasekai -libname=box -trimpath -buildvcs=false \
  -ldflags "-X github.com/sagernet/sing-box/constant.Version=1.14.4 -X runtime.godebugDefault=multipathtcp=0,tlssha1=1 -checklinkname=0 -s -w -buildid=" \
  -tags "with_gvisor,with_quic,with_utls,with_clash_api,badlinkname,tfogo_checklinkname0" \
  ./experimental/libbox ./experimental/xraylite
cp libbox.aar <repo>/android/app/libs/libbox.aar
```
