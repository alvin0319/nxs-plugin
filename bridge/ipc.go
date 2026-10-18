package main

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"sync"
)

const maxFrameSize = 8 << 20

const (
	s2pSessionOpen byte = iota
	s2pPacket
	s2pSessionClose
	s2pIdentityResult
	s2pPing
	s2pReceipt
)

const (
	p2sSend byte = iota
	p2sClose
	p2sVerifyIdentity
	p2sPong
	p2sGameJoined
	p2sGameRejected
	p2sBlockAddress
	p2sUnblockAddress
)

type ipc struct {
	conn net.Conn
	r    *bufio.Reader
	mu   sync.Mutex
	w    *bufio.Writer
}

func newIPC(conn net.Conn) *ipc {
	return &ipc{conn: conn, r: bufio.NewReaderSize(conn, 1<<16), w: bufio.NewWriterSize(conn, 1<<16)}
}

func (c *ipc) readFrame() ([]byte, error) {
	var header [4]byte
	if _, err := io.ReadFull(c.r, header[:]); err != nil {
		return nil, err
	}
	size := binary.BigEndian.Uint32(header[:])
	if size == 0 || size > maxFrameSize {
		return nil, fmt.Errorf("invalid frame size %d", size)
	}
	frame := make([]byte, size)
	if _, err := io.ReadFull(c.r, frame); err != nil {
		return nil, err
	}
	return frame, nil
}

func (c *ipc) write(kind byte, session uint64, payload ...[]byte) error {
	size := 1 + 8
	for _, p := range payload {
		size += len(p)
	}
	if size > maxFrameSize {
		return errors.New("frame too large")
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	var header [13]byte
	binary.BigEndian.PutUint32(header[:4], uint32(size))
	header[4] = kind
	binary.BigEndian.PutUint64(header[5:], session)
	if _, err := c.w.Write(header[:]); err != nil {
		return err
	}
	for _, p := range payload {
		if _, err := c.w.Write(p); err != nil {
			return err
		}
	}
	return c.w.Flush()
}

func u16(v uint16) []byte {
	return binary.BigEndian.AppendUint16(nil, v)
}

func u32(v uint32) []byte {
	return binary.BigEndian.AppendUint32(nil, v)
}

func shortString(s string) []byte {
	if len(s) > 255 {
		s = s[:255]
	}
	return append([]byte{byte(len(s))}, s...)
}
