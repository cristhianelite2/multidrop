import os
import re
import zipfile
import threading
from urllib.parse import urlparse, unquote

import requests
import tkinter as tk
from tkinter import filedialog, messagebox, scrolledtext, ttk


def extraer_urls(html):
    urls = re.findall(r'src="([^"]+)"', html)
    return list(dict.fromkeys(urls))  # elimina duplicados manteniendo el orden


def nombre_archivo_desde_url(url, index):
    path = urlparse(url).path
    nombre = unquote(os.path.basename(path))
    if not nombre:
        nombre = f"imagen_{index}.webp"
    return nombre


class App:
    def __init__(self, root):
        self.root = root
        self.root.title("Descargador de imágenes HTML → ZIP")
        self.root.geometry("700x600")
        self.root.minsize(600, 500)

        # Ruta por defecto: donde está el script
        self.ruta_destino = os.path.dirname(os.path.abspath(__file__))

        self._build_ui()

    def _build_ui(self):
        pad = {"padx": 10, "pady": 5}

        # --- HTML ---
        tk.Label(self.root, text="Pega aquí el HTML:").pack(anchor="w", **pad)
        self.txt_html = scrolledtext.ScrolledText(self.root, height=15, wrap="word")
        self.txt_html.pack(fill="both", expand=True, padx=10)

        # --- Nombre del ZIP ---
        frame_zip = tk.Frame(self.root)
        frame_zip.pack(fill="x", **pad)
        tk.Label(frame_zip, text="Nombre del ZIP:").pack(side="left")
        self.entry_zip = tk.Entry(frame_zip)
        self.entry_zip.insert(0, "imagenes")
        self.entry_zip.pack(side="left", fill="x", expand=True, padx=5)
        tk.Label(frame_zip, text=".zip").pack(side="left")

        # --- Ruta destino ---
        frame_ruta = tk.Frame(self.root)
        frame_ruta.pack(fill="x", **pad)
        tk.Label(frame_ruta, text="Guardar en:").pack(side="left")
        self.entry_ruta = tk.Entry(frame_ruta)
        self.entry_ruta.insert(0, self.ruta_destino)
        self.entry_ruta.pack(side="left", fill="x", expand=True, padx=5)
        tk.Button(frame_ruta, text="Examinar...", command=self.elegir_ruta).pack(side="left")

        # --- Barra de progreso ---
        self.progress = ttk.Progressbar(self.root, mode="determinate")
        self.progress.pack(fill="x", padx=10, pady=(10, 0))

        # --- Log ---
        tk.Label(self.root, text="Registro:").pack(anchor="w", **pad)
        self.txt_log = scrolledtext.ScrolledText(self.root, height=8, state="disabled", wrap="word")
        self.txt_log.pack(fill="both", expand=True, padx=10, pady=(0, 10))

        # --- Botón ---
        self.btn_descargar = tk.Button(
            self.root, text="Descargar imágenes y crear ZIP",
            command=self.iniciar_descarga, bg="#0ea5e9", fg="white",
            font=("Segoe UI", 10, "bold"), height=2
        )
        self.btn_descargar.pack(fill="x", padx=10, pady=(0, 10))

    def elegir_ruta(self):
        carpeta = filedialog.askdirectory(initialdir=self.entry_ruta.get())
        if carpeta:
            self.entry_ruta.delete(0, tk.END)
            self.entry_ruta.insert(0, carpeta)

    def log(self, mensaje):
        self.txt_log.config(state="normal")
        self.txt_log.insert(tk.END, mensaje + "\n")
        self.txt_log.see(tk.END)
        self.txt_log.config(state="disabled")
        self.root.update_idletasks()

    def iniciar_descarga(self):
        html = self.txt_html.get("1.0", tk.END).strip()
        if not html:
            messagebox.showwarning("Falta HTML", "Pega el HTML con las imágenes antes de continuar.")
            return

        nombre_zip = self.entry_zip.get().strip() or "imagenes"
        ruta_destino = self.entry_ruta.get().strip() or self.ruta_destino

        if not os.path.isdir(ruta_destino):
            messagebox.showerror("Ruta inválida", "La ruta de destino no existe.")
            return

        self.btn_descargar.config(state="disabled", text="Descargando...")
        self.progress["value"] = 0
        self.txt_log.config(state="normal")
        self.txt_log.delete("1.0", tk.END)
        self.txt_log.config(state="disabled")

        # Ejecutar en un hilo aparte para no congelar la GUI
        hilo = threading.Thread(
            target=self.procesar, args=(html, nombre_zip, ruta_destino), daemon=True
        )
        hilo.start()

    def procesar(self, html, nombre_zip, ruta_destino):
        urls = extraer_urls(html)

        if not urls:
            self.log("⚠️ No se encontraron imágenes (atributos src=\"...\") en el HTML.")
            self._finalizar()
            return

        self.log(f"Se encontraron {len(urls)} imágenes.\n")
        self.progress["maximum"] = len(urls)

        carpeta_temp = os.path.join(ruta_destino, f"_tmp_{nombre_zip}")
        os.makedirs(carpeta_temp, exist_ok=True)

        headers = {"User-Agent": "Mozilla/5.0"}
        archivos = []

        for i, url in enumerate(urls, start=1):
            nombre = nombre_archivo_desde_url(url, i)
            ruta_archivo = os.path.join(carpeta_temp, nombre)

            base, ext = os.path.splitext(ruta_archivo)
            contador = 1
            while os.path.exists(ruta_archivo):
                ruta_archivo = f"{base}_{contador}{ext}"
                contador += 1

            try:
                self.log(f"[{i}/{len(urls)}] Descargando {os.path.basename(ruta_archivo)}...")
                resp = requests.get(url, headers=headers, timeout=30)
                resp.raise_for_status()
                with open(ruta_archivo, "wb") as f:
                    f.write(resp.content)
                archivos.append(ruta_archivo)
            except Exception as e:
                self.log(f"  ❌ Error: {e}")

            self.progress["value"] = i
            self.root.update_idletasks()

        if not archivos:
            self.log("\n⚠️ No se pudo descargar ninguna imagen.")
            self._finalizar()
            return

        zip_path = os.path.join(ruta_destino, f"{nombre_zip}.zip")
        base_zip, ext_zip = os.path.splitext(zip_path)
        contador = 1
        while os.path.exists(zip_path):
            zip_path = f"{base_zip}_{contador}{ext_zip}"
            contador += 1

        with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
            for archivo in archivos:
                zf.write(archivo, arcname=os.path.basename(archivo))

        # Limpiar carpeta temporal
        for archivo in archivos:
            try:
                os.remove(archivo)
            except OSError:
                pass
        try:
            os.rmdir(carpeta_temp)
        except OSError:
            pass

        self.log(f"\n✅ Listo. ZIP creado en:\n{zip_path}\n({len(archivos)} imágenes)")
        self._finalizar()
        messagebox.showinfo("Completado", f"Se descargaron {len(archivos)} imágenes.\n\nZIP guardado en:\n{zip_path}")

    def _finalizar(self):
        self.btn_descargar.config(state="normal", text="Descargar imágenes y crear ZIP")


if __name__ == "__main__":
    root = tk.Tk()
    app = App(root)
    root.mainloop()