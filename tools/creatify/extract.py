#!/usr/bin/env python3
"""
extraer_assets_creatify_gui.py

Interfaz gráfica de escritorio para:
  1. Pegar el HTML de la página de Creatify (vista "Select videos to render").
  2. Elegir la carpeta donde se guardarán los assets.
  3. Extraer y descargar, organizados por video, los recursos REALES:
     - Imágenes de fondo
     - Audio de voz / locución
     - Música de fondo

LIMITACIÓN IMPORTANTE (léela antes de usar):
Los <video src="blob:..."> que ves en la página de Creatify NO son archivos
descargables: son referencias temporales en memoria del navegador. El video
"formateado" final (con texto animado, transiciones, música y voz ya
combinados en un solo .mp4) solo se genera del lado del servidor de
Creatify cuando presionas "Render" en su interfaz. Esta herramienta no
puede sustituir ese paso — solo recupera los materiales fuente que sí
están alojados en un CDN accesible (imágenes, audio de voz, música).

Requisitos:
    pip install beautifulsoup4 requests
    (tkinter viene incluido con Python en Windows/Mac; en Linux puede
     requerir: sudo apt install python3-tk)

Ejecutar:
    python extraer_assets_creatify_gui.py
"""

import os
import re
import json
import hashlib
import threading
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

import tkinter as tk
from tkinter import filedialog, scrolledtext, ttk, messagebox


# ---------------------------------------------------------------------------
# Lógica de extracción / descarga (misma base que la versión de consola)
# ---------------------------------------------------------------------------

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
    )
}

CARD_SELECTOR = "div.relative.cursor-pointer.hover\\:opacity-100"
REAL_ASSET_HOSTS = ("cdn.creatify.ai", "media.creatify.ai", "images.pexels.com")


def sanitize_filename(name: str, max_len: int = 60) -> str:
    name = name.strip()
    name = re.sub(r"[^\w\-. ]+", "", name, flags=re.UNICODE)
    name = re.sub(r"\s+", "_", name)
    name = name[:max_len]
    # Windows no permite que un nombre de carpeta/archivo termine en punto
    # o espacio (p. ej. un título como "...Want..." rompía os.makedirs()
    # de forma silenciosa). Lo recortamos también por si queda vacío.
    name = name.rstrip(". ")
    return name if name else "sin_titulo"


def short_hash(text: str) -> str:
    return hashlib.md5(text.encode("utf-8")).hexdigest()[:8]


def is_real_asset(url: str) -> bool:
    if not url or url.startswith("blob:") or url.startswith("data:"):
        return False
    host = urlparse(url).netloc
    return any(h in host for h in REAL_ASSET_HOSTS)


def filename_from_url(url: str) -> str:
    path = urlparse(url).path
    base = os.path.basename(path) or f"asset_{short_hash(url)}"
    if len(base) > 80:
        ext = os.path.splitext(base)[1] or ""
        base = f"asset_{short_hash(url)}{ext}"
    # Evita caracteres inválidos en Windows (: * ? " < > |) y nombres que
    # terminen en punto/espacio, que provocan errores silenciosos al escribir.
    base = re.sub(r'[:*?"<>|\\]', "_", base)
    base = base.rstrip(". ")
    return base if base else f"asset_{short_hash(url)}"


def extract_video_cards(soup: BeautifulSoup):
    cards = soup.select(CARD_SELECTOR)
    results = []

    for card in cards:
        title_span = card.select_one("span.truncate")
        title = title_span.get_text(strip=True) if title_span else None
        if not title:
            continue

        images, voice_audio, music_audio = [], [], []
        blob_video = None

        for img in card.find_all("img"):
            src = img.get("src", "")
            if is_real_asset(src):
                images.append(src)

        for audio in card.find_all("audio"):
            src = audio.get("src", "")
            if not is_real_asset(src):
                continue
            if "/assets/music/" in src:
                music_audio.append(src)
            else:
                voice_audio.append(src)

        video_tag = card.find("video")
        if video_tag:
            vsrc = video_tag.get("src", "")
            if vsrc.startswith("blob:"):
                blob_video = vsrc
            poster = video_tag.get("poster", "")
            if is_real_asset(poster):
                images.append(poster)

        results.append({
            "title": title,
            "images": sorted(set(images)),
            "voice_audio": sorted(set(voice_audio)),
            "music_audio": sorted(set(music_audio)),
            "blob_video": blob_video,
        })

    return results


def download_file(url: str, dest_path: str, session: requests.Session) -> tuple:
    """Devuelve (ok: bool, mensaje: str). Nunca lanza una excepción hacia
    afuera: cualquier error de red, de archivo, etc. queda capturado aquí
    para que el hilo de descarga jamás muera en silencio."""
    if os.path.exists(dest_path):
        return True, "ya existía"
    try:
        # timeout=(conexión, lectura) — evita que una descarga se quede
        # colgada indefinidamente si el servidor no responde o corta el
        # flujo a la mitad.
        with session.get(url, headers=HEADERS, stream=True, timeout=(10, 30)) as r:
            r.raise_for_status()
            os.makedirs(os.path.dirname(dest_path), exist_ok=True)
            with open(dest_path, "wb") as f:
                for chunk in r.iter_content(chunk_size=65536):
                    if chunk:
                        f.write(chunk)
        return True, "ok"
    except requests.exceptions.Timeout:
        return False, "tiempo de espera agotado (posible bloqueo de red/CDN)"
    except requests.exceptions.RequestException as e:
        return False, f"error de red: {e}"
    except OSError as e:
        return False, f"error al escribir archivo: {e}"
    except Exception as e:  # red de seguridad: nunca dejar morir el hilo
        return False, f"error inesperado: {e}"


# ---------------------------------------------------------------------------
# Interfaz gráfica
# ---------------------------------------------------------------------------

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Extractor de assets de Creatify")
        self.geometry("820x680")
        self.minsize(700, 550)

        self.output_dir = tk.StringVar(value="")
        self.status_var = tk.StringVar(value="Listo.")
        self.source_var = tk.StringVar(value="Sin HTML cargado.")

        # Contenido HTML real, guardado en memoria (NO en el widget de texto).
        # Esto es clave para evitar que la interfaz se congele: nunca insertamos
        # el HTML completo en un Text widget con ajuste de línea ("word wrap"),
        # que es lento/costoso cuando hay líneas de miles de caracteres
        # (típico en <script>/<style> minificados).
        self.html_content = ""
        self._stop_requested = False

        self._build_ui()

    # ---- UI ----
    def _build_ui(self):
        pad = {"padx": 10, "pady": 6}

        # Aviso
        notice = (
            "Esta herramienta descarga imágenes, audio de voz y música REALES "
            "(alojados en el CDN de Creatify). No puede generar el .mp4 final "
            "renderizado — eso solo lo hace el servidor de Creatify al presionar "
            "'Render' en su interfaz."
        )
        tk.Label(self, text=notice, wraplength=780, justify="left",
                 fg="#8a5300", font=("Segoe UI", 9, "italic")).pack(fill="x", **pad)

        # --- Carga del HTML (sin trabar la interfaz) ---
        tk.Label(self, text="1) Carga el HTML de la página de Creatify:",
                 font=("Segoe UI", 10, "bold")).pack(anchor="w", **pad)

        frame_load = tk.Frame(self)
        frame_load.pack(fill="x", padx=10)
        tk.Button(frame_load, text="📋 Pegar desde portapapeles",
                  command=self._paste_from_clipboard).pack(side="left", padx=(0, 8))
        tk.Button(frame_load, text="📂 Cargar archivo HTML…",
                  command=self._load_from_file).pack(side="left")
        tk.Button(frame_load, text="🗑 Limpiar",
                  command=self._clear_html).pack(side="left", padx=8)
        tk.Label(frame_load, textvariable=self.source_var,
                 fg="#555").pack(side="left", padx=10)

        # Vista previa de solo lectura (truncada, no afecta el procesamiento)
        tk.Label(self, text="Vista previa (solo referencia, no editable):",
                 font=("Segoe UI", 9)).pack(anchor="w", padx=10, pady=(6, 0))
        preview_frame = tk.Frame(self)
        preview_frame.pack(fill="both", expand=False, padx=10)
        self.html_preview = tk.Text(
            preview_frame, height=8, wrap="none", state="disabled",
            bg="#f4f4f4", fg="#333"
        )
        vscroll = tk.Scrollbar(preview_frame, orient="vertical",
                                command=self.html_preview.yview)
        hscroll = tk.Scrollbar(preview_frame, orient="horizontal",
                                command=self.html_preview.xview)
        self.html_preview.configure(yscrollcommand=vscroll.set,
                                     xscrollcommand=hscroll.set)
        self.html_preview.grid(row=0, column=0, sticky="nsew")
        vscroll.grid(row=0, column=1, sticky="ns")
        hscroll.grid(row=1, column=0, sticky="ew")
        preview_frame.grid_rowconfigure(0, weight=1)
        preview_frame.grid_columnconfigure(0, weight=1)

        # Selección de carpeta
        frame_dir = tk.Frame(self)
        frame_dir.pack(fill="x", **pad)
        tk.Label(frame_dir, text="2) Carpeta de destino:",
                 font=("Segoe UI", 10, "bold")).pack(side="left")
        tk.Entry(frame_dir, textvariable=self.output_dir).pack(
            side="left", fill="x", expand=True, padx=8)
        tk.Button(frame_dir, text="Elegir carpeta…",
                  command=self._choose_dir).pack(side="left")

        # Botón de acción
        frame_btn = tk.Frame(self)
        frame_btn.pack(fill="x", **pad)
        self.run_btn = tk.Button(
            frame_btn, text="3) Extraer y descargar assets",
            font=("Segoe UI", 10, "bold"), bg="#5C54FF", fg="white",
            command=self._start_extraction
        )
        self.run_btn.pack(side="left")

        self.stop_btn = tk.Button(
            frame_btn, text="Detener", fg="white", bg="#b03030",
            command=self._request_stop, state="disabled"
        )
        self.stop_btn.pack(side="left", padx=6)

        self.progress = ttk.Progressbar(frame_btn, mode="determinate")
        self.progress.pack(side="left", fill="x", expand=True, padx=10)

        # Log de salida
        tk.Label(self, text="Registro:", font=("Segoe UI", 10, "bold")).pack(
            anchor="w", **pad)
        self.log_text = scrolledtext.ScrolledText(self, height=12, wrap="word",
                                                    state="disabled", bg="#111214",
                                                    fg="#e6e6e6")
        self.log_text.pack(fill="both", expand=True, padx=10, pady=(0, 10))

        # Barra de estado
        tk.Label(self, textvariable=self.status_var, anchor="w",
                 relief="sunken").pack(fill="x", side="bottom")

    def _choose_dir(self):
        path = filedialog.askdirectory(title="Elige la carpeta de destino")
        if path:
            self.output_dir.set(path)

    # ---- Carga de HTML sin congelar la UI ----
    PREVIEW_LIMIT = 5000  # caracteres mostrados en la vista previa

    def _set_html_content(self, text: str, source_label: str):
        """Guarda el HTML completo en memoria y muestra solo un fragmento
        truncado en el widget de vista previa (rápido, sin word-wrap)."""
        self.html_content = text
        preview = text[: self.PREVIEW_LIMIT]
        if len(text) > self.PREVIEW_LIMIT:
            preview += (
                f"\n\n... [truncado en la vista previa — el archivo completo "
                f"tiene {len(text):,} caracteres y SÍ se usará entero al "
                f"procesar] ..."
            )
        self.html_preview.configure(state="normal")
        self.html_preview.delete("1.0", "end")
        self.html_preview.insert("1.0", preview)
        self.html_preview.configure(state="disabled")
        self.source_var.set(f"{source_label} — {len(text):,} caracteres")

    def _paste_from_clipboard(self):
        try:
            text = self.clipboard_get()
        except tk.TclError:
            messagebox.showwarning(
                "Portapapeles vacío",
                "No se encontró texto en el portapapeles. Copia el HTML "
                "primero (Ctrl+C) y vuelve a intentar."
            )
            return
        if not text.strip():
            messagebox.showwarning("Portapapeles vacío", "El portapapeles está vacío.")
            return
        self._set_html_content(text, "Pegado desde portapapeles")

    def _load_from_file(self):
        path = filedialog.askopenfilename(
            title="Selecciona el archivo HTML",
            filetypes=[("Archivos HTML", "*.html *.htm"), ("Todos los archivos", "*.*")],
        )
        if not path:
            return
        try:
            with open(path, "r", encoding="utf-8", errors="ignore") as f:
                text = f.read()
        except OSError as e:
            messagebox.showerror("Error al abrir archivo", str(e))
            return
        self._set_html_content(text, f"Archivo: {os.path.basename(path)}")

    def _clear_html(self):
        self.html_content = ""
        self.html_preview.configure(state="normal")
        self.html_preview.delete("1.0", "end")
        self.html_preview.configure(state="disabled")
        self.source_var.set("Sin HTML cargado.")

    def _log(self, msg: str):
        """Thread-safe: siempre se agenda en el hilo principal de Tkinter
        con `after()`, sin importar desde qué hilo se llame. Tkinter/Tcl no
        es seguro para llamarse directamente desde hilos secundarios; eso
        podía causar que la interfaz se congelara sin avisar."""
        def _do():
            self.log_text.configure(state="normal")
            self.log_text.insert("end", msg + "\n")
            self.log_text.see("end")
            self.log_text.configure(state="disabled")
        self.after(0, _do)

    def _set_progress(self, value=None, maximum=None):
        def _do():
            if maximum is not None:
                self.progress["maximum"] = maximum
            if value is not None:
                self.progress["value"] = value
        self.after(0, _do)

    def _set_status(self, text: str):
        self.after(0, lambda: self.status_var.set(text))

    def _set_running_state(self, running: bool):
        def _do():
            self.run_btn.config(state="disabled" if running else "normal")
            self.stop_btn.config(state="normal" if running else "disabled")
        self.after(0, _do)

    def _request_stop(self):
        self._stop_requested = True
        self._log("\n[Detener solicitado] Se cancelará después del archivo actual…")

    # ---- Lógica principal ----
    def _start_extraction(self):
        html = self.html_content.strip()
        out_dir = self.output_dir.get().strip()

        if not html:
            messagebox.showwarning(
                "Falta HTML",
                "Carga el HTML primero usando 'Pegar desde portapapeles' "
                "o 'Cargar archivo HTML…'."
            )
            return
        if not out_dir:
            messagebox.showwarning("Falta carpeta", "Elige una carpeta de destino.")
            return

        self._stop_requested = False
        self._set_running_state(True)
        self.log_text.configure(state="normal")
        self.log_text.delete("1.0", "end")
        self.log_text.configure(state="disabled")
        self._set_status("Analizando HTML…")

        # Ejecutar en un hilo aparte para no congelar la interfaz mientras
        # se hace el parsing (que en HTML muy grandes puede tardar varios
        # segundos) y las descargas.
        thread = threading.Thread(target=self._run_extraction_safe,
                                   args=(html, out_dir), daemon=True)
        thread.start()

    def _run_extraction_safe(self, html: str, out_dir: str):
        """Envoltorio que garantiza que el hilo NUNCA muera en silencio.
        Cualquier excepción no prevista se muestra en el log y se
        reactivan los botones, en vez de dejar la interfaz "trabada"
        para siempre sin explicación."""
        try:
            self._run_extraction(html, out_dir)
        except Exception as e:
            import traceback
            self._log(f"\n[ERROR INESPERADO] {e}")
            self._log(traceback.format_exc())
            self._set_status("Ocurrió un error. Revisa el registro.")
        finally:
            self._set_running_state(False)

    def _run_extraction(self, html: str, out_dir: str):
        try:
            soup = BeautifulSoup(html, "html.parser")
            cards = extract_video_cards(soup)
        except Exception as e:
            self._log(f"[ERROR] No se pudo analizar el HTML: {e}")
            self._set_status("Error al analizar el HTML.")
            return

        if not cards:
            self._log("No se detectaron tarjetas de video con título en el HTML cargado.")
            self._set_status("Sin resultados.")
            return

        self._log(f"Se detectaron {len(cards)} video(s). Iniciando descarga…\n")
        os.makedirs(out_dir, exist_ok=True)

        total_assets = sum(
            len(c["images"]) + len(c["voice_audio"]) + len(c["music_audio"])
            for c in cards
        )
        self._set_progress(value=0, maximum=max(total_assets, 1))
        done = 0

        session = requests.Session()
        summary = []

        for i, card in enumerate(cards, start=1):
            if self._stop_requested:
                self._log("\nProceso detenido por el usuario.")
                break

            folder_name = f"{i:02d}_{sanitize_filename(card['title'])}"
            folder_path = os.path.join(out_dir, folder_name)
            try:
                os.makedirs(folder_path, exist_ok=True)
            except OSError as e:
                self._log(f"[{i}/{len(cards)}] {card['title']} — "
                           f"[ERROR] no se pudo crear la carpeta: {e}")
                continue

            self._log(f"[{i}/{len(cards)}] {card['title']}")
            counts = {"images": 0, "voice_audio": 0, "music_audio": 0}

            for category, urls in (
                ("images", card["images"]),
                ("voice_audio", card["voice_audio"]),
                ("music_audio", card["music_audio"]),
            ):
                for url in urls:
                    if self._stop_requested:
                        break
                    fname = filename_from_url(url)
                    dest = os.path.join(folder_path, fname)
                    self._log(f"    [{category}] Descargando {fname}…")
                    ok, msg = download_file(url, dest, session)
                    status = "OK" if ok else f"FALLÓ ({msg})"
                    self._log(f"    [{category}] {fname}  -> {status}")
                    if ok:
                        counts[category] += 1
                    done += 1
                    self._set_progress(value=done)

            if card["blob_video"]:
                self._log(
                    "    [AVISO] Composición en vivo (blob:) — no descargable. "
                    "Usa 'Render' en Creatify para el .mp4 final."
                )

            summary.append({
                "titulo": card["title"],
                "carpeta": folder_path,
                **counts,
                "video_renderizable_solo_en_creatify": bool(card["blob_video"]),
            })

        summary_path = os.path.join(out_dir, "_resumen.json")
        try:
            with open(summary_path, "w", encoding="utf-8") as f:
                json.dump(summary, f, ensure_ascii=False, indent=2)
            self._log(f"\nResumen guardado en: {summary_path}")
        except OSError as e:
            self._log(f"\n[AVISO] No se pudo guardar el resumen JSON: {e}")

        if self._stop_requested:
            self._set_status(f"Detenido. {len(summary)} video(s) procesados antes de parar.")
        else:
            self._set_status(f"Completado: {len(summary)} video(s) procesados.")
            self.after(0, lambda: messagebox.showinfo(
                "Completado",
                f"Se procesaron {len(summary)} video(s).\n"
                f"Assets guardados en:\n{out_dir}"
            ))


if __name__ == "__main__":
    app = App()
    app.mainloop()