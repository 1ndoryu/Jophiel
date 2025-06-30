import os
import pyperclip
import sys
import argparse

# --- CONFIGURACIÓN ---
# 1. La carpeta raíz que quieres copiar.
CARPETA_RAIZ = r"C:\Users\1u\Documents\Jophiel"

# 2. (OPCIONAL) El archivo que quieres que aparezca al principio de todo.
#    Debe ser la ruta relativa desde la CARPETA_RAIZ. Ej: "index.php" o "src/main.js"
#    Si no quieres usar esta opción, déjalo como una cadena vacía: ""
ARCHIVO_PRIORITARIO = "status.md"

# 3. Lista de carpetas que quieres ignorar.
IGNORAR_CARPETAS = [
    # Carpetas de dependencias, caché y configuración de entorno
    ".git",
    "node_modules",
    "vendor",
    "cache",
    ".vscode",
    "__pycache__",
    "runtime",
    "tests",
    "runtime",

]

# Archivos específicos a ignorar
IGNORAR_ARCHIVOS = [
    # Archivos de configuración de dependencias y proyecto
    "composer.lock",
    "package.json",
    "package-lock.json",
    ".gitignore",
    "LICENSE",
    "README.md",
    "Dockerfile",
    "docker-compose.yml",
]
# 4. Lista de extensiones de archivo que quieres ignorar.
IGNORAR_EXTENSIONES = [".log", ".lock", ".bak", ".tmp", ".dll", ".exe", ".jpg", ".png", ".gif", ".ico", ".css", "webp", "mp3", "wav"]

# --- FIN DE LA CONFIGURACIÓN ---


def generar_guia_de_uso(modo_actual=None, comando_utilizado=""):
    """Genera una explicación de los modos de uso del script."""
    guia = ("-"*60) + "\n"
    
    # <--- MEJORA: Se añade el comando utilizado al informe.
    if comando_utilizado:
        guia += f"COMANDO UTILIZADO:\n{comando_utilizado}\n"
        guia += ("-"*60) + "\n\n"

    return guia


def copiar_codigo(args):
    """
    Recorre un directorio, recolecta la estructura y el content de los archivos
    según el modo de operación especificado, y lo copia al portapapeles.
    """
    try:
        modo = "default"
        if args.modo_precision:
            modo = "precision"
        elif args.modo_seleccion:
            modo = "seleccion"
        elif args.modo_busqueda:
            modo = "busqueda"

        # <--- MEJORA: Captura el comando completo utilizado para incluirlo en el reporte.
        comando_utilizado = f"python {' '.join(sys.argv)}"
        texto_guia = generar_guia_de_uso(modo, comando_utilizado)
        
        partes_estructura = []
        partes_content = []
        content_prioritario = ""
        prioritario_encontrado = False
        archivos_incluidos_contador = 0

        # <--- MEJORA: Sets para gestionar y verificar los archivos en modo selección.
        archivos_solicitados_norm = set()
        archivos_encontrados_seleccion = set()
        if modo == "seleccion":
            # Normaliza las rutas solicitadas por el usuario para una comparación fiable
            archivos_solicitados_norm = {os.path.normpath(p.strip()) for p in args.modo_seleccion.split(',')}

        ignorar_carpetas_norm = {os.path.normpath(p) for p in IGNORAR_CARPETAS}
        ignorar_archivos_norm = {os.path.normpath(p) for p in IGNORAR_ARCHIVOS}

        for dirpath, dirnames, filenames in os.walk(CARPETA_RAIZ):
            
            dirnames[:] = [
                d for d in dirnames 
                if d not in ignorar_carpetas_norm and 
                os.path.normpath(os.path.relpath(os.path.join(dirpath, d), CARPETA_RAIZ)) not in ignorar_carpetas_norm
            ]

            profundidad = dirpath.replace(CARPETA_RAIZ, "").count(os.sep)
            indentacion = "    " * profundidad
            
            nombre_carpeta = os.path.basename(dirpath) if profundidad > 0 else os.path.basename(CARPETA_RAIZ)
            if profundidad == 0:
                partes_estructura.append(f"{nombre_carpeta}\n")
            else:
                partes_estructura.append(f"{indentacion}|-- {nombre_carpeta}\n")

            for filename in sorted(filenames):
                ruta_completa = os.path.join(dirpath, filename)
                ruta_relativa = os.path.relpath(ruta_completa, CARPETA_RAIZ)
                ruta_relativa_norm = os.path.normpath(ruta_relativa)

                partes_estructura.append(f"{indentacion}    |-- {filename}\n")

                if ARCHIVO_PRIORITARIO and ruta_relativa_norm == os.path.normpath(ARCHIVO_PRIORITARIO):
                    try:
                        with open(ruta_completa, "r", encoding="utf-8", errors="ignore") as f_prio:
                            content_prioritario = (
                                f"--- ARCHIVO PRIORITARIO: {ruta_relativa} ---\n"
                                f"--------------------------------------------------\n\n"
                                f"{f_prio.read()}\n\n"
                            )
                        prioritario_encontrado = True
                        if modo != "precision": archivos_incluidos_contador += 1
                    except Exception as e:
                        content_prioritario = f"*** No se pudo leer el archivo prioritario '{ruta_relativa}': {e} ***\n\n"
                    continue 

                if os.path.basename(sys.argv[0]) == filename: continue

                if filename in IGNORAR_ARCHIVOS or ruta_relativa_norm in ignorar_archivos_norm:
                    continue
                
                if any(filename.endswith(ext) for ext in IGNORAR_EXTENSIONES): continue
                
                if modo == "precision":
                    continue

                content_para_añadir = None

                if modo == "default":
                    try:
                        with open(ruta_completa, "r", encoding="utf-8", errors="ignore") as f:
                            content_para_añadir = f.read()
                    except Exception as e:
                        content_para_añadir = f"*** No se pudo leer el archivo: {e} ***\n"
                
                # <--- MEJORA: La lógica de selección ahora busca rutas exactas.
                elif modo == "seleccion":
                    if ruta_relativa_norm in archivos_solicitados_norm:
                        try:
                            with open(ruta_completa, "r", encoding="utf-8", errors="ignore") as f:
                                content_para_añadir = f.read()
                                # Registra que este archivo solicitado fue encontrado.
                                archivos_encontrados_seleccion.add(ruta_relativa_norm)
                        except Exception as e:
                            content_para_añadir = f"*** No se pudo leer el archivo: {e} ***\n"
                
                elif modo == "busqueda":
                    try:
                        with open(ruta_completa, "r", encoding="utf-8", errors="ignore") as f:
                            content_leido = f.read()
                        if args.modo_busqueda.lower() in content_leido.lower():
                            content_para_añadir = content_leido
                    except Exception:
                        pass

                if content_para_añadir is not None:
                    partes_content.append(f"\n\n--- ARCHIVO: {ruta_relativa} ---\n\n")
                    partes_content.append(content_para_añadir)
                    archivos_incluidos_contador += 1

        texto_inicial_formateado = ""
        if args.texto_inicial:
            texto_inicial_formateado = (
                f"\nPROMPT INICIAL\n"
                f"========================\n"
                f"{args.texto_inicial}\n\n\n"
            )

        texto_final = (
            texto_guia
            + texto_inicial_formateado
            + content_prioritario
            + "ESTRUCTURA DE CARPETAS\n========================\n"
            + "".join(partes_estructura)
            + "\n\ncontent DE ARCHIVOS\n=======================\n"
            + "".join(partes_content)
        )
        
        pyperclip.copy(texto_final)
        
        if modo == 'precision' and prioritario_encontrado:
                archivos_incluidos_contador = 1
        elif modo == 'precision':
                archivos_incluidos_contador = 0

        print("\n¡Éxito! El contexto del proyecto ha sido copiado al portapapeles.")
        print(f" -> Se incluyó el content de {archivos_incluidos_contador} archivo(s).")
        print(f" -> Se copiaron un total de {len(texto_final):,} caracteres.")
        print(" -> Modo de operación y guía de uso incluidos en el texto copiado.")
        print("\nAhora puedes pegarlo donde lo necesites (Ctrl + V).")
        
        if ARCHIVO_PRIORITARIO and not prioritario_encontrado:
            print(f"\nADVERTENCIA: No se encontró el archivo prioritario especificado: '{ARCHIVO_PRIORITARIO}'")
        
        # <--- MEJORA: Informe final sobre los archivos encontrados en modo selección.
        if modo == "seleccion":
            archivos_faltantes = archivos_solicitados_norm - archivos_encontrados_seleccion
            print(f"\n--- Reporte de --modo-seleccion ---")
            print(f" -> Se encontraron y copiaron {len(archivos_encontrados_seleccion)} de {len(archivos_solicitados_norm)} archivos solicitados.")
            if archivos_faltantes:
                print("\nADVERTENCIA: Los siguientes archivos solicitados no fueron encontrados:")
                for archivo in sorted(list(archivos_faltantes)):
                    print(f" - {archivo}")
            print("-----------------------------------")


    except FileNotFoundError:
        print(f"Error: La carpeta '{CARPETA_RAIZ}' no fue encontrada.")
    except pyperclip.PyperclipException as e:
        print(f"Error al copiar al portapapeles: {e}")
    except Exception as e:
        print(f"Ocurrió un error inesperado: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Copia la estructura y content de un proyecto al portapapeles con varios modos de filtrado.",
        formatter_class=argparse.RawTextHelpFormatter 
    )

    parser.add_argument(
        "texto_inicial",
        type=str,
        nargs='?',
        default=None,
        help="Texto opcional para incluir al principio de la salida del portapapeles."
    )
    
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--modo-precision", action="store_true", help="Copia solo la estructura y el archivo prioritario.")
    group.add_argument("--modo-seleccion", type=str, help="Copia archivos cuyas rutas relativas exactas coincidan (separadas por coma).")
    group.add_argument("--modo-busqueda", type=str, help="Copia archivos cuyo content contenga el texto dado.")
    
    args = parser.parse_args()
    copiar_codigo(args)