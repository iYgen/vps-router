package xraylite

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"github.com/xtls/xray-core/core"
)

// Цепочка приложения: sing-box (вместо TUN — mixed-вход) → SOCKS с паролем → Xray → XHTTP-сервер → сайт.
func TestAppChain(t *testing.T) {
	dir := os.Getenv("CHAIN_DIR")
	if dir == "" { t.Skip() }
	web := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "hello-chain") }))
	defer web.Close()
	srvCfg := fmt.Sprintf(`{"inbounds":[{"listen":"127.0.0.1","port":18443,"protocol":"vless","settings":{"clients":[{"id":"%s"}],"decryption":"none"},
	 "streamSettings":{"network":"xhttp","xhttpSettings":{"path":"/abc123","mode":"auto"}}}],"outbounds":[{"protocol":"freedom"}]}`, uuid)
	sc, _ := core.LoadConfig("json", bytes.NewReader([]byte(srvCfg)))
	srv, err := core.New(sc); if err != nil { t.Fatal(err) }
	srv.Start(); defer srv.Close()

	xc, _ := os.ReadFile(filepath.Join(dir, "xray-client.json"))
	if err := Start(string(xc)); err != nil { t.Fatal(err) }
	defer Stop()

	var box map[string]any
	b, _ := os.ReadFile(filepath.Join(dir, "box-xray.json"))
	json.Unmarshal(b, &box)
	box["inbounds"] = []any{map[string]any{"type": "mixed", "listen": "127.0.0.1", "listen_port": 18090}}
	route := box["route"].(map[string]any)
	delete(route, "auto_detect_interface")
	out, _ := json.Marshal(box)
	cfgPath := filepath.Join(t.TempDir(), "box.json")
	os.WriteFile(cfgPath, out, 0600)
	cmd := exec.Command(os.Getenv("SINGBOX"), "run", "-c", cfgPath)
	cmd.Stderr = os.Stderr
	if err := cmd.Start(); err != nil { t.Fatal(err) }
	defer cmd.Process.Kill()
	time.Sleep(1500 * time.Millisecond)

	proxy, _ := url.Parse("socks5h://127.0.0.1:18090")
	hc := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(proxy)}, Timeout: 15 * time.Second}
	resp, err := hc.Get(web.URL)
	if err != nil { t.Fatal(err) }
	body, _ := io.ReadAll(resp.Body)
	if string(body) != "hello-chain" { t.Fatalf("got %q", body) }

	// Без пароля локальный вход Xray не пускает (защита от других приложений).
	noauth, _ := url.Parse("socks5h://127.0.0.1:18080")
	hc2 := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(noauth)}, Timeout: 5 * time.Second}
	if _, err := hc2.Get(web.URL); err == nil { t.Fatal("xray socks inbound accepted a client without password") }
	t.Log("chain ok, inbound rejects unauthenticated clients")
}
