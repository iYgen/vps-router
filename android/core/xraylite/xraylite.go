// Package xraylite — минимальная обёртка ядра Xray для Android-приложения VPS Router.
// Собирается в одну библиотеку с libbox (один Go-рантайм). Xray поднимает локальный
// SOCKS-вход, sing-box держит TUN и отдаёт туда трафик; наружу Xray ходит по VLESS+XHTTP.
package xraylite

import (
	"bytes"
	"sync"

	"github.com/xtls/xray-core/core"
	_ "github.com/xtls/xray-core/main/distro/all"
)

var (
	mu   sync.Mutex
	inst *core.Instance
)

func load(configJSON string) (*core.Config, error) {
	return core.LoadConfig("json", bytes.NewReader([]byte(configJSON)))
}

// Check проверяет конфиг без запуска.
func Check(configJSON string) error {
	_, err := load(configJSON)
	return err
}

// Start запускает Xray с конфигом (JSON). Предыдущий экземпляр останавливается.
func Start(configJSON string) error {
	mu.Lock()
	defer mu.Unlock()
	stopLocked()
	cfg, err := load(configJSON)
	if err != nil {
		return err
	}
	i, err := core.New(cfg)
	if err != nil {
		return err
	}
	if err := i.Start(); err != nil {
		i.Close()
		return err
	}
	inst = i
	return nil
}

// Stop останавливает Xray.
func Stop() {
	mu.Lock()
	defer mu.Unlock()
	stopLocked()
}

func stopLocked() {
	if inst != nil {
		inst.Close()
		inst = nil
	}
}

// Running — запущен ли Xray.
func Running() bool {
	mu.Lock()
	defer mu.Unlock()
	return inst != nil
}

// Version — версия ядра Xray.
func Version() string { return core.Version() }
