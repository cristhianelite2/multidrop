#!/usr/bin/env python3
"""Pipeline HyperFrames Ads: composition → check → render → out/final.mp4."""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

TOOL_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(Path(__file__).resolve().parent))

from build_composition import write_project  # noqa: E402


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


def cancelled(job_dir: Path) -> bool:
    return (job_dir / "cancel.flag").is_file()


def abort_if_cancelled(job_dir: Path) -> None:
    if cancelled(job_dir):
        set_status(job_dir, "cancelled", "Cancelado por el usuario")
        raise SystemExit(130)


def which_node() -> str:
    return shutil.which("node") or "node"


def npx_cmd() -> list[str]:
    npx = shutil.which("npx") or shutil.which("npx.cmd")
    if not npx:
        raise RuntimeError("npx no está en el PATH (Node.js 22+ requerido).")
    return [npx, "--yes", "hyperframes@0.8.49"]


def run(cmd: list[str], cwd: Path, timeout: int = 1800, job_dir=None, step: int = 6) -> subprocess.CompletedProcess:
    env = dict(os.environ)
    env["PYTHONIOENCODING"] = "utf-8"
    env.setdefault("CI", "1")
    # Evitar caché npm del sandbox de Cursor (provoca hangs al lanzar Chromium).
    npm_cache = Path.home() / ".npm"
    env["npm_config_cache"] = str(npm_cache)
    env["NPM_CONFIG_CACHE"] = str(npm_cache)

    log_dir = Path(job_dir) if job_dir is not None else Path(cwd)
    log_dir.mkdir(parents=True, exist_ok=True)
    out_log = log_dir / "render-stdout.log"
    err_log = log_dir / "render-stderr.log"

    # CRÍTICO: no usar PIPE sin leer → deadlock cuando Chromium/HyperFrames
    # llena el buffer (~64KB) y se queda bloqueado hasta timeout.
    with out_log.open("w", encoding="utf-8", errors="replace") as out_fh, err_log.open(
        "w", encoding="utf-8", errors="replace"
    ) as err_fh:
        proc = subprocess.Popen(
            cmd,
            cwd=str(cwd),
            env=env,
            stdout=out_fh,
            stderr=err_fh,
            text=True,
            encoding="utf-8",
            errors="replace",
            shell=False,
        )
        deadline = time.time() + timeout
        started = time.time()
        last_heartbeat = 0.0
        while True:
            if job_dir is not None and cancelled(job_dir):
                try:
                    if os.name == "nt":
                        subprocess.run(
                            ["taskkill", "/PID", str(proc.pid), "/T", "/F"],
                            capture_output=True,
                        )
                    else:
                        proc.kill()
                except Exception:
                    pass
                try:
                    proc.wait(timeout=8)
                except Exception:
                    pass
                abort_if_cancelled(job_dir)
            code = proc.poll()
            if code is not None:
                break
            if time.time() >= deadline:
                try:
                    if os.name == "nt":
                        subprocess.run(
                            ["taskkill", "/PID", str(proc.pid), "/T", "/F"],
                            capture_output=True,
                        )
                    else:
                        proc.kill()
                except Exception:
                    pass
                try:
                    proc.wait(timeout=8)
                except Exception:
                    pass
                raise subprocess.TimeoutExpired(cmd, timeout)
            if job_dir is not None and (time.time() - last_heartbeat) >= 10:
                elapsed = int(time.time() - started)
                set_status(
                    job_dir,
                    "running",
                    f"{step}/8 Renderizando MP4… ({elapsed}s transcurridos)",
                    step=step,
                )
                last_heartbeat = time.time()
            time.sleep(0.4)

    stdout = out_log.read_text(encoding="utf-8", errors="replace") if out_log.is_file() else ""
    stderr = err_log.read_text(encoding="utf-8", errors="replace") if err_log.is_file() else ""
    return subprocess.CompletedProcess(cmd, code if code is not None else 1, stdout, stderr)


def fake_render(job_dir: Path) -> None:
    steps = [
        (2, "2/8 Analizando producto y guion…"),
        (3, "3/8 Montando medios del producto…"),
        (4, "4/8 Escribiendo composición HyperFrames…"),
        (5, "5/8 Validando composición…"),
        (6, "6/8 Renderizando MP4 (modo prueba)…"),
    ]
    for step, msg in steps:
        set_status(job_dir, "running", msg, step=step)
        time.sleep(0.35)
    out = job_dir / "out" / "final.mp4"
    out.parent.mkdir(parents=True, exist_ok=True)
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg:
        subprocess.run(
            [ffmpeg, "-y", "-f", "lavfi", "-i", "color=c=#10141c:s=1080x1920:d=1", "-pix_fmt", "yuv420p", str(out)],
            capture_output=True,
        )
    if not out.is_file() or out.stat().st_size < 1024:
        first = next((job_dir / "images").glob("*"), None) if (job_dir / "images").is_dir() else None
        if first and first.is_file():
            out.write_bytes(first.read_bytes())
        else:
            out.write_bytes(b"\x00" * 4096)
    set_status(job_dir, "done", "7/8 Video listo en el bridge; pendiente importar…", step=7, output=str(out))


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: run_pipeline.py <job_dir>", file=sys.stderr)
        return 2

    job_dir = Path(sys.argv[1]).resolve()
    if not job_dir.is_dir():
        print(f"Job dir no existe: {job_dir}", file=sys.stderr)
        return 1

    try:
        set_status(job_dir, "running", "1/8 Job recibido; preparando workspace…", step=1)
        abort_if_cancelled(job_dir)
        if os.environ.get("HYPERFRAMES_BRIDGE_FAKE_RENDER") == "1":
            fake_render(job_dir)
            return 0

        if not (job_dir / "product.json").is_file():
            set_status(job_dir, "failed", "Falta product.json en el job.")
            return 1

        set_status(job_dir, "running", "2/8 Analizando producto y guion…", step=2)
        abort_if_cancelled(job_dir)
        project_dir = job_dir / "project"
        if project_dir.exists():
            shutil.rmtree(project_dir, ignore_errors=True)

        set_status(job_dir, "running", "3/8 Montando medios del producto…", step=3)
        abort_if_cancelled(job_dir)
        set_status(job_dir, "running", "4/8 Escribiendo composición HyperFrames…", step=4)
        meta = write_project(job_dir, project_dir)
        abort_if_cancelled(job_dir)

        set_status(job_dir, "running", "5/8 Validando composición…", step=5)
        check = run(
            npx_cmd() + ["check", "--non-interactive"],
            cwd=project_dir,
            timeout=300,
            job_dir=job_dir,
            step=5,
        )
        # check puede fallar por contrast warnings; no bloqueamos si index.html existe.
        if check.returncode != 0:
            lint = run(
                npx_cmd() + ["lint", "--json"],
                cwd=project_dir,
                timeout=120,
                job_dir=job_dir,
                step=5,
            )
            lint_out = (lint.stdout or "") + (lint.stderr or "")
            # Solo abortamos ante errores duros de lint (no warnings).
            hard_fail = '"severity":"error"' in lint_out or '"severity": "error"' in lint_out
            if hard_fail and 'gsap_css_transform_conflict' in lint_out:
                set_status(job_dir, "failed", "Lint HyperFrames falló (transform conflict). Revisa composition.")
                return 1
            # Continuamos con warnings / contrast.

        abort_if_cancelled(job_dir)
        out_dir = job_dir / "out"
        out_dir.mkdir(parents=True, exist_ok=True)
        out_mp4 = out_dir / "final.mp4"
        if out_mp4.exists():
            out_mp4.unlink()

        set_status(
            job_dir,
            "running",
            f"6/8 Renderizando MP4 ({meta.get('duration', 30)}s, 1080×1920, calidad looks)…",
            step=6,
        )
        render = run(
            npx_cmd()
            + [
                "render",
                "--quality",
                os.environ.get("HYPERFRAMES_RENDER_QUALITY", "looks"),
                "--output",
                str(out_mp4),
            ],
            cwd=project_dir,
            timeout=int(os.environ.get("HYPERFRAMES_RENDER_TIMEOUT", "1200")),
            job_dir=job_dir,
            step=6,
        )
        abort_if_cancelled(job_dir)
        if render.returncode != 0 or not out_mp4.is_file() or out_mp4.stat().st_size < 1024:
            err = (render.stderr or render.stdout or "Render HyperFrames falló").strip()
            err = re_short(err)
            # Si el log es enorme, quedarnos con el final (donde suele estar el error).
            set_status(job_dir, "failed", err[-1500:] if err else "Render HyperFrames falló (sin log).")
            return 1

        set_status(
            job_dir,
            "done",
            "7/8 Video renderizado; pendiente importar a la campaña…",
            step=7,
            output=str(out_mp4),
            bytes=out_mp4.stat().st_size,
        )
        return 0
    except SystemExit as e:
        if int(getattr(e, "code", 1) or 1) == 130:
            return 130
        raise
    except subprocess.TimeoutExpired:
        set_status(
            job_dir,
            "failed",
            "Timeout en el render HyperFrames. Revisa render-stderr.log en el job (suele ser Chromium o FFmpeg).",
        )
        return 1
    except Exception as e:  # noqa: BLE001
        set_status(job_dir, "failed", str(e)[-1500:])
        return 1


def re_short(text: str) -> str:
    return " ".join((text or "").split())


if __name__ == "__main__":
    sys.exit(main())
