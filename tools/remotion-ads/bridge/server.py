#!/usr/bin/env python3
"""Bridge HTTP del motor Remotion Ads para uso por túnel (Cloudflare).

Expone el pipeline de tools/remotion-ads (TTS -> Whisper -> MIIA -> Remotion)
para que una app Laravel en otro servidor lo consuma sin instalarlo allí.

Protocolo (todas las rutas exigen cabecera X-Remotion-Token):
  POST /api/render?job=<uuid>&preset=<preset>   body = zip del job (enteros)
        -> 202 {ok:true} (el render corre en un hilo) o 409/400/500
  GET  /api/jobs/<id>/status   -> JSON {state,message,step,steps,...}
  GET  /api/jobs/<id>/out/final.mp4 -> 200 MP4 | 500 con error | 404
  GET  /api/health             -> {ok:true}

Solo stdlib (http.server threading + zipfile). El token/port se leen de
REMOTION_BRIDGE_PORT / REMOTION_BRIDGE_TOKEN o del archivo token.txt.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import threading
import time
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

BRIDGE_DIR = Path(__file__).resolve().parent
TOOL_ROOT = BRIDGE_DIR.parent
JOBS_DIR = TOOL_ROOT / "jobs"
LOG_FILE = BRIDGE_DIR / "server.log"

_JOB_ID_RE = re.compile(r"^[0-9A-Za-z-]{4,80}$")


def log(msg: str) -> None:
    line = f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    try:
        with LOG_FILE.open("a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except Exception:
        pass
    print(line, file=sys.stderr, flush=True)


def read_token() -> str:
    token = os.environ.get("REMOTION_BRIDGE_TOKEN", "").strip()
    if token:
        return token
    tok_file = BRIDGE_DIR / "token.txt"
    if tok_file.is_file():
        return tok_file.read_text(encoding="utf-8").strip()
    return ""


TOKEN = read_token()


def fake_render(job_id: str, job_dir: Path, preset: str) -> None:
    """Render simulado (REMOTION_BRIDGE_FAKE_RENDER=1): genera un MP4 1s y pasa por los steps."""
    steps = [
        (2, "2/6 Transcribiendo audio con Whisper…"),
        (3, "3/6 Plan de cortes con MIIA…"),
        (4, "4/6 Construyendo props de Remotion…"),
        (5, "5/6 Renderizando video con Remotion…"),
    ]
    for step, msg in steps:
        set_status(job_dir, "running", msg, step=step, steps=6)
        time.sleep(0.4)
    out = job_dir / "out" / "final.mp4"
    out.parent.mkdir(parents=True, exist_ok=True)
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg:
        subprocess.run(
            [ffmpeg, "-y", "-f", "lavfi", "-i", "color=black:s=1080x1920:d=1", "-pix_fmt", "yuv420p", str(out)],
            capture_output=True,
        )
    if not out.is_file() or out.stat().st_size < 1024:
        first_img = next(job_dir.glob("images/*"), None)
        if first_img and first_img.is_file():
            with first_img.open("rb") as src, out.open("wb") as dst:
                dst.write(src.read())
    if not out.is_file() or out.stat().st_size < 1024:
        out.write_bytes(b"\x00" * 4096)
    set_status(job_dir, "done", "5/6 Video renderizado (fake); pendiente importar…", step=5, steps=6,
               output=str(out))
    log(f"JOB {job_id}: fake render OK ({out.stat().st_size} bytes)")


def venv_python() -> str:
    for rel in (".venv/Scripts/python.exe", ".venv/bin/python"):
        cand = TOOL_ROOT / rel
        if cand.is_file():
            return str(cand)
    return sys.executable


def set_status(job_dir: Path, state: str, message: str, step=None, steps=None, **extra) -> None:
    data = {"state": state, "message": message, **extra}
    if step is not None:
        data["step"] = int(step)
    if steps is not None:
        data["steps"] = int(steps)
    job_dir.mkdir(parents=True, exist_ok=True)
    (job_dir / "status.json").write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def run_pipeline(job_id: str, job_dir: Path, preset: str) -> None:
    try:
        set_status(job_dir, "running", "1/6 Job recibido; generando voiceover…", step=1, steps=6)
        if os.environ.get("REMOTION_BRIDGE_FAKE_RENDER") == "1":
            fake_render(job_id, job_dir, preset)
            return
        py = venv_python()
        script = TOOL_ROOT / "python" / "run_pipeline.py"
        env = dict(os.environ)
        env["PYTHONIOENCODING"] = "utf-8"
        env["PYTHONUTF8"] = "1"
        proc = subprocess.run(
            [py, str(script), str(job_dir), f"--preset={preset}"],
            cwd=str(TOOL_ROOT),
            env=env,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        if proc.returncode != 0:
            err = (proc.stderr or proc.stdout or "").strip()
            if not err:
                err = "El pipeline remoto devolvió exit code %d" % proc.returncode
            log(f"JOB {job_id}: pipeline falló ({proc.returncode})")
            set_status(job_dir, "failed", err[-1500:], message=err[-1500:])
            return
        out = job_dir / "out" / "final.mp4"
        if not out.is_file() or out.stat().st_size < 1024:
            set_status(job_dir, "failed", "No se generó out/final.mp4 en el bridge.")
            return
        log(f"JOB {job_id}: render OK ({out.stat().st_size} bytes)")
    except Exception as e:  # noqa: BLE001
        log(f"JOB {job_id}: excepción en el pipeline: {e}")
        try:
            set_status(job_dir, "failed", str(e))
        except Exception:
            pass


ACTIVE_JOBS: dict[str, str] = {}
ACTIVE_LOCK = threading.Lock()


def claim_job(job_id: str) -> bool:
    with ACTIVE_LOCK:
        if ACTIVE_JOBS.get(job_id):
            return False
        ACTIVE_JOBS[job_id] = "running"
        return True


def release_job(job_id: str) -> None:
    with ACTIVE_LOCK:
        ACTIVE_JOBS.pop(job_id, None)


class BridgeHandler(BaseHTTPRequestHandler):
    server_version = "RemotionBridge/1.0"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # silenciar ruido a stderr
        return

    # ---------- helpers ----------

    def send_json(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authed(self) -> bool:
        return TOKEN == "" or self.headers.get("X-Remotion-Token") == TOKEN

    def read_body_to(self, dest: Path) -> None:
        length = self.headers.get("Content-Length")
        dest.parent.mkdir(parents=True, exist_ok=True)
        with dest.open("wb") as fh:
            if length:
                remaining = int(length)
                while remaining > 0:
                    chunk = self.rfile.read(min(1048576, remaining))
                    if not chunk:
                        break
                    fh.write(chunk)
                    remaining -= len(chunk)
            else:
                while True:
                    chunk = self.rfile.read(1048576)
                    if not chunk:
                        break
                    fh.write(chunk)

    @staticmethod
    def safe_extract(zf: zipfile.ZipFile, dest: Path) -> None:
        for info in zf.infolist():  # vs extractall: protege contra path traversal
            name = info.filename.replace("\\", "/")
            if name.startswith("/") or ".." in name.split("/"):
                raise ValueError(f"Entrada no válida en el zip: {info.filename!r}")
            target = dest / name
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(info) as src, target.open("wb") as out:
                shutil.copyfileobj(src, out)

    # ---------- rutas ----------

    def do_POST(self) -> None:
        if self.path.split("?")[0] != "/api/render":
            self.send_json(404, {"ok": False, "message": "Not found"})
            return
        if not self.authed():
            self.send_json(401, {"ok": False, "message": "Token inválido"})
            return
        from urllib.parse import parse_qs, urlparse

        query = parse_qs(urlparse(self.path).query)
        job_id = (query.get("job") or [""])[0]
        preset = (query.get("preset") or ["product_presenter"])[0]
        if not _JOB_ID_RE.fullmatch(job_id):
            self.send_json(400, {"ok": False, "message": "job_id inválido"})
            return
        if not claim_job(job_id):
            self.send_json(409, {"ok": False, "message": "El job ya está en curso"})
            return
        job_dir = JOBS_DIR / job_id
        if job_dir.exists():
            release_job(job_id)
            self.send_json(409, {"ok": False, "message": "El job ya existe"})
            return
        try:
            tmp_zip = (JOBS_DIR / f".{job_id}.upload.zip")
            self.read_body_to(tmp_zip)
            job_dir.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(tmp_zip) as zf:
                self.safe_extract(zf, job_dir)
            tmp_zip.unlink(missing_ok=True)
            log(f"JOB {job_id}: recibido ({preset}), arrancando pipeline…")
            threading.Thread(target=run_pipeline, args=(job_id, job_dir, preset), daemon=True).start()
            self.send_json(202, {"ok": True, "job_id": job_id})
        except Exception as e:  # noqa: BLE001
            log(f"JOB {job_id}: error al recibir: {e}")
            release_job(job_id)
            if job_dir.exists() and not (job_dir / "status.json").exists():
                shutil.rmtree(job_dir, ignore_errors=True)
            self.send_json(500, {"ok": False, "message": str(e)})

    def do_GET(self) -> None:
        path = self.path
        if path == "/api/health":
            self.send_json(200, {"ok": True, "token": bool(TOKEN)})
            return
        if not self.authed():
            self.send_json(401, {"ok": False, "message": "Token inválido"})
            return

        m = re.fullmatch(r"/api/jobs/([0-9A-Za-z-]{4,80})/status", path)
        if m:
            job_dir = JOBS_DIR / m.group(1)
            status_file = job_dir / "status.json"
            if status_file.is_file():
                try:
                    data = json.loads(status_file.read_text(encoding="utf-8"))
                except Exception:
                    data = {}
                if not isinstance(data, dict):
                    data = {}
                self.send_json(200, data)
                return
            if job_dir.exists():
                self.send_json(200, {"state": "running", "message": "1/6 Job recibido; arrancando…", "step": 1, "steps": 6})
                return
            self.send_json(404, {"state": "unknown", "message": "Job no existe en el bridge"})
            return

        m = re.fullmatch(r"/api/jobs/([0-9A-Za-z-]{4,80})/out/final\.mp4", path)
        if m:
            job_dir = JOBS_DIR / m.group(1)
            mp4 = job_dir / "out" / "final.mp4"
            if mp4.is_file() and mp4.stat().st_size > 0:
                size = mp4.stat().st_size
                self.send_response(200)
                self.send_header("Content-Type", "video/mp4")
                self.send_header("Content-Length", str(size))
                self.send_header("Accept-Ranges", "bytes")
                self.end_headers()
                with mp4.open("rb") as fh:
                    while True:
                        chunk = fh.read(1048576)
                        if not chunk:
                            break
                        self.wfile.write(chunk)
                return
            status_file = job_dir / "status.json"
            if status_file.is_file():
                try:
                    data = json.loads(status_file.read_text(encoding="utf-8"))
                except Exception:
                    data = {}
                if is_dict(data) and data.get("state") == "failed":
                    self.send_json(500, {"ok": False, "message": data.get("message", "Pipeline falló")})
                    return
            self.send_json(404, {"ok": False, "message": "final.mp4 aún no disponible"})
            return

        self.send_json(404, {"ok": False, "message": "Not found"})


def is_dict(data) -> bool:
    return isinstance(data, dict)


def main() -> int:
    if not TOKEN:
        log("AVISO: no se definió REMOTION_BRIDGE_TOKEN ni token.txt; el bridge queda abierto.")
    port = int(os.environ.get("REMOTION_BRIDGE_PORT", "9013"))
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    server = ThreadingHTTPServer(("127.0.0.1", port), BridgeHandler)
    server.daemon_threads = True
    log(f"Bridge Remotion Ads escuchando en http://127.0.0.1:{port} (token={'sí' if TOKEN else 'no'})")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())