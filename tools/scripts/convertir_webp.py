import os
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, scrolledtext, ttk

from PIL import Image


def buscar_webp(carpeta_raiz):
    """Busca recursivamente todos los archivos .webp dentro de la carpeta y subcarpetas."""
    encontrados = []
    for root, _dirs, files in os.walk(carpeta_raiz):
        for f in files:
            if f.lower().endswith(".webp"):
                encontrados.append(os.path.join(root, f))
    return encontrados


class App:
    def __init__(self, root):
        self.root = root
        self.root.title("Conversor WEBP → JPG")
        self.root.geometry("650x550")
        self.root.minsize(550, 450)

        self.carpeta_seleccionada = tk.StringVar(value="")
        self.eliminar_webp = tk.BooleanVar(value=False)
        self.reemplazar_existentes = tk.BooleanVar(value=False)

        self._build_ui()

    def _build_ui(self):
        pad = {"padx": 10, "pady": 5}

        # --- Selección de carpeta ---
        frame_carpeta = tk.Frame(self.root)
        frame_carpeta.pack(fill="x", **pad)
        tk.Label(frame_carpeta, text="Carpeta a procesar:").pack(side="left")
        self.entry_carpeta = tk.Entry(frame_carpeta, textvariable=self.carpeta_seleccionada)
        self.entry_carpeta.pack(side="left", fill="x", expand=True, padx=5)
        tk.Button(frame_carpeta, text="Examinar...", command=self.elegir_carpeta).pack(side="left")

        tk.Label(
            self.root,
            text="Se buscarán imágenes .webp en esta carpeta y en todas sus subcarpetas.",
            fg="#64748b", font=("Segoe UI", 8)
        ).pack(anchor="w", padx=12)

        # --- Opciones ---
        frame_opts = tk.LabelFrame(self.root, text="Opciones")
        frame_opts.pack(fill="x", padx=10, pady=10)

        tk.Checkbutton(
            frame_opts, text="Eliminar los archivos .webp originales después de convertir",
            variable=self.eliminar_webp
        ).pack(anchor="w", padx=10, pady=3)

        tk.Checkbutton(
            frame_opts, text="Reemplazar archivos .jpg existentes (si no, se omiten)",
            variable=self.reemplazar_existentes
        ).pack(anchor="w", padx=10, pady=3)

        # --- Barra de progreso ---
        self.progress = ttk.Progressbar(self.root, mode="determinate")
        self.progress.pack(fill="x", padx=10, pady=(5, 0))

        self.lbl_progreso = tk.Label(self.root, text="")
        self.lbl_progreso.pack(anchor="w", padx=10)

        # --- Log ---
        tk.Label(self.root, text="Registro:").pack(anchor="w", padx=10, pady=(5, 0))
        self.txt_log = scrolledtext.ScrolledText(self.root, height=14, state="disabled", wrap="word")
        self.txt_log.pack(fill="both", expand=True, padx=10, pady=(0, 10))

        # --- Botón ---
        self.btn_convertir = tk.Button(
            self.root, text="Convertir imágenes",
            command=self.iniciar_conversion, bg="#0ea5e9", fg="white",
            font=("Segoe UI", 10, "bold"), height=2
        )
        self.btn_convertir.pack(fill="x", padx=10, pady=(0, 10))

    def elegir_carpeta(self):
        inicial = self.carpeta_seleccionada.get() or os.path.dirname(os.path.abspath(__file__))
        carpeta = filedialog.askdirectory(initialdir=inicial)
        if carpeta:
            self.carpeta_seleccionada.set(carpeta)

    def log(self, mensaje):
        self.txt_log.config(state="normal")
        self.txt_log.insert(tk.END, mensaje + "\n")
        self.txt_log.see(tk.END)
        self.txt_log.config(state="disabled")
        self.root.update_idletasks()

    def iniciar_conversion(self):
        carpeta = self.carpeta_seleccionada.get().strip()

        if not carpeta:
            messagebox.showwarning("Falta carpeta", "Selecciona una carpeta para continuar.")
            return

        if not os.path.isdir(carpeta):
            messagebox.showerror("Ruta inválida", "La carpeta seleccionada no existe.")
            return

        self.btn_convertir.config(state="disabled", text="Convirtiendo...")
        self.progress["value"] = 0
        self.txt_log.config(state="normal")
        self.txt_log.delete("1.0", tk.END)
        self.txt_log.config(state="disabled")

        hilo = threading.Thread(target=self.procesar, args=(carpeta,), daemon=True)
        hilo.start()

    def procesar(self, carpeta):
        archivos_webp = buscar_webp(carpeta)

        if not archivos_webp:
            self.log("⚠️ No se encontraron archivos .webp en la carpeta seleccionada.")
            self._finalizar()
            return

        self.log(f"Se encontraron {len(archivos_webp)} imágenes .webp.\n")
        self.progress["maximum"] = len(archivos_webp)

        convertidas = 0
        omitidas = 0
        errores = 0
        eliminadas = 0

        for i, ruta_webp in enumerate(archivos_webp, start=1):
            nombre_base = os.path.splitext(ruta_webp)[0]
            ruta_jpg = nombre_base + ".jpg"

            self.lbl_progreso.config(text=f"{i}/{len(archivos_webp)}: {os.path.basename(ruta_webp)}")

            if os.path.exists(ruta_jpg) and not self.reemplazar_existentes.get():
                self.log(f"[{i}/{len(archivos_webp)}] ⏭️  Omitido (ya existe): {os.path.relpath(ruta_jpg, carpeta)}")
                omitidas += 1
                self.progress["value"] = i
                self.root.update_idletasks()
                continue

            try:
                with Image.open(ruta_webp) as img:
                    # JPG no soporta transparencia: convertir a fondo blanco si es necesario
                    if img.mode in ("RGBA", "LA") or (img.mode == "P" and "transparency" in img.info):
                        fondo = Image.new("RGB", img.size, (255, 255, 255))
                        img_rgba = img.convert("RGBA")
                        fondo.paste(img_rgba, mask=img_rgba.split()[-1])
                        fondo.save(ruta_jpg, "JPEG", quality=92)
                    else:
                        img.convert("RGB").save(ruta_jpg, "JPEG", quality=92)

                self.log(f"[{i}/{len(archivos_webp)}] ✅ Convertido: {os.path.relpath(ruta_jpg, carpeta)}")
                convertidas += 1

                if self.eliminar_webp.get():
                    os.remove(ruta_webp)
                    eliminadas += 1

            except Exception as e:
                self.log(f"[{i}/{len(archivos_webp)}] ❌ Error con {os.path.basename(ruta_webp)}: {e}")
                errores += 1

            self.progress["value"] = i
            self.root.update_idletasks()

        resumen = (
            f"\n--- Resumen ---\n"
            f"Convertidas: {convertidas}\n"
            f"Omitidas (ya existían): {omitidas}\n"
            f"Errores: {errores}\n"
        )
        if self.eliminar_webp.get():
            resumen += f"WEBP eliminados: {eliminadas}\n"

        self.log(resumen)
        self._finalizar()

        messagebox.showinfo(
            "Proceso completado",
            f"Convertidas: {convertidas}\nOmitidas: {omitidas}\nErrores: {errores}"
        )

    def _finalizar(self):
        self.lbl_progreso.config(text="")
        self.btn_convertir.config(state="normal", text="Convertir imágenes")


if __name__ == "__main__":
    root = tk.Tk()
    app = App(root)
    root.mainloop()