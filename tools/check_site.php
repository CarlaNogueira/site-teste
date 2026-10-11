<?php
declare(strict_types=1);

/*
 * Validação estrutural do site (sem dependências externas).
 *
 * Verifica:
 *   1. JSON-LD de cada página: parse, @id únicos, @id referenciados existentes.
 *   2. Âncoras internas: cada link "página#âncora" tem destino real.
 *   3. Cada âncora referenciada existe na página de destino.
 *   4. Um único <h1> por página e sem saltos de nível de título.
 *   5. Ficheiros referenciados por href/src que existem no disco.
 *   6. robots.txt: cada agente de treino está bloqueado, cada agente de
 *      pesquisa está permitido.
 *   7. Coerência dos dados de contacto (NAP) entre JSON-LD, llms.txt e páginas.
 *
 * Uso:  php tools/check_site.php
 * Sai com código 1 se encontrar erros.
 */

/* Constante e não variável: é o único modo de a ler dentro das funções
   abaixo sem Repeatir `global` em cada uma. */
define('RAIZ', dirname(__DIR__));

/* Só HTML estático. As rotas /admin/ e /api/ são geradas em PHP e não são
   analisadas aqui: os seus <link> e <form action> apontariam para ficheiros
   que não existem em disco. */
$PAGINAS = [
    'index.html',
    'sobre.html',
    'servicos.html',
    'contactos.html',
    'privacidade.html',
];

$AGENTES_TREINO = [
    'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended',
    'CCBot', 'Bytespider',
];
$AGENTES_PESQUISA = [
    'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User',
    'PerplexityBot', 'Googlebot', 'Bingbot',
];

$erros = [];
$avisos = [];
$referidos = [];

function err(string $msg): void
{
    global $erros;
    $erros[] = $msg;
}

function aviso(string $msg): void
{
    global $avisos;
    $avisos[] = $msg;
}

/**
 * Coleta de uma página: links, ids, níveis de título e blocos JSON-LD.
 *
 * O DOMDocument faz o trabalho que o HTMLParser fazia na versão anterior:
 * normaliza os nomes de atributos para minúsculas e devolve os nós por
 * ordem do documento, que é a ordem em que os erros devem ser reportados.
 */
final class Coletor
{
    /** @var list<string> */
    public array $links = [];
    /** @var list<string> */
    public array $ids = [];
    /** @var list<int> */
    public array $h_niveis = [];
    /** @var list<string> */
    public array $ld_json = [];
    public int $imgs_sem_alt = 0;

    private DOMXPath $xpath;

    public function __construct(string $html)
    {
        /* loadHTML() de HTML5 pede interpolação e não understand as tags
           sem fechar; silenciamos o barulho do libxml e seguimos. */
        $anterior = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $this->xpath = new DOMXPath($doc);

        /* Uma só consulta em ordem de documento, para que a lista de links
           saia na mesma ordem em que aparecem no ficheiro. */
        foreach ($this->xpath->query('//a[@href] | //link | //script | //img') as $n) {
            switch ($n->nodeName) {
                case 'a':
                    $this->links[] = $n->getAttribute('href');
                    break;
                case 'link':
                    if ($n->hasAttribute('src')) {
                        $this->links[] = $n->getAttribute('src');
                    }
                    if ($n->hasAttribute('href')) {
                        $this->links[] = $n->getAttribute('href');
                    }
                    break;
                case 'script':
                    if ($n->hasAttribute('src')) {
                        $this->links[] = $n->getAttribute('src');
                    }
                    break;
                case 'img':
                    if ($n->hasAttribute('src')) {
                        $this->links[] = $n->getAttribute('src');
                    }
                    break;
            }
        }

        foreach ($this->xpath->query('//*[@id]') as $n) {
            $this->ids[] = $n->getAttribute('id');
        }

        foreach ($this->xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $n) {
            $this->h_niveis[] = (int) $n->nodeName[1];
        }

        foreach ($this->xpath->query('//script[@type="application/ld+json"]') as $n) {
            $this->ld_json[] = $n->textContent;
        }

        $this->imgs_sem_alt = $this->xpath->query('//img[not(@alt)]')->length;
    }
}

/** @return array{0: string, 1: string} alvo e fragmento, como urldefrag. */
function urldefrag(string $url): array
{
    $pos = strpos($url, '#');
    if ($pos === false) {
        return [$url, ''];
    }

    return [substr($url, 0, $pos), rawurldecode(substr($url, $pos + 1))];
}

/** Equivalente a (RAIZ / alvo) do pathlib. Um alvo com "/" inicial é
    absoluto e resolve para a raiz do sistema de ficheiros. */
function resolver(string $alvo): string
{
    if ($alvo === '') {
        return RAIZ;
    }
    if ($alvo[0] === '/') {
        return $alvo;
    }

    return RAIZ . '/' . $alvo;
}

/**
 * Devolve os @id usados como referência que não são declarados em
 * $declarados. Percorre listas e dicionários em qualquer profundidade.
 *
 * @param array<string,true> $declarados
 * @return list<string>
 */
function ids_referenciados(mixed $o, array $declarados): array
{
    if (!is_array($o)) {
        return [];
    }

    $achados = [];
    $eh_lista = array_is_list($o);

    foreach ($o as $k => $v) {
        /* Numa lista as chaves são inteiros sequenciais e nunca '@id'. */
        if (!$eh_lista && $k === '@id' && is_string($v)) {
            if (!isset($declarados[$v])) {
                $achados[] = $v;
            }
            /* Não se desce no valor: '@id' é sempre uma folha. */
            continue;
        }

        foreach (ids_referenciados($v, $declarados) as $ref) {
            $achados[] = $ref;
        }
    }

    return $achados;
}

/** json_encode com chaves ordenadas, para comparar dois objetos JSON
    independentemente da ordem em que foram escritos. */
function canonizar(mixed $v): string
{
    if (is_array($v)) {
        if (!array_is_list($v)) {
            ksort($v);
        }
        foreach ($v as $k => $item) {
            $v[$k] = canonizar($item);
        }
    }

    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------- 1. Parse + integridade estrutural ----------
$parsers = [];

foreach ($PAGINAS as $pagina) {
    $caminho = RAIZ . '/' . $pagina;
    if (!file_exists($caminho)) {
        err("$pagina: ficheiro em falta");
        continue;
    }

    $texto = file_get_contents($caminho);
    $p = new Coletor($texto);
    $parsers[$pagina] = $p;

    $h1 = count(array_filter($p->h_niveis, static fn(int $n): bool => $n === 1));
    if ($h1 !== 1) {
        err("$pagina: esperado exatamente 1 <h1>, encontrado $h1");
    }

    foreach ($p->h_niveis as $i => $a) {
        $b = $p->h_niveis[$i + 1] ?? null;
        if ($b !== null && $b > $a + 1) {
            aviso("$pagina: salto de nível de título h$a -> h$b");
        }
    }

    if ($p->imgs_sem_alt) {
        err("{$pagina}: {$p->imgs_sem_alt} <img> sem atributo alt");
    }

    /* Ids duplicados procurados no texto cru: é o mesmo teste que a versão
       anterior fazia, e apanha também ids escritos dentro de atributos. */
    preg_match_all('/\sid="([^"]+)"/', $texto, $m);
    $contagem = array_count_values($m[1]);
    $dupes = array_keys(array_filter($contagem, static fn(int $n): bool => $n > 1));
    sort($dupes, SORT_STRING);
    if ($dupes) {
        err("$pagina: id duplicado(s): [" . implode(', ', array_map(
            static fn(string $d): string => "'$d'",
            $dupes
        )) . ']');
    }
}

// ---------- 1b. Imagens declaradas em meta tags e JSON-LD ----------
// og:image, twitter:image e as propriedades logo/image do schema.org não
// passam pelo atributo href/src, por isso precisam da sua própria verificação.
$PREFIXO = 'https://exemplo.com/';

foreach ($PAGINAS as $pagina) {
    $caminho = RAIZ . '/' . $pagina;
    if (!file_exists($caminho)) {
        continue;
    }
    $texto = file_get_contents($caminho);

    preg_match_all('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/', $texto, $a);
    preg_match_all('/<meta[^>]+name="twitter:image"[^>]+content="([^"]+)"/', $texto, $b);
    preg_match_all('/"(?:logo|image)"\s*:\s*"([^"]+)"/', $texto, $c);

    foreach (array_merge($a[1], $b[1], $c[1]) as $url) {
        if (!str_starts_with($url, $PREFIXO)) {
            continue;
        }
        $destino = resolver(substr($url, strlen($PREFIXO)));
        if (!file_exists($destino)) {
            err("$pagina: imagem declarada em metadados não existe: $url");
        }
    }
}

$og = RAIZ . '/assets/img/og-capa.jpg';
if (file_exists($og)) {
    if (!is_readable($og)) {
        err("assets/img/og-capa.jpg não pode ser lida");
    } else {
        /* As plataformas de partilha social esperam 1200x630. */
        $bytes = filesize($og);
        if ($bytes < 5000) {
            aviso('og-capa.jpg é muito pequena; pode renderizar mal');
        }
    }
} else {
    err('assets/img/og-capa.jpg em falta (usada por og:image e twitter:image)');
}

// ---------- 2. JSON-LD: @id globais e consistência de entidade ----------
// Um @id é uma identidade persistente: pode estar declarada numa página
// e referenciada noutra (padrão normal num site com várias páginas). O
// que não pode acontecer é a mesma identidade ser declarada com
// atributos contraditórios — é isso que quebra o reconhecimento de entidade.
$definidos = [];

foreach ($parsers as $pagina => $p) {
    foreach ($p->ld_json as $i => $bruto) {
        try {
            $doc = json_decode($bruto, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exc) {
            /* O libjson do PHP dá uma mensagem mais curta que a de outros
               parsers ("Syntax error" sem apontar a coluna). A detecção e a
               numeração do bloco são as mesmas. */
            err("$pagina: JSON-LD #$i inválido: {$exc->getMessage()}");
            continue;
        }

        $grafo = (is_array($doc) && isset($doc['@graph'])) ? $doc['@graph'] : [$doc];

        $declarados = [];
        foreach ($grafo as $no) {
            if (is_array($no) && !array_is_list($no) && !empty($no['@id']) && is_string($no['@id'])) {
                $declarados[$no['@id']] = true;
            }
        }

        foreach ($grafo as $no) {
            if (!is_array($no) || array_is_list($no) || empty($no['@id']) || !is_string($no['@id'])) {
                continue;
            }
            $nid = $no['@id'];
            if (isset($definidos[$nid])) {
                [$outra_pag, $outro_no] = $definidos[$nid];
                if (canonizar($outro_no) !== canonizar($no)) {
                    err(
                        "@id $nid declarado de forma diferente em "
                        . "$outra_pag e $pagina — quebra a consistência de entidade"
                    );
                }
            } else {
                $definidos[$nid] = [$pagina, $no];
            }
        }

        // Referências não resolvidas localmente são testadas contra
        // o conjunto global de @id declarados no site.
        foreach ($grafo as $no) {
            foreach (ids_referenciados($no, $declarados) as $ref) {
                $referidos["$pagina\u{0000}$i\u{0000}$ref"] = [$pagina, $i, $ref];
            }
        }
    }
}

$lista_ref = array_values($referidos);
usort($lista_ref, static function (array $a, array $b): int {
    return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
});

foreach ($lista_ref as [$pagina, $i, $ref]) {
    if (!isset($definidos[$ref])) {
        err("$pagina: JSON-LD #$i referencia @id inexistente no site: $ref");
    }
}

// ---------- 3. Âncoras e ficheiros ----------
foreach ($parsers as $pagina => $p) {
    foreach ($p->links as $href) {
        if (preg_match('#^(https?:|mailto:|tel:|data:)#', $href)) {
            continue;
        }

        [$alvo, $fragmento] = urldefrag($href);

        if ($alvo !== '') {  // link para outro ficheiro
            $destino = resolver($alvo);
            if (!file_exists($destino)) {
                err("$pagina: link quebrado -> $href");
                continue;
            }
            $destino_pagina = basename($destino);
        } else {
            $destino_pagina = $pagina;
        }

        if ($fragmento !== '' && isset($parsers[$destino_pagina])) {
            if (!in_array($fragmento, $parsers[$destino_pagina]->ids, true)) {
                err("$pagina: âncora inexistente -> $href");
            }
        }
    }
}

// ---------- 4. robots.txt ----------
$robots = file_get_contents(RAIZ . '/robots.txt');

$blocos = preg_split('/^user-agent:\s*/mi', $robots, -1, PREG_SPLIT_NO_EMPTY) ?: [];

$politica = [];
foreach ($blocos as $bloco) {
    $primeira_linha = preg_split('/\R/', trim($bloco))[0] ?? '';
    $agente = strtolower(trim($primeira_linha));
    $baixo = strtolower($bloco);

    if (str_contains($baixo, 'disallow: /')) {
        $politica[$agente] = 'disallow';
    } elseif (str_contains($baixo, 'allow: /')) {
        $politica[$agente] = 'allow';
    }
}

foreach ($AGENTES_TREINO as $agente) {
    if (($politica[strtolower($agente)] ?? null) !== 'disallow') {
        err("robots.txt: agente de treino '$agente' deveria estar bloqueado");
    }
}

foreach ($AGENTES_PESQUISA as $agente) {
    if (($politica[strtolower($agente)] ?? null) !== 'allow') {
        err("robots.txt: agente de pesquisa '$agente' deveria estar permitido");
    }
}

/* As rotas geradas em PHP não são conteúdo público: uma página de painel não
   deve entrar num índice de pesquisa, e o directório das credenciais não deve
   sequer ser sinalizado como rastreável. */
foreach (['/api/', '/admin/', '/config/'] as $rota) {
    if (!preg_match('#^Disallow:\s*' . preg_quote($rota, '#') . '\s*$#mi', $robots)) {
        err("robots.txt: $rota devia estar em Disallow");
    }
}

// ---------- 5. Coerência de contactos (NAP) ----------
$llms = file_get_contents(RAIZ . '/llms.txt');
$contactos = [
    'Lisboa' => ['Rua Augusta 120', '1100-053', '+351 21 000 0111', 'lisboa@harmonia.pt'],
    'Porto'  => ['Rua de Cedofeita 250', '4050-174', '+351 22 000 0222', 'porto@harmonia.pt'],
    'Beja'   => ['Rua Comenda Rui Rafael 12', '7800-000', '+351 26 000 0333', 'beja@harmonia.pt'],
];

$todas_paginas = implode("\n", array_map(
    static fn(string $pag): string => (string) file_get_contents(RAIZ . '/' . $pag),
    array_keys($parsers)
));

foreach ($contactos as $cidade => [$rua, $codigo, $telefone, $email]) {
    foreach (['llms.txt' => $llms, 'páginas' => $todas_paginas] as $fonte => $texto) {
        foreach ([$rua, $codigo, $telefone, $email] as $indice => $valor) {
            $rotulo = ['morada', 'código postal', 'telefone', 'email'][$indice];
            if (!str_contains($texto, $valor)) {
                err("NAP inconsistente: $cidade $rotulo '$valor' ausente em $fonte");
            }
        }
    }
}

// ---------- Relatório ----------
foreach ($avisos as $a) {
    echo "AVISO: $a\n";
}
foreach ($erros as $e) {
    echo "ERRO:  $e\n";
}

echo "\n" . count($erros) . ' erro(s), ' . count($avisos) . " aviso(s).\n";
exit($erros ? 1 : 0);