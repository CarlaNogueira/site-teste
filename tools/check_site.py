#!/usr/bin/env python3
"""
Validação estrutural do site (sem dependências externas).

Verifica:
  1. JSON-LD de cada página: parse, @id únicos, @id referenciados existentes.
  2. Âncoras internas: cada link "página#âncora" tem destino real.
  3. Cada âncora referenciada existe na página de destino.
  4. Um único <h1> por página e sem saltos de nível de título.
  5. Ficheiros referenciados por href/src que existem no disco.
  6. robots.txt: cada agente de treino está bloqueado, cada agente de
     pesquisa está permitido.
  7. Coerência dos dados de contacto (NAP) entre JSON-LD, llms.txt e páginas.
"""

import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urldefrag

ROOT = Path(__file__).resolve().parent.parent
PAGINAS = ["index.html", "sobre.html", "servicos.html", "contactos.html"]

AGENTES_TREINO = [
    "GPTBot", "ClaudeBot", "Google-Extended", "Applebot-Extended",
    "CCBot", "Bytespider",
]
AGENTES_PESQUISA = [
    "OAI-SearchBot", "ChatGPT-User", "Claude-SearchBot", "Claude-User",
    "PerplexityBot", "Googlebot", "Bingbot",
]

def ids_referenciados(obj, declarados):
    """Devolve os @id usados como referência que não são declarados em
    `declarados`. Percorre dicionários e listas em qualquer profundidade."""
    achados = []

    def percorrer(o):
        if isinstance(o, dict):
            for k, v in o.items():
                if k == "@id" and isinstance(v, str):
                    if v not in declarados:
                        achados.append(v)
                else:
                    percorrer(v)
        elif isinstance(o, list):
            for item in o:
                percorrer(item)

    percorrer(obj)
    return achados


erros: list[str] = []
_referidos: set = set()
avisos: list[str] = []


def err(msg):
    erros.append(msg)


def aviso(msg):
    avisos.append(msg)


class Coletor(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.links = []          # (href, id_ou_None)
        self.ids = set()
        self.h_niveis = []
        self.ld_json = []        # texto de cada <script type=ld+json>
        self._dentro_ld = False
        self._buffer = []
        self.imgs_sem_alt = 0

    def handle_starttag(self, tag, attrs):
        d = dict(attrs)

        if d.get("id"):
            self.ids.add(d["id"])

        if tag == "a" and "href" in d:
            self.links.append(d["href"])

        if tag in ("link", "script", "img") and d.get("src"):
            self.links.append(d["src"])
        if tag == "link" and d.get("href"):
            self.links.append(d["href"])

        if tag == "img" and "alt" not in d:
            self.imgs_sem_alt += 1

        if re.fullmatch(r"h[1-6]", tag):
            self.h_niveis.append(int(tag[1]))

        if tag == "script" and d.get("type") == "application/ld+json":
            self._dentro_ld = True
            self._buffer = []

    def handle_data(self, data):
        if self._dentro_ld:
            self._buffer.append(data)

    def handle_endtag(self, tag):
        if tag == "script" and self._dentro_ld:
            self._dentro_ld = False
            self.ld_json.append("".join(self._buffer))


def main():
    parsers = {}

    # ---------- 1. Parse + integridade estrutural ----------
    for pagina in PAGINAS:
        caminho = ROOT / pagina
        if not caminho.exists():
            err(f"{pagina}: ficheiro em falta")
            continue

        p = Coletor()
        p.feed(caminho.read_text(encoding="utf-8"))
        parsers[pagina] = p

        h1 = p.h_niveis.count(1)
        if h1 != 1:
            err(f"{pagina}: esperado exatamente 1 <h1>, encontrado {h1}")

        for a, b in zip(p.h_niveis, p.h_niveis[1:]):
            if b > a + 1:
                aviso(f"{pagina}: salto de nível de título h{a} -> h{b}")

        if p.imgs_sem_alt:
            err(f"{pagina}: {p.imgs_sem_alt} <img> sem atributo alt")

        ids = re.findall(r'\sid="([^"]+)"', caminho.read_text(encoding="utf-8"))
        dupes = {i for i in ids if ids.count(i) > 1}
        if dupes:
            err(f"{pagina}: id duplicado(s): {sorted(dupes)}")

    # ---------- 1b. Imagens declaradas em meta tags e JSON-LD ----------
    # og:image, twitter:image e as propriedades logo/image do schema.org não
    # passam pelo atributo href/src, por isso precisam da sua própria verificação.
    PREFIXO = "https://exemplo.com/"

    for pagina in PAGINAS:
        caminho = ROOT / pagina
        if not caminho.exists():
            continue
        texto = caminho.read_text(encoding="utf-8")

        candidatos = re.findall(
            r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', texto
        ) + re.findall(
            r'<meta[^>]+name="twitter:image"[^>]+content="([^"]+)"', texto
        ) + re.findall(r'"(?:logo|image)"\s*:\s*"([^"]+)"', texto)

        for url in candidatos:
            if not url.startswith(PREFIXO):
                continue
            destino = ROOT / url[len(PREFIXO):]
            if not destino.exists():
                err(f"{pagina}: imagem declarada em metadados não existe: {url}")

    og = ROOT / "assets/img/og-capa.jpg"
    if og.exists():
        # As plataformas de partilha social esperam 1200x630.
        try:
            dados = og.read_bytes()
            if len(dados) < 5000:
                aviso("og-capa.jpg é muito pequena; pode renderizar mal")
        except OSError:
            err("assets/img/og-capa.jpg não pode ser lida")
    else:
        err("assets/img/og-capa.jpg em falta (usada por og:image e twitter:image)")

    # ---------- 2. JSON-LD: @id globais e consistência de entidade ----------
    # Um @id é uma identidade persistente: pode estar declarada numa página
    # e referenciada noutra (padrão normal num site com várias páginas). O
    # que não pode acontecer é a mesma identidade ser declarada com
    # atributos contraditórios — é isso que quebra a reconhe��ão de entidade.
    definidos: dict[str, tuple[str, dict]] = {}
    documentos: dict[str, list] = {}

    for pagina, p in parsers.items():
        documentos[pagina] = []
        for i, bruto in enumerate(p.ld_json):
            try:
                doc = json.loads(bruto)
            except json.JSONDecodeError as exc:
                err(f"{pagina}: JSON-LD #{i} inválido: {exc}")
                continue

            grafo = doc.get("@graph", [doc])
            documentos[pagina].append(grafo)

            declarados = {no["@id"] for no in grafo if isinstance(no, dict) and no.get("@id")}

            for no in grafo:
                if not isinstance(no, dict) or not no.get("@id"):
                    continue
                nid = no["@id"]
                if nid in definidos:
                    outra_pag, outro_no = definidos[nid]
                    if json.dumps(outro_no, sort_keys=True) != json.dumps(no, sort_keys=True):
                        err(
                            f"@id {nid} declarado de forma diferente em "
                            f"{outra_pag} e {pagina} — quebra a consistência de entidade"
                        )
                else:
                    definidos[nid] = (pagina, no)

            # Referências não resolvidas localmente são testadas contra
            # o conjunto global de @id declarados no site.
            for no in grafo:
                for ref in ids_referenciados(no, declarados):
                    _referidos.add((pagina, i, ref))

    for pagina, i, ref in sorted(_referidos):
        if ref not in definidos:
            err(f"{pagina}: JSON-LD #{i} referencia @id inexistente no site: {ref}")

    # ---------- 3. Âncoras e ficheiros ----------
    for pagina, p in parsers.items():
        for href in p.links:
            if href.startswith(("http://", "https://", "mailto:", "tel:", "data:")):
                continue

            alvo, fragmento = urldefrag(href)

            if alvo:  # link para outro ficheiro
                destino = (ROOT / alvo).resolve()
                if not destino.exists():
                    err(f"{pagina}: link quebrado -> {href}")
                    continue
                destino_pagina = destino.name
            else:
                destino_pagina = pagina

            if fragmento and destino_pagina in parsers:
                if fragmento not in parsers[destino_pagina].ids:
                    err(f"{pagina}: âncora inexistente -> {href}")

    # ---------- 4. robots.txt ----------
    robots = (ROOT / "robots.txt").read_text(encoding="utf-8")

    def_directivas = re.split(r"^user-agent:\s*", robots, flags=re.M | re.I)[1:]
    politica = {}
    for bloco in def_directivas:
        agente = bloco.splitlines()[0].strip().lower()
        if "disallow: /" in bloco.lower():
            politica[agente] = "disallow"
        elif "allow: /" in bloco.lower():
            politica[agente] = "allow"

    for agente in AGENTES_TREINO:
        if politica.get(agente.lower()) != "disallow":
            err(f"robots.txt: agente de treino '{agente}' deveria estar bloqueado")

    for agente in AGENTES_PESQUISA:
        if politica.get(agente.lower()) != "allow":
            err(f"robots.txt: agente de pesquisa '{agente}' deveria estar permitido")

    # ---------- 5. Coerência de contactos (NAP) ----------
    llms = (ROOT / "llms.txt").read_text(encoding="utf-8")
    contactos = {
        "Lisboa": ("Rua Augusta 120", "1100-053", "+351 21 000 0111", "lisboa@harmonia.pt"),
        "Porto": ("Rua de Cedofeita 250", "4050-174", "+351 22 000 0222", "porto@harmonia.pt"),
        "Beja": ("Rua Comenda Rui Rafael 12", "7800-000", "+351 26 000 0333", "beja@harmonia.pt"),
    }

    todas_paginas = "\n".join(
        (ROOT / pag).read_text(encoding="utf-8") for pag in PAGINAS
    )

    for cidade, (rua, codigo, telefone, email) in contactos.items():
        for fonte, texto in (("llms.txt", llms), ("páginas", todas_paginas)):
            for valor, rotulo in ((rua, "morada"), (codigo, "código postal"),
                                  (telefone, "telefone"), (email, "email")):
                if valor not in texto:
                    err(f"NAP inconsistente: {cidade} {rotulo} '{valor}' ausente em {fonte}")

    # ---------- Relatório ----------
    for a in avisos:
        print(f"AVISO: {a}")
    for e in erros:
        print(f"ERRO:  {e}")

    print()
    print(f"{len(erros)} erro(s), {len(avisos)} aviso(s).")
    return 1 if erros else 0


if __name__ == "__main__":
    sys.exit(main())