# Escola de Música Harmonia

Site institucional de uma escola de música com três escolas — Lisboa, Porto e
Beja — que dá aulas de guitarra, piano e voz.

HTML, CSS e JavaScript puros. Sem dependências, sem passo de build, sem
servidor de aplicação.

## Ver localmente

Precisas de um servidor HTTP simples. **Não abras os ficheiros com
`file://`** — o JavaScript e o CSS são carregados por caminho relativo e
alguns navegadores bloqueiam-nos nesse esquema.

```sh
python3 -m http.server 8000
```

Depois abre <http://localhost:8000>.

## Estrutura

```
index.html          Início
sobre.html          Sobre nós
servicos.html       Serviços e preços
contactos.html      Contactos e moradas
robots.txt          Política de crawling
sitemap.xml          Mapa do site
llms.txt            Resumo para motores de IA
assets/css/main.css Folha de estilos (design system + componentes)
assets/js/main.js   Interface: menu, separadores, formulário
assets/img/         Favicon e logótipo
tools/              Verificadores locais
```

## Verificações

Ambas correm sem dependências externas:

```sh
python3 tools/check_text.py   # texto corrompido, palavras coladas
python3 tools/check_site.py   # JSON-LD, âncoras, robots.txt, coerência NAP
```

`check_site.py` é o mais útil depois de editar conteúdo: garante que não há
`@id` de JSON-LD duplicado com atributos contraditórios, que todas as âncoras
existem, que cada agente de treino está bloqueado em `robots.txt` e que
moradas, telefones e emails são idênticos em todas as páginas.

## Decisões de implementação

**Todo o conteúdo está no HTML.** O JavaScript apenas realça a interface —
menu, separadores de cidade e construção da mensagem de contacto. Nenhuma
página depende de script para mostrar conteúdo, o que é requisito para
indexação e para os motores de IA conseguirem extrair o texto.

**O formulário não tem backend.** Constrói uma mensagem e abre o programa de
email do utilizador, endereçada à escola escolhida. Zero configuração, zero
custo, nenhum dado pessoal atravessa um servidor.

**O mapa do Google é uma ligação, não um *embed*.** Um *iframe* carrega
cookies de terceiros e, num site com público em Portugal, obriga a gestão de
consentimento GDPR. Uma ligação para as direções resolve a mesma intenção sem
esse custo.

**Os separadores de cidade ativam por JavaScript.** O `tablist` só deixa de
estar oculto depois de o script correr; sem JavaScript, os três blocos de
contactos ficam visíveis. Os papéis `role="tab"`/`role="tabpanel"` vivem no
HTML, para que o par continue válido sem script.

## Dados fictícios

Nome, moradas, coordenadas, telefones, emails, preços, professores e
testemunhos são inventados e existem apenas para demonstração. Os domínios
usados nos `canonical`, no `sitemap.xml` e no `llms.txt` são
`https://exemplo.com`.

## Antes de publicar

1. Substituir `exemplo.com` pelo domínio real em `canonical`, `og:url`,
   `sitemap.xml`, `llms.txt` e no topo do `robots.txt`.
2. Substituir moradas, coordenadas, telefones, emails e preços.
3. Substituir `assets/img/og-capa.jpg` por fotografia real de uma aula. A
   imagem actual é um desenho de demonstração, gerado a partir de
   `tools/og-capa.html` com Chrome headless. Para a regenerar:
   ```sh
   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
     --headless --screenshot=og.png --window-size=1200,630 tools/og-capa.html
   sips -s format jpeg -s formatOptions 90 og.png --out assets/img/og-capa.jpg
   ```
4. Preencher `sameAs` com os perfis reais nas redes sociais, no nó
   `#organizacao` do `index.html` — está vazio de propósito.
5. Criar um perfil Google Business Profile para cada uma das três escolas,
   com os dados NAP idênticos aos do site.

## Notas sobre SEO e GEO

**`schema.org/MusicSchool` não existe.** A classe devolve 404, pelo que a
organização é marcada como `["EducationalOrganization", "LocalBusiness"]` —
o multi-typing que o Google aceita para(results locais.

**O FAQ rich result do Google deixou de existir a 7 de Maio de 2026.** O
`FAQPage` continua válido e é consumido pelo Bing (que alimenta a pesquisa do
ChatGPT), pelo Perplexity e por pipelines RAG, mas já não produz resultado
ricos no Google. O markup só tem valor se as perguntas e respostas estiverem
visíveis no HTML — nunca apenas no JSON-LD. É o que se fez.

**A política de `robots.txt` permite pesquisa e bloqueia treino.**
`OAI-SearchBot`, `Claude-SearchBot`, `PerplexityBot` e afins estão permitidos
para que o site possa ser citado; `GPTBot`, `ClaudeBot`, `Google-Extended`,
`CCBot` e os restantes agentes de treino estão bloqueados. Isto é um
contrato por honra: agentes como o `Perplexity-User` não são obrigados a
respeitá-lo, e scrapers sem user-agent declarado ignoram-no. Para proteger a
origem, a resposta tem de ser do servidor (rate limiting, WAF).

**`llms.txt` é uma convenção recente, ainda sem adaptador nativo garantido.**
É barato de manter e não prejudica, mas não conte com efeitos mensuráveis
enquanto o ecossistema não aossipcar em escala.