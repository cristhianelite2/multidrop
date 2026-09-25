#!/usr/bin/env python3
"""Bridge HTTP del motor HyperFrames Ads (túnel Cloudflare → hyperframes.ceballosleon.com).

Protocolo (cabecera X-HyperFrames-Token):
  POST /api/render?job=<uuid>   body = zip del job
  GET  /api/jobs/<id>/status
  GET  /api/jobs/<id>/out/final.mp4
  GET  /api/health
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
from urllib.parse import parse_qs, urlparse

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
    token = os.environ.get("HYPERFRAMES_BRIDGE_TOKEN", "").strip()
    if token:
        return token
    tok_file = BRIDGE_DIR / "token.txt"
    if tok_file.is_file():
        return tok_file.read_text(encoding="utf-8").strip()
    return ""


TOKEN = read_token()


def set_status(job_dir: Path, state: str, message: str, step=None, steps=8, **extra) -> None:
    data = {"state": state, "message": message, **extra}
    if step is not None:
        data["step"] = int(step)
    if steps is not None:
        data["steps"] = int(steps)
    job_dir.mkdir(parents=True, exist_ok=True)
    (job_dir / "status.json").write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def run_pipeline(job_id: str, job_dir: Path) -> None:
    proc = None
    try:
        set_status(job_dir, "running", "1/8 Job recibido; arrancando HyperFrames…", step=1)
        script = TOOL_ROOT / "pipeline" / "run_pipeline.py"
        env = dict(os.environ)
        env["PYTHONIOENCODING"] = "utf-8"
        env["PYTHONUTF8"] = "1"
        proc = subprocess.Popen(
            [sys.executable, str(script), str(job_dir)],
            cwd=str(TOOL_ROOT),
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        register_proc(job_id, proc)
        stdout, stderr = proc.communicate()
        code = proc.returncode or 0
        if (job_dir / "cancel.flag").is_file():
            set_status(job_dir, "cancelled", "Cancelado por el usuario")
            log(f"JOB {job_id}: cancelado")
            return
        if code != 0:
            status_file = job_dir / "status.json"
            if status_file.is_file():
                try:
                    data = json.loads(status_file.read_text(encoding="utf-8"))
                    if isinstance(data, dict) and data.get("state") in ("failed", "cancelled"):
                        log(f"JOB {job_id}: pipeline {data.get('state')}")
                        return
                except Exception:
                    pass
            err = (stderr or stdout or f"exit {code}").strip()
            set_status(job_dir, "failed", err[-1500:])
            log(f"JOB {job_id}: falló ({code})")
            return
        out = job_dir / "out" / "final.mp4"
        if not out.is_file() or out.stat().st_size < 1024:
            set_status(job_dir, "failed", "No se generó out/final.mp4 en el bridge HyperFrames.")
            return
        log(f"JOB {job_id}: OK ({out.stat().st_size} bytes)")
    except Exception as e:  # noqa: BLE001
        log(f"JOB {job_id}: excepción: {e}")
        try:
            set_status(job_dir, "failed", str(e))
        except Exception:
            pass
    finally:
        clear_proc(job_id)
        release_job(job_id)


ACTIVE_JOBS: dict[str, str] = {}
ACTIVE_PROCS: dict[str, subprocess.Popen] = {}
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


def register_proc(job_id: str, proc: subprocess.Popen) -> None:
    with ACTIVE_LOCK:
        ACTIVE_PROCS[job_id] = proc


def clear_proc(job_id: str) -> None:
    with ACTIVE_LOCK:
        ACTIVE_PROCS.pop(job_id, None)


def cancel_job(job_id: str) -> dict:
    job_dir = JOBS_DIR / job_id
    if not job_dir.exists():
        return {"ok": False, "message": "Job no existe en el bridge"}
    (job_dir / "cancel.flag").write_text("1", encoding="utf-8")
    set_status(job_dir, "cancelled", "Cancelado por el usuario")
    with ACTIVE_LOCK:
        proc = ACTIVE_PROCS.get(job_id)
    if proc is not None and proc.poll() is None:
        try:
            if os.name == "nt":
                subprocess.run(
                    ["taskkill", "/PID", str(proc.pid), "/T", "/F"],
                    capture_output=True,
                )
            else:
                proc.kill()
        except Exception as e:  # noqa: BLE001
            log(f"JOB {job_id}: no se pudo matar proceso: {e}")
    log(f"JOB {job_id}: cancel solicitado")
    return {"ok": True, "message": "Cancelado"}


class BridgeHandler(BaseHTTPRequestHandler):
    server_version = "HyperFramesBridge/1.0"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        return

    def send_json(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authed(self) -> bool:
        return TOKEN == "" or self.headers.get("X-HyperFrames-Token") == TOKEN

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
        for info in zf.infolist():
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

    def do_POST(self) -> None:
        path = self.path.split("?")[0]
        m_cancel = re.fullmatch(r"/api/jobs/([0-9A-Za-z-]{4,80})/cancel", path)
        if m_cancel:
            if not self.authed():
                self.send_json(401, {"ok": False, "message": "Token inválido"})
                return
            # drain body if any
            length = int(self.headers.get("Content-Length") or 0)
            if length:
                self.rfile.read(length)
            self.send_json(200, cancel_job(m_cancel.group(1)))
            return

        if path != "/api/render":
            self.send_json(404, {"ok": False, "message": "Not found"})
            return
        if not self.authed():
            self.send_json(401, {"ok": False, "message": "Token inválido"})
            return
        query = parse_qs(urlparse(self.path).query)
        job_id = (query.get("job") or [""])[0]
        if not _JOB_ID_RE.fullmatch(job_id):
            self.send_json(400, {"ok": False, "message": "job_id inválido"})
            return
        if not claim_job(job_id):
            self.send_json(409, {"ok": False, "message": "El job ya está en curso"})
            return
        job_dir = JOBS_DIR / job_id
        if job_dir.exists():
            # Ya reclamamos el slot: limpiar restos de un intento previo.
            shutil.rmtree(job_dir, ignore_errors=True)
            if job_dir.exists():
                release_job(job_id)
                self.send_json(409, {"ok": False, "message": "No se pudo limpiar el job previo"})
                return
        try:
            tmp_zip = JOBS_DIR / f".{job_id}.upload.zip"
            self.read_body_to(tmp_zip)
            job_dir.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(tmp_zip) as zf:
                self.safe_extract(zf, job_dir)
            tmp_zip.unlink(missing_ok=True)
            log(f"JOB {job_id}: recibido, arrancando pipeline HyperFrames…")
            threading.Thread(target=run_pipeline, args=(job_id, job_dir), daemon=True).start()
            self.send_json(202, {"ok": True, "job_id": job_id})
        except Exception as e:  # noqa: BLE001
            log(f"JOB {job_id}: error al recibir: {e}")
            release_job(job_id)
            if job_dir.exists() and not (job_dir / "status.json").exists():
                shutil.rmtree(job_dir, ignore_errors=True)
            self.send_json(500, {"ok": False, "message": str(e)})

    def do_GET(self) -> None:
        path = self.path.split("?")[0]
        if path == "/api/health":
            self.send_json(
                200,
                {
                    "ok": True,
                    "service": "hyperframes-ads",
                    "token": bool(TOKEN),
                    "host": "hyperframes.ceballosleon.com",
                },
            )
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
                self.send_json(
                    200,
                    {"state": "running", "message": "1/8 Job recibido; arrancando…", "step": 1, "steps": 8},
                )
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
                if isinstance(data, dict) and data.get("state") == "failed":
                    self.send_json(500, {"ok": False, "message": data.get("message", "Pipeline falló")})
                    return
            self.send_json(404, {"ok": False, "message": "final.mp4 aún no disponible"})
            return

        self.send_json(404, {"ok": False, "message": "Not found"})


def main() -> int:
    if not TOKEN:
        log("AVISO: sin HYPERFRAMES_BRIDGE_TOKEN ni token.txt; bridge abierto.")
    port = int(os.environ.get("HYPERFRAMES_BRIDGE_PORT", "9014"))
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    server = ThreadingHTTPServer(("127.0.0.1", port), BridgeHandler)
    server.daemon_threads = True
    log(f"Bridge HyperFrames escuchando en http://127.0.0.1:{port} (token={'sí' if TOKEN else 'no'})")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
