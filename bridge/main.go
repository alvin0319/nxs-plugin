package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"os"
	"os/signal"
	"strings"

	"github.com/df-mc/go-nethernet"
	"github.com/df-mc/go-nxs"
)

func main() {
	var (
		socketPath  = flag.String("socket", "", "Unix socket path the plugin connects to")
		origin      = flag.String("origin", "", "HTTPS origin of the NXS provider")
		stateDir    = flag.String("state", "", "directory holding the state of this instance")
		addr        = flag.String("addr", ":19133", "UDP address players connect to")
		endpoints   = flag.String("endpoints", "", "comma-separated public UDP endpoints to advertise")
		capacity    = flag.Int("capacity", 0, "maximum number of players admitted through NXS")
		label       = flag.String("label", "", "label of this instance at the provider")
		software    = flag.String("software", "", "name of the host software reported to the provider")
		version     = flag.String("software-version", "", "version of the host software reported to the provider")
		webSocket   = flag.Bool("websocket", true, "use the WebSocket control transport of the provider")
		assisted    = flag.Bool("assisted-joins", false, "accept assisted joins over the WebSocket control transport")
		diagnostics = flag.Bool("diagnostics", false, "answer connectivity diagnostics of the provider")
		outcomes    = flag.Bool("game-outcomes", true, "report game join outcomes to the provider")
		stdinEOF    = flag.Bool("exit-on-stdin-eof", false, "exit when standard input is closed")
		debug       = flag.Bool("debug", false, "enable debug logging")
	)
	flag.Parse()

	level := slog.LevelInfo
	if *debug {
		level = slog.LevelDebug
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: level}))

	conf := nxs.Config{
		Origin:        *origin,
		Token:         os.Getenv("NXS_TOKEN"),
		StateDir:      *stateDir,
		Address:       *addr,
		Capacity:      *capacity,
		Label:         *label,
		WebSocket:     *webSocket,
		AssistedJoins: *assisted,
		Diagnostics:   *diagnostics,
		GameOutcomes:  *outcomes,
		Log:           log,
	}
	if *software != "" {
		conf.Software = &nxs.Software{Name: *software, Version: *version}
	}
	for _, e := range strings.Split(*endpoints, ",") {
		if e = strings.TrimSpace(e); e == "" {
			continue
		}
		ap, err := netip.ParseAddrPort(e)
		if err != nil {
			log.Error("invalid endpoint", "endpoint", e, "error", err)
			os.Exit(2)
		}
		conf.Endpoints = append(conf.Endpoints, ap)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	if *stdinEOF {
		go func() {
			_, _ = io.Copy(io.Discard, os.Stdin)
			log.Info("standard input closed, shutting down")
			stop()
		}()
	}

	if err := run(ctx, conf, *socketPath, log); err != nil {
		log.Error(err.Error())
		os.Exit(1)
	}
}

func run(ctx context.Context, conf nxs.Config, socketPath string, log *slog.Logger) error {
	if socketPath == "" {
		return errors.New("socket path is required")
	}
	provider, err := nxs.New(ctx, conf)
	if err != nil {
		return fmt.Errorf("start provider: %w", err)
	}
	defer provider.Close()
	provider.Pause()

	listener, err := nethernet.ListenConfig{Log: log}.Listen(provider)
	if err != nil {
		return fmt.Errorf("listen: %w", err)
	}
	defer listener.Close()

	_ = os.Remove(socketPath)
	ipcListener, err := net.Listen("unix", socketPath)
	if err != nil {
		return fmt.Errorf("listen on %s: %w", socketPath, err)
	}
	defer os.Remove(socketPath)
	defer ipcListener.Close()

	log.Info("NXS bridge ready", "addr", provider.Addr(), "publicAddress", provider.PublicAddress(), "socket", socketPath)

	r := newRelay(provider, listener, log)
	go r.acceptPlayers()
	go func() {
		<-ctx.Done()
		_ = ipcListener.Close()
		_ = listener.Close()
	}()

	for {
		conn, err := ipcListener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return fmt.Errorf("accept plugin connection: %w", err)
		}
		log.Info("plugin connected")
		provider.Resume()
		r.serve(ctx, newIPC(conn))
		provider.Pause()
		_ = conn.Close()
		log.Info("plugin disconnected")
		if ctx.Err() != nil {
			return nil
		}
	}
}
