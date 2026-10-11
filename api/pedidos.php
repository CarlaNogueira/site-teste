<?php
declare(strict_types=1);

/*
 * Recebe o pedido de aula experimental.
 *
 * Chamado por POST a partir do formulário em contactos.html.
 *
 * Resposta em JSON quando o pedido vem de fetch (Accept: application/json);
 * resposta em HTML quando vem de um envio de formulário sem JavaScript, para
 * que quem não tem script veja uma página e não um bloco de JSON.
 *
 * Regra de oro deste ficheiro: nada do que vem do utilizador entra na
 * instrução SQL. Os valores vão sempre em parâmetros com nome, nunca
 * concatenados numa string.
 */

require __DIR__ . '/../config/database.php';

header('X-Content-Type-Options: nosniff');

/* ------------------------------------------------------------------ saída */

/** @return bool true se quem pede espera JSON (isto é, vem de fetch). */
function querJson(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** Envelope mínimo para a resposta sem JavaScript. */
function paginaHtml(string $titulo, string $texto): string
{
    $titulo = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $texto = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <!DOCTYPE html>
    <html lang="pt-PT">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="robots" content="noindex">
      <title>{$titulo}</title>
      <style>
        body { font: 1rem/1.6 system-ui, sans-serif; max-width: 38rem;
               margin: 4rem auto; padding: 0 1.5rem; color: #1a1a1a; }
        h1 { font-size: 1.5rem; margin-bottom: .5rem; }
        p { margin-bottom: 1.5rem; }
        a { color: #9a3412; }
      </style>
    </head>
    <body>
      <h1>{$titulo}</h1>
      <p>{$texto}</p>
      <p><a href="/contactos.html">Voltar aos contactos</a></p>
    </body>
    </html>
    HTML;
}

/**
 * Termina o pedido com a resposta certa para quem perguntou.
 *
 * @param array<string,mixed> $dados conteúdo em JSON
 */
function responder(bool $json, int $status, array $dados, string $titulo, string $texto): never
{
    http_response_code($status);

    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo paginaHtml($titulo, $texto);
    }

    exit;
}

/* ------------------------------------------------------------------ método */

$json = querJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(
        $json,
        405,
        ['ok' => false, 'erro' => 'Método não permitido.'],
        'Método não permitido',
        'Este endereço só aceita pedidos enviados pelo formulário.'
    );
}

/* ------------------------------------------------------------------ armadilha */

/* Campo invisível ao qual nenhum humano escreve. Os bots que preenchem
   todos os campos do formulário dão-lhe valor; se isso acontecer, respondemos
   como se tivéssemos gravado, sem gravar. Se devolvêssemos um erro, o bot
   saberia que foi detectado e ajustava o comportamento. */
if (($_POST['website'] ?? '') !== '') {
    responder(
        $json,
        200,
        ['ok' => true, 'id' => 0],
        'Pedido recebido',
        'Obrigado. A escola escolhida responde por email.'
    );
}

/* ------------------------------------------------------------------ limite */

/**
 * No máximo $maximo pedidos por IP em $janelaMin minutos.
 *
 * O contador vive num ficheiro no directório temporário: são só datas de
 * envio, nada de conteúdo, e não vale a pena uma tabela na base para isto.
 */
function passouLimite(string $ip, int $maximo = 5, int $janelaMin = 60): bool
{
    $dir = sys_get_temp_dir() . '/harmonia-limite';

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // se não conseguirmos escrever, não bloqueamos o pedido
    }

    /* O nome do ficheiro é um hash do IP: o IP em claro não fica em disco. */
    $ficheiro = $dir . '/' . hash('sha256', $ip) . '.txt';
    $agora = time();
    $desde = $agora - ($janelaMin * 60);

    $marcas = [];
    if (is_readable($ficheiro)) {
        foreach (file($ficheiro, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $quando = (int) $linha;
            if ($quando > $desde) {
                $marcas[] = $quando;
            }
        }
    }

    if (count($marcas) >= $maximo) {
        return false;
    }

    $marcas[] = $agora;
    @file_put_contents($ficheiro, implode("\n", $marcas));

    return true;
}

if (!passouLimite($_SERVER['REMOTE_ADDR'] ?? 'desconhecido')) {
    responder(
        $json,
        429,
        ['ok' => false, 'erro' => 'Demasiados pedidos seguidos. Tenta daqui a pouco.'],
        'Demasiados pedidos seguidos',
        'Recebemos pedidos deste endereço com muita frequência. Tenta daqui a pouco, ou escreve directamente para a escola por email ou telefone.'
    );
}

/* ------------------------------------------------------------------ validação */

$erros = [];

$nome       = trim($_POST['nome'] ?? '');
$email      = trim($_POST['email'] ?? '');
$telefone   = trim($_POST['telefone'] ?? '');
$escola     = trim($_POST['escola'] ?? '');
$instrumento = trim($_POST['instrumento'] ?? '');
$nivel      = trim($_POST['nivel'] ?? '');
$mensagem   = trim($_POST['mensagem'] ?? '');
/* isset() não chega: uma chave presente com valor vazio também devolve true,
   e um cliente que envie "consentimento=" apareceria como consentimento dado.
   Só aceitamos os valores que uma caixa marcada produz de facto. */
$consentiu = in_array($_POST['consentimento'] ?? '', ['on', '1', 'true'], true);

/* O que o formulário pode enviar. Uma lista de valores aceites é mais
   segura do que tentar adivinhar o que não é válido: o que não está na
   lista é rejeitado, mesmo que chegue por um caminho inesperado. */
$ESCOLAS_VALIDAS = ['lisboa', 'porto', 'beja'];
$INSTRUMENTOS_VALIDOS = ['', 'guitarra', 'piano', 'voz'];
$NIVEIS_VALIDOS = ['', 'iniciante', 'basico', 'intermedio', 'avancado'];

if ($nome === '') {
    $erros['nome'] = 'Diz-nos o teu nome.';
} elseif (mb_strlen($nome) > 120) {
    $erros['nome'] = 'O nome não pode ter mais de 120 caracteres.';
}

if ($email === '') {
    $erros['email'] = 'Precisamos de um email para te responder.';
} elseif (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $erros['email'] = 'Esse email não parece válido.';
}

/* Telefone é opcional, por isso só se valida quando vem preenchido. */
if ($telefone !== '' && !preg_match('/^[0-9\s+()\-.]{9,25}$/', $telefone)) {
    $erros['telefone'] = 'Esse número de telefone não parece válido.';
}

if (!in_array($escola, $ESCOLAS_VALIDAS, true)) {
    $erros['escola'] = 'Escolhe uma das três escolas.';
}

if (!in_array($instrumento, $INSTRUMENTOS_VALIDOS, true)) {
    $erros['instrumento'] = 'Instrumento não reconhecido.';
}

if (!in_array($nivel, $NIVEIS_VALIDOS, true)) {
    $erros['nivel'] = 'Nível não reconhecido.';
}

if (mb_strlen($mensagem) > 5000) {
    $erros['mensagem'] = 'A mensagem não pode ter mais de 5000 caracteres.';
}

/* Guardar dados de uma pessoa sem autorização não tem base legal. */
if (!$consentiu) {
    $erros['consentimento'] = 'É preciso autorizares o tratamento dos teus dados para te podermos responder.';
}

if ($erros) {
    responder(
        $json,
        422,
        ['ok' => false, 'erro' => 'Verifica os campos assinalados.', 'campos' => $erros],
        'Falta alguma coisa',
        'Há campos por corrigir. Volta atrás e tenta outra vez.'
    );
}

/* ------------------------------------------------------------------ gravar */

try {
    /* Os valores dos dois SELECT vêm do formulário, por isso também vão em
       parâmetros: 'lisboa' é aceite, mas 'lisboa\' OR 1=1 --' nunca chega
       a ser SQL. */
    $stmtEscola = db()->prepare('SELECT id FROM escolas WHERE cidade = ?');
    $stmtEscola->execute([$escola]);
    $escolaId = $stmtEscola->fetchColumn();

    if ($escolaId === false) {
        /* Só acontece se a tabela escolas não tiver a cidade. Registamos e
           recusamos, em vez de gravar com escola_id NULL. */
        error_log('[harmonia] cidade desconhecida no pedido: ' . $escola);

        responder(
            $json,
            500,
            ['ok' => false, 'erro' => 'Não conseguimos registar o pedido.'],
            'Algo correu mal',
            'Não conseguimos registar o teu pedido. Tenta outra vez.'
        );
    }

    $instrumentoId = null;
    if ($instrumento !== '') {
        $stmtInstr = db()->prepare('SELECT id FROM instrumentos WHERE slug = ?');
        $stmtInstr->execute([$instrumento]);
        $instrumentoId = $stmtInstr->fetchColumn();
    }

    /* NULL é diferente de string vazia para a base: '' gravaria uma linha
       com 0 caracteres, NULL significa mesmo "não preenchido". */
    $stmt = db()->prepare(
        'INSERT INTO pedidos
           (nome, email, telefone, escola_id, instrumento_id, nivel, mensagem, origem)
         VALUES
           (:nome, :email, :telefone, :escola_id, :instrumento_id, :nivel, :mensagem, :origem)'
    );

    $stmt->execute([
        'nome'           => $nome,
        'email'          => $email,
        'telefone'       => $telefone !== '' ? $telefone : null,
        'escola_id'      => $escolaId,
        'instrumento_id' => $instrumentoId,
        'nivel'          => $nivel !== '' ? $nivel : null,
        'mensagem'       => $mensagem !== '' ? $mensagem : null,
        'origem'         => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 190),
    ]);

    $id = (int) db()->lastInsertId();
} catch (PDOException $e) {
    /* Não se mostra o erro ao utilizador: pode revelar estrutura da base.
       Fica registado no log do servidor para diagnóstico. */
    error_log('[harmonia] falha ao gravar pedido: ' . $e->getMessage());

    responder(
        $json,
        500,
        ['ok' => false, 'erro' => 'Não conseguimos registar o pedido. Tenta outra vez.'],
        'Algo correu mal',
        'Não conseguimos registar o teu pedido. Tenta outra vez dentro de minutos, ou escreve directamente para a escola.'
    );
}

responder(
    $json,
    201,
    ['ok' => true, 'id' => $id],
    'Pedido registado',
    'Obrigado. A escola escolhida responde por email dentro de um dia útil.'
);