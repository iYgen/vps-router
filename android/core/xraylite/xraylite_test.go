package xraylite

import (
	"bytes"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"
	"time"

	"github.com/xtls/xray-core/core"
)

const uuid = "0b6b6a63-1f7e-4d55-9f43-2c6a8f0b5a11"

func TestXhttpChain(t *testing.T) {
	web := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "hello-xhttp") }))
	defer web.Close()

	srvCfg := fmt.Sprintf(`{"log":{"loglevel":"warning"},"inbounds":[{"listen":"127.0.0.1","port":18443,"protocol":"vless",
	 "settings":{"clients":[{"id":"%s"}],"decryption":"none"},
	 "streamSettings":{"network":"xhttp","xhttpSettings":{"path":"/abc123","mode":"auto"}}}],
	 "outbounds":[{"protocol":"freedom"}]}`, uuid)
	sc, err := core.LoadConfig("json", bytes.NewReader([]byte(srvCfg)))
	if err != nil { t.Fatal(err) }
	srv, err := core.New(sc)
	if err != nil { t.Fatal(err) }
	if err := srv.Start(); err != nil { t.Fatal(err) }
	defer srv.Close()

	cli := fmt.Sprintf(`{"log":{"loglevel":"warning"},"inbounds":[{"listen":"127.0.0.1","port":18080,"protocol":"socks","settings":{"udp":true}}],
	 "outbounds":[{"protocol":"vless","settings":{"vnext":[{"address":"127.0.0.1","port":18443,"users":[{"id":"%s","encryption":"none"}]}]},
	 "streamSettings":{"network":"xhttp","xhttpSettings":{"path":"/abc123","mode":"auto","extra":{"xPaddingBytes":"100-1000"}}}}]}`, uuid)
	if err := Check(cli); err != nil { t.Fatal(err) }
	if err := Start(cli); err != nil { t.Fatal(err) }
	defer Stop()
	time.Sleep(300 * time.Millisecond)

	proxy, _ := url.Parse("socks5h://127.0.0.1:18080")
	hc := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(proxy)}, Timeout: 10 * time.Second}
	resp, err := hc.Get(web.URL)
	if err != nil { t.Fatal(err) }
	b, _ := io.ReadAll(resp.Body)
	if string(b) != "hello-xhttp" { t.Fatalf("got %q", b) }
	t.Logf("xray %s: through XHTTP ok", Version())
}
