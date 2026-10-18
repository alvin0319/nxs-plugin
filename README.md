# NXS

PocketMine-MP plugin that accepts players through [NetherNet External Signaling](https://github.com/CloudburstMC/Network/blob/nethernet/docs/external-signaling/README.md) (NXS). A Go bridge built on [go-nxs](https://github.com/df-mc/go-nxs) registers with the NXS provider and terminates the players' WebRTC connections. The plugin starts the bridge, talks to it over a Unix socket and relays game packets into PocketMine-MP sessions.

Windows requires at least Windows 10 1803 or Windows Server 2019.

## Setup

1. Download `NXS.phar` from a release, or from the `nightly` pre-release for the latest `main`, and put it in `plugins/`.
2. Set `provider-origin`, and `token` if the provider requires one, in `plugin_data/NXS/config.yml`.
3. Open the UDP `port` from `config.yml`. When using NAT, port forwarding, or Docker, list the public address in `endpoints`.
4. Register `nxs` transport in `pocketmine.yml`'s `transport.name` section.

On other systems, build the bridge yourself and set `bridge-binary` in `config.yml`:

```bash
cd bridge && CGO_ENABLED=0 go build -o nxs-bridge .
```

The bridge keeps its registration and secret keys in `plugin_data/NXS/state`. Do not share that directory between servers.
