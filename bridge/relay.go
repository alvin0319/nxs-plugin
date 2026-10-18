package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/x509"
	"encoding/binary"
	"errors"
	"log/slog"
	"net/netip"
	"sync"
	"time"

	"github.com/df-mc/go-nethernet"
	"github.com/df-mc/go-nxs"
)

const (
	sendQueueSize    = 4096
	closeGracePeriod = time.Second
)

type outgoing struct {
	payload []byte
	receipt uint32
	close   bool
}

type session struct {
	id    uint64
	conn  *nethernet.Conn
	addr  netip.AddrPort
	queue chan outgoing
	once  sync.Once
	done  chan struct{}
}

func (s *session) close() {
	s.once.Do(func() {
		close(s.done)
		_ = s.conn.Close()
	})
}

type relay struct {
	provider *nxs.Provider
	listener *nethernet.Listener
	log      *slog.Logger

	mu       sync.Mutex
	plugin   *ipc
	nextID   uint64
	sessions map[uint64]*session
	blocked  map[netip.Addr]time.Time
}

func newRelay(provider *nxs.Provider, listener *nethernet.Listener, log *slog.Logger) *relay {
	return &relay{
		provider: provider,
		listener: listener,
		log:      log,
		sessions: map[uint64]*session{},
		blocked:  map[netip.Addr]time.Time{},
	}
}

func (r *relay) acceptPlayers() {
	for {
		c, err := r.listener.Accept()
		if err != nil {
			return
		}
		conn := c.(*nethernet.Conn)
		addr := remoteAddrPort(conn)

		r.mu.Lock()
		plugin := r.plugin
		if plugin == nil || r.isBlocked(addr.Addr()) {
			r.mu.Unlock()
			_ = conn.Close()
			continue
		}
		r.nextID++
		s := &session{id: r.nextID, conn: conn, addr: addr, queue: make(chan outgoing, sendQueueSize), done: make(chan struct{})}
		r.sessions[s.id] = s
		r.mu.Unlock()

		if err := plugin.write(s2pSessionOpen, s.id, shortString(addr.Addr().String()), u16(addr.Port())); err != nil {
			r.drop(s)
			continue
		}
		go r.readLoop(plugin, s)
		go r.writeLoop(plugin, s)
	}
}

func remoteAddrPort(conn *nethernet.Conn) netip.AddrPort {
	addr, ok := conn.RemoteAddr().(*nethernet.Addr)
	if !ok || addr.SelectedCandidate == nil {
		return netip.AddrPortFrom(netip.IPv4Unspecified(), 0)
	}
	ip, err := netip.ParseAddr(addr.SelectedCandidate.Address)
	if err != nil {
		return netip.AddrPortFrom(netip.IPv4Unspecified(), 0)
	}
	return netip.AddrPortFrom(ip.Unmap(), addr.SelectedCandidate.Port)
}

func (r *relay) isBlocked(ip netip.Addr) bool {
	until, ok := r.blocked[ip]
	if !ok {
		return false
	}
	if !until.IsZero() && time.Now().After(until) {
		delete(r.blocked, ip)
		return false
	}
	return true
}

func (r *relay) readLoop(plugin *ipc, s *session) {
	for {
		payload, err := s.conn.ReadPacket()
		if err != nil {
			if r.remove(s) {
				reason := "connection closed"
				if cause := context.Cause(s.conn.Context()); cause != nil && !errors.Is(cause, context.Canceled) {
					reason = cause.Error()
				}
				_ = plugin.write(s2pSessionClose, s.id, []byte(reason))
			}
			s.close()
			return
		}
		if err := plugin.write(s2pPacket, s.id, payload); err != nil {
			r.drop(s)
			return
		}
	}
}

func (r *relay) writeLoop(plugin *ipc, s *session) {
	for {
		select {
		case <-s.done:
			return
		case out := <-s.queue:
			if out.close {
				// go-nethernet Conn.Close stops SCTP at once and drops messages it has not sent yet,
				// such as the Disconnect packet written just before.
				time.AfterFunc(closeGracePeriod, s.close)
				return
			}
			if _, err := s.conn.Write(out.payload); err != nil {
				s.close()
				return
			}
			if out.receipt != 0 {
				_ = plugin.write(s2pReceipt, s.id, u32(out.receipt-1))
			}
		}
	}
}

func (r *relay) remove(s *session) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.sessions[s.id] != s {
		return false
	}
	delete(r.sessions, s.id)
	return true
}

func (r *relay) drop(s *session) {
	r.remove(s)
	s.close()
}

func (r *relay) dropAfterFlush(s *session) {
	r.remove(s)
	select {
	case s.queue <- outgoing{close: true}:
	default:
		s.close()
	}
}

func (r *relay) session(id uint64) *session {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.sessions[id]
}

func (r *relay) serve(ctx context.Context, plugin *ipc) {
	r.mu.Lock()
	r.plugin = plugin
	r.mu.Unlock()

	pingCtx, cancel := context.WithCancel(ctx)
	go r.reportPings(pingCtx, plugin)
	defer func() {
		cancel()
		r.mu.Lock()
		r.plugin = nil
		sessions := r.sessions
		r.sessions = map[uint64]*session{}
		r.mu.Unlock()
		for _, s := range sessions {
			s.close()
		}
	}()

	for {
		frame, err := plugin.readFrame()
		if err != nil {
			return
		}
		if len(frame) < 9 {
			r.log.Warn("short frame from plugin", "size", len(frame))
			return
		}
		if err := r.handle(plugin, frame[0], binary.BigEndian.Uint64(frame[1:9]), frame[9:]); err != nil {
			r.log.Warn("invalid frame from plugin", "type", frame[0], "error", err)
			return
		}
	}
}

func (r *relay) handle(plugin *ipc, kind byte, id uint64, body []byte) error {
	switch kind {
	case p2sSend:
		if len(body) < 4 {
			return errors.New("short send frame")
		}
		if s := r.session(id); s != nil {
			select {
			case s.queue <- outgoing{payload: body[4:], receipt: binary.BigEndian.Uint32(body[:4])}:
			default:
				r.log.Warn("send queue full, closing session", "session", id)
				r.drop(s)
			}
		}
	case p2sClose:
		if s := r.session(id); s != nil {
			r.dropAfterFlush(s)
		}
	case p2sVerifyIdentity:
		s := r.session(id)
		if s == nil {
			return nil
		}
		if err := verifyIdentity(s.conn, body); err != nil {
			return plugin.write(s2pIdentityResult, id, []byte{0}, []byte(err.Error()))
		}
		return plugin.write(s2pIdentityResult, id, []byte{1})
	case p2sPong:
		r.listener.PongData(body)
	case p2sGameJoined:
		if s := r.session(id); s != nil {
			r.provider.GameJoined(s.conn)
		}
	case p2sGameRejected:
		if s := r.session(id); s != nil {
			r.provider.GameRejected(s.conn, string(body))
		}
	case p2sBlockAddress:
		ip, rest, err := readAddr(body)
		if err != nil {
			return err
		}
		if len(rest) < 4 {
			return errors.New("short block frame")
		}
		r.block(ip, int32(binary.BigEndian.Uint32(rest)))
	case p2sUnblockAddress:
		ip, _, err := readAddr(body)
		if err != nil {
			return err
		}
		r.mu.Lock()
		delete(r.blocked, ip)
		r.mu.Unlock()
	default:
		return errors.New("unknown frame type")
	}
	return nil
}

func verifyIdentity(conn *nethernet.Conn, der []byte) error {
	key, err := x509.ParsePKIXPublicKey(der)
	if err != nil {
		return err
	}
	pub, ok := key.(*ecdsa.PublicKey)
	if !ok {
		return errors.New("identity key is not an ECDSA public key")
	}
	return conn.VerifyPublicKey(pub)
}

func readAddr(body []byte) (netip.Addr, []byte, error) {
	if len(body) < 1 || len(body) < 1+int(body[0]) {
		return netip.Addr{}, nil, errors.New("short address")
	}
	ip, err := netip.ParseAddr(string(body[1 : 1+body[0]]))
	if err != nil {
		return netip.Addr{}, nil, err
	}
	return ip.Unmap(), body[1+body[0]:], nil
}

func (r *relay) block(ip netip.Addr, timeoutSeconds int32) {
	var until time.Time
	if timeoutSeconds >= 0 {
		until = time.Now().Add(time.Duration(timeoutSeconds) * time.Second)
	}
	r.mu.Lock()
	r.blocked[ip] = until
	var victims []*session
	for _, s := range r.sessions {
		if s.addr.Addr() == ip {
			victims = append(victims, s)
		}
	}
	r.mu.Unlock()
	for _, s := range victims {
		r.drop(s)
	}
}

func (r *relay) reportPings(ctx context.Context, plugin *ipc) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		r.mu.Lock()
		sessions := make([]*session, 0, len(r.sessions))
		for _, s := range r.sessions {
			sessions = append(sessions, s)
		}
		r.mu.Unlock()
		for _, s := range sessions {
			_ = plugin.write(s2pPing, s.id, u32(uint32(s.conn.Latency().Milliseconds())))
		}
	}
}
