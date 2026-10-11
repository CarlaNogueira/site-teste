<?php
declare(strict_types=1);

/*
 * Verificador de texto dos ficheiros do site.
 *
 * Deteta três classes de defeito que surgiram durante a escrita dos ficheiros:
 *
 *   1. Caracteres fora do conjunto esperado (alfabeto português, pontuação
 *      comum e um punhado de símbolos usados no CSS e nos ícones).
 *   2. Palavras coladas por perda de espaço entre elas.
 *   3. Palavras com sufixos ou prefixos de outra língua colados, típicos de
 *      escrita automática sem revisão.
 *
 * Os exemplos concretos do que se procura estão em $PADROES_COLADOS mais
 * abaixo. Não são transcritos aqui de propósito: este ficheiro verifica-se
 * a si próprio, e escrevê-los literalmente dispararia o alarme em cima dele.
 *
 * Uso:  php tools/check_text.php
 * Sai com código 1 se encontrar problemas.
 */

$RAIZ = dirname(__DIR__);

$ARQUIVOS = [
    'index.html',
    'sobre.html',
    'servicos.html',
    'contactos.html',
    'robots.txt',
    'sitemap.xml',
    'llms.txt',
    'README.md',
    'assets/css/main.css',
    'assets/css/admin.css',
    'assets/js/main.js',
    'db/schema.sql',
    'db/seed.sql',
    'config/database.php',
    'config/database.local.exemplo.php',
    'tools/testar-ligacao.php',
    'tools/criar-utilizador.php',
    'tools/check_site.php',
    'tools/check_text.php',
];

/* Caracteres não-ASCII legítimos neste projecto.
   mb_str_split parte em caracteres, não em bytes: é o que permite
   tratar um emoji (4 bytes em UTF-8) como um único caractere. */
$PERMITIDOS = mb_str_split(
    "áàâãéêíóôõúüçÁÀÂÃÉÊÍÓÔÕÚÜÇºª—–·“”‘’€…♪→©−≈"
    . "🎸🎹🎤×↔↑↓–■",
    1,
    'UTF-8'
);

/* Colagens sem espaço detectadas durante a escrita. São expressões
   regulares PCRE, idênticas às que a versão anterior usava. */
$PADROES_COLADOS = [
    '\bcomadultos\b',
    '\btrêsinstrumentos\b',
    '\bparchildren\b',
    '\bdeschools\b',
    '\baging\s+players\b',
    '\bqueJp\b',
    '\bteachsem\b',
    '\bSimply\s+um\b',
    '\boutrasoghals\b',
    '\bsãoidl\b',
    "\bou'amadores\b",
];

$problemas = 0;

foreach ($ARQUIVOS as $rel) {
    $caminho = $RAIZ . '/' . $rel;
    if (!file_exists($caminho)) {
        continue;
    }

    $texto = file_get_contents($caminho);

    /* \R parte em LF, CRLF, CR e VT/FF/NEL/LS/PS — o mesmo conjunto que
       o splitlines() tratava. */
    $linhas = preg_split('/\R/u', $texto) ?: [];

    foreach ($linhas as $i => $linha) {
        $num = $i + 1;

        /* ---------- 1. Caracteres inesperados ---------- */
        $caracteres = mb_str_split($linha, 1, 'UTF-8');
        $inesperados = [];

        foreach ($caracteres as $c) {
            $ord = mb_ord($c, 'UTF-8');
            if ($ord > 127 && !in_array($c, $PERMITIDOS, true)) {
                $inesperados[$c] = true;
            }
        }

        if ($inesperados) {
            $problemas++;
            $chaves = array_keys($inesperados);
            sort($chaves, SORT_STRING);
            $marcas = array_map(
                static fn(string $c): string => sprintf('%s (U+%04X)', $c, mb_ord($c, 'UTF-8')),
                $chaves
            );
            printf("[caractere] %s:%d: %s\n", $rel, $num, implode(' ', $marcas));
            printf("             %s\n", mb_substr(trim($linha), 0, 120, 'UTF-8'));
        }

        /* ---------- 2. e 3. Colagens de palavras ---------- */
        foreach ($PADROES_COLADOS as $padrao) {
            $n = preg_match_all('/' . $padrao . '/u', $linha, $achados, PREG_OFFSET_CAPTURE);
            if (!$n) {
                continue;
            }

            foreach ($achados[0] as [$texto_matched, $off_bytes]) {
                $problemas++;

                /* preg_match_all devolve offsets em BYTES; a linha é
                   indexada em caracteres. Convertemos para não partir
                   um caractere multibyte nas extremidades do excerto. */
                $ini = mb_strlen(substr($linha, 0, $off_bytes), 'UTF-8');
                $fim = $ini + mb_strlen($texto_matched, 'UTF-8');

                $de = max(0, $ini - 40);
                $ate = min(mb_strlen($linha, 'UTF-8'), $fim + 40);
                $excerto = mb_substr($linha, $de, $ate - $de, 'UTF-8');

                printf("[colagem]  %s:%d: %s\n", $rel, $num, var_export($texto_matched, true));
                printf("             ...%s...\n", $excerto);
            }
        }
    }
}

if ($problemas) {
    printf("\n%d problema(s) encontrado(s).\n", $problemas);
    exit(1);
}

echo "Texto limpo em todos os ficheiros verificados.\n";
exit(0);