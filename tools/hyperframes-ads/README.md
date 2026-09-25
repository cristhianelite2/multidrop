# HyperFrames Ads (Multidrop)

Motor local de videos de producto con [HyperFrames](https://github.com/heygen-com/hyperframes).
Multidrop (servidor) envía el job por HTTPS a este bridge vía túnel Cloudflare.

## Arranque rápido (Windows)

```bat
REM 1) Bridge HTTP en 127.0.0.1:9014
tools\hyperframes-ads\bridge\start-bridge.cmd

REM 2) Túnel público (después de configurar cloudflared-config.yml)
tools\hyperframes-ads\bridge\start-tunnel.cmd
```

Health: `https://hyperframes.ceballosleon.com/api/health`

## Crear subdominio (una vez)

```bat
cloudflared tunnel login
cloudflared tunnel create multidrop-hyperframes
cloudflared tunnel route dns multidrop-hyperframes hyperframes.ceballosleon.com
```

Copia `bridge/cloudflared-config.example.yml` → `bridge/cloudflared-config.yml` y completa el UUID.

## Token

El archivo `bridge/token.txt` (gitignored) debe coincidir con `HYPERFRAMES_ADS_TOKEN` en el `.env` del servidor Multidrop.

## Protocolo

| Método | Ruta | Notas |
|--------|------|--------|
| GET | `/api/health` | sin auth |
| POST | `/api/render?job=<uuid>` | zip del job, header `X-HyperFrames-Token` |
| GET | `/api/jobs/<id>/status` | progreso `step/steps` + `message` |
| GET | `/api/jobs/<id>/out/final.mp4` | MP4 listo |

## Requisitos locales

- Node.js 22+
- FFmpeg en PATH
- `npx hyperframes@0.8.49`
