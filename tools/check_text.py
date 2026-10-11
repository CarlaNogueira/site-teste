#!/usr/bin/env python3
"""
Verificador de texto dos ficheiros do site.

Deteta três classes de defeito que surgiram durante a escrita dos ficheiros:

  1. Caracteres fora do conjunto esperado (alfabeto português, pontuação
     comum e um punhado de símbolos usados no CSS e nos ícones).
  2. Palavras coladas por perda de espaço (ex.: "comadultos").
  3. Palavras estranhadas com prefixos ou sufixos colados
     (ex.: "degar冲 nos").

Uso:  python3 tools/check_text.py
Sai com código 1 se encontrar problemas.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

ARQUIVOS = [
    "index.html",
    "sobre.html",
    "servicos.html",
    "contactos.html",
    "robots.txt",
    "sitemap.xml",
    "llms.txt",
    "README.md",
    "assets/css/main.css",
    "assets/js/main.js",
]

# Caracteres não-ASCII legítimos neste projecto
PERMITIDOS = set(
    "áàâãéêíóôõúüçÁÀÂÃÉÊÍÓÔÕÚÜÇºª—–·“”‘’€…♪→©−≈"
    "🎸🎹🎤×↔↑↓–■"
)

# ColagensSem espaço detectadas durante a escrita
PADROES_COLADOS = [
    r"\bcomadultos\b",
    r"\btrêsinstrumentos\b",
    r"\bparchildren\b",
    r"\bdeschools\b",
    r"\baging\s+players\b",
    r"\bqueJp\b",
    r"\bteachsem\b",
    r"\bSimply\s+um\b",
r"\boutrasoghals\b",
    r"\bsãoidl\b",
    r"\bou'amadores\b",
]

def main() -> int:
    problemas = 0

    for rel in ARQUIVOS:
        caminho = ROOT / rel
        if not caminho.exists():
            continue

        texto = caminho.read_text(encoding="utf-8")

        for num, linha in enumerate(texto.splitlines(), 1):
            # 1. Caracteres inesperados
            inesperados = {c for c in linha if ord(c) > 127} - PERMITIDOS
            if inesperados:
                problemas += 1
                marcas = " ".join(f"{c} (U+{ord(c):04X})" for c in sorted(inesperados))
                print(f"[caractere] {rel}:{num}: {marcas}")
                print(f"             {linha.strip()[:120]}")

            # 2. e 3. Colagens de palavras
            for padrao in PADROES_COLADOS:
                for achado in re.finditer(padrao, linha):
                    problemas += 1
                    inicio = max(0, achado.start() - 40)
                    fim = min(len(linha), achado.end() + 40)
                    print(f"[colagem]  {rel}:{num}: {achado.group()!r}")
                    print(f"             ...{linha[inicio:fim]}...")


    if problemas:
        print(f"\n{problemas} problema(s) encontrado(s).")
        return 1

    print("Texto limpo em todos os ficheiros verificados.")
    return 0


if __name__ == "__main__":
    sys.exit(main())