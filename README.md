# Escola de Música Harmonia

Site institucional de uma escola de música com três escolas — Lisboa, Porto e
Beja — que dá aulas de guitarra, piano e voz.

HTML, CSS e JavaScript puros no site público; PHP apenas nas ferramentas
locais. Sem dependências, sem passo de build, sem servidor de aplicação.

## Ver localmente

Precisas de um servidor HTTP simples. **Não abras os ficheiros com
`file://`** — o JavaScript e o CSS são carregados por caminho relativo e
alguns navegadores bloqueiam-nos nesse esquema.

```sh
php -S localhost:8000
```

Depois abre <http://localhost:8000>.

## Estrutura

```
index.html          Início
sobre.html          Sobre nós
servicos.html       Serviços e preços
contactos.html      Contactos e moradas
privacidade.html    Política de privacidade (RGPD)
robots.txt          Política de crawling
sitemap.xml          Mapa do site
llms.txt            Resumo para motores de IA
api/pedidos.php     Recebe o pedido de aula experimental
admin/              Painel de gestão (exige login)
assets/css/main.css Folha de estilos (design system + componentes)
assets/css/admin.css Componentes do painel (tabelas, etiquetas)
assets/js/main.js   Interface: menu, separadores, formulário
assets/img/         Favicon e logótipo
db/schema.sql       Estrutura das tabelas
db/seed.sql         Escolas e instrumentos iniciais
config/             Ligação à base de dados (credenciais fora do Git)
tools/              Verificadores locais e teste de ligação (PHP)
```

## O painel

O painel vive em `/admin/` e não tem ligação nenhuma a partir do site
público — é o comportamento correcto e, por acaso, também evita que o
`check_site.php` acuse links partidos para rotas geradas em PHP.

```sh
php -S localhost:8000     # depois: http://localhost:8000/admin/
```

Cinco páginas: pedidos, alunos, aulas, professores e o resumo do painel.

**O que cada operação aplica no servidor, nunca só no formulário:**

| Operação | Regras |
|---|---|
| Converter pedido em aluno | Copia para `alunos` e marca `convertido`, numa transacção — ou as duas coisas acontecem, ou nenhuma |
| Apagar aluno | As aulas dele vão com ele (`ON DELETE CASCADE`) |
| Apagar professor com aulas | Recusado (`ON DELETE RESTRICT`); apagar deixaria aulas sem nome |
| Agendar aula | O professor tem de ser da escola do aluno, dentro do horário da escola, e sem aula sobreposta |
| Login | Limite de tentativas e mensagem igual para email certo e errado, para não revelar que contas existem |

A sobreposição de aulas não é imposta pela base de dados — depende de uma
coluna calculada (início mais duração), que uma restrição `UNIQUE` não sabe
exprimir. É verificada no PHP, o que é suficiente porque o PHP de um servidor
normal é de processo único.

## Para correr em desenvolvimento

O site já tem PHP, por isso o servidor deixou de ser um servidor de ficheiros
estáticos e passou a ser o do PHP:

```sh
php -S localhost:8000
```

Isto é o servidor embutido do PHP, feito para desenvolvimento. **Não serve para
produção**: é de processo único e não define limites de tempo nem cabeçalhos
de segurança. Em produção usa-se Apache ou Nginx com PHP-FPM.

## Base de dados

MySQL. As credenciais vivem em `config/database.local.php`, que está no
`.gitignore` — o que se versiona é o `.exemplo.php`.

```sh
cp config/database.local.exemplo.php config/database.local.php
# edita a senha em config/database.local.php

mysql -u root -e "CREATE DATABASE harmonia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER 'harmonia'@'localhost' IDENTIFIED BY 'a-sua-senha';"
mysql -u root -e "GRANT ALL PRIVILEGES ON harmonia.* TO 'harmonia'@'localhost'; FLUSH PRIVILEGES;"

mysql -u harmonia -p harmonia < db/schema.sql
mysql -u harmonia -p harmonia < db/seed.sql

php tools/testar-ligacao.php
```

Para entrar no painel `/admin/` é preciso um utilizador. O script pede email,
nome e senha, e guarda apenas o hash:

```sh
php tools/criar-utilizador.php
```

A senha é lida sem eco no ecrã e não é guardada em lado nenhum — só o resultado
de `password_hash()`. Se a perderes, não a recuperas: cria-se outro utilizador
e apaga-se o primeiro. Este script só é preciso para o primeiro acesso; os
seguintes criam-se a partir do painel.

`testar-ligacao.php` confirma a ligação, o charset e se as sete tabelas
existem. Se o MySQL não arrancar depois de reiniciar a máquina:

```sh
launchctl kickstart -k gui/$(id -u)/local.mysql
```

## Verificações

Correm sem dependências externas. Precisam de PHP 8.1 ou superior
(`array_is_list()`, usado para distinguir objetos JSON de listas):

```sh
php tools/check_text.php   # texto corrompido, palavras coladas
php tools/check_site.php   # JSON-LD, âncoras, robots.txt, coerência NAP
```

`check_site.php` é o mais útil depois de editar conteúdo: garante que não há
`@id` de JSON-LD duplicado com atributos contraditórios, que todas as âncoras
existem, que cada agente de treino está bloqueado em `robots.txt` e que
moradas, telefones e emails são idênticos em todas as páginas.

`check_text.php` cobre o HTML, o CSS, o JavaScript e os ficheiros de `db/` e
`config/`.

## Decisões de implementação

**Todo o conteúdo está no HTML.** O JavaScript apenas realça a interface —
menu, separadores de cidade e construção da mensagem de contacto. Nenhuma
página depende de script para mostrar conteúdo, o que é requisito para
indexação e para os motores de IA conseguirem extrair o texto.

**O formulário grava na base de dados, mas o `mailto:` continua de reserva.**
`api/pedidos.php` valida no servidor e regista o pedido em `pedidos`. Se o PHP
não estiver a responder, a rede falhar, ou o servidor devolver um erro, o
JavaScript volta a abrir o programa de email com a mensagem já escrita. É o
mesmo princípio de ativação progressiva dos separadores: nenhum visitante fica
sem forma de contactar a escola por causa de uma peça que pode estar em baixo.
Quem não tiver JavaScript tem o formulário com `action` e `method`, e recebe
uma página de confirmação em HTML em vez de JSON.

**Um pedido que vira aluno é uma cópia, não uma transformação.** Ao converter
um pedido em aluno, faz-se `INSERT` em `alunos` e o pedido passa a
`convertido`. Um pedido é uma intenção; um aluno tem aulas, nível e estado
próprios, com vida própria depois da matrícula. Reescrever a linha em `pedidos`
destruía o registo do contacto original e, com ele, a forma de responder ao
pedido inicial.

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
6. Criar a base de dados no alojamento e importar `db/schema.sql` e
   `db/seed.sql`. Escrever `config/database.local.php` **à mão** no
   servidor, com a senha do alojamento — nunca copie a senha de local, nem
   versione esse ficheiro.
7. Confirmar que o servidor não mostra erros ao visitante. Com
   `display_errors` ligado, um erro de PHP imprime o caminho dos ficheiros e a
   linha donde veio — o que entrega a estrutura do projecto a quem o provoke.
   Em produção: `display_errors = Off` e `log_errors = On`.

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