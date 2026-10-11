<?php
declare(strict_types=1);

/*
 * Base comum do painel /admin/.
 *
 * Todo o resto do painel faz require deste ficheiro. Ele trata de três
 * coisas que nunca podem ser esquecidas:
 *
 *   1. A sessão, iniciada com cookie httponly e SameSite.
 *   2. O token CSRF, que acompanha todos os formulários que escrevem.
 *   3. A saída de texto com htmlspecialchars, para que um nome de aluno
 *      chamado <script> não seja lido como código pelo navegador.
 */

require __DIR__ . '/../config/database.php';

/* ------------------------------------------------------------------ sessão */

/* Se o PHP já tiver iniciado a sessão (noutro ficheiro), respeitamos isso. */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        /* httponly: o JavaScript da página não consegue ler o cookie. Se um
           Injection correr, não consegue roubar a sessão. */
        'httponly' => true,
        /* Lax: o cookie não acompanha o pedido para outro sítio, o que
           impede que um link externo envie a sessão de volta. */
        'samesite' => 'Lax',
        /* secure: só por HTTPS. Em localhost não há HTTPS, e um cookie com
           esta marca não seria enviado, o que impossiberia o login local. */
        'secure' => !empty($_SERVER['HTTPS']),
    ]);

    session_start();
}

/* ------------------------------------------------------------------ saída */

/** Escapa texto para ir para HTML. Tudo o que venha da base ou de um
    formulário passa por aqui antes de ser impresso. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ CSRF */

/** Devolve o token desta sessão, criando-o na primeira chamada. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        /* random_bytes é criptograficamente seguro; rand() e mt_rand() não
           são, e adivinhar o token é o que o CSRF tenta impedir. */
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/** O campo oculto que tem de accompanyar todo o formulário que escreve. */
function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

/** Verifica o token. Chamar no topo de qualquer POST. */
function verificarCsrf(): void
{
    $enviado = $_POST['csrf'] ?? '';

    /* hash_equals compara em tempo constante: um === simples sai mais cedo
       no primeiro carácter diferente e deixaria o atacante medir o token. */
    if (!is_string($enviado) || !hash_equals(csrfToken(), $enviado)) {
        http_response_code(400);
        exit('Pedido recusado: a sessão expirou ou o formulário não veio desta sessão. Recarrega a página e tenta outra vez.');
    }
}

/* ------------------------------------------------------------------ sessão de login */

function autenticado(): bool
{
    return !empty($_SESSION['utilizador_id']);
}

/** Redirige para o login se não houver sessão válida. */
function exigirLogin(): void
{
    if (!autenticado()) {
        header('Location: login.php');
        exit;
    }
}

/* ------------------------------------------------------------------ mensagens */

function flash(?string $mensagem = null): ?string
{
    if ($mensagem !== null) {
        $_SESSION['flash'] = $mensagem;
        return null;
    }

    $guardada = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $guardada;
}

/* ------------------------------------------------------------------ limitador */

/**
 * Trava tentativas repetidas a partir da mesma origem.
 *
 * Sem isto, um atacante pode tentar milhares de senhas por segundo contra o
 * login. O contador é um ficheiro no directório temporário — são só
 * timestamps, e não vale a pena uma tabela na base para isto.
 *
 * @param string $chave identificador do que se está a limitar (ex.: "login")
 */
function passouLimite(string $chave, int $maximo = 8, int $janelaMin = 15): bool
{
    $dir = sys_get_temp_dir() . '/harmonia-limite';

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // sem escrita não bloqueamos o utilizador
    }

    /* A chave entra em hash: nem o IP nem o email ficam legíveis em disco. */
    $ficheiro = $dir . '/' . hash('sha256', $chave) . '.txt';
    $desde = time() - ($janelaMin * 60);

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

    $marcas[] = time();
    @file_put_contents($ficheiro, implode("\n", $marcas));

    return true;
}

/** Liberta o contador depois de uma tentativa bem sucedida. */
function limparLimite(string $chave): void
{
    $ficheiro = sys_get_temp_dir() . '/harmonia-limite/' . hash('sha256', $chave) . '.txt';
    @unlink($ficheiro);
}

/* ------------------------------------------------------------------ layout */

/** Cabeçalho do painel. $actual é 'inicio'|'pedidos'|'alunos'|'aulas'. */
function cabecalho(string $titulo, string $actual): void
{
    $nav = [
        'inicio'       => 'Painel',
        'pedidos'      => 'Pedidos',
        'alunos'       => 'Alunos',
        'professores'  => 'Professores',
        'aulas'        => 'Aulas',
    ];

    $itens = '';
    foreach ($nav as $chave => $texto) {
        $actual_ = $chave === $actual ? ' aria-current="page"' : '';
        $itens .= '<a class="admin-nav__link"' . $actual_ . ' href="' . $chave . '.php">'
            . e($texto) . '</a>';
    }
    ?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> — Painel Harmonia</title>
<link rel="stylesheet" href="../assets/css/main.css">
<link rel="stylesheet" href="../assets/css/admin.css">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
</head>
<body class="admin">

<header class="admin-header">
  <div class="container admin-header__inner">
    <a class="brand" href="index.php">
      <span class="brand__mark" aria-hidden="true">♪</span>
      <span>Harmonia <span class="brand__cities">painel</span></span>
    </a>
    <nav class="admin-nav" aria-label="Secções do painel">
      <?= $itens ?>
      <a class="admin-nav__link admin-nav__link--sair" href="sair.php">Sair</a>
    </nav>
  </div>
</header>

<main class="admin-main">
  <div class="container">
    <?php
    $mensagem = flash();
    if ($mensagem !== null) {
        echo '<p class="admin-flash" role="status">' . e($mensagem) . '</p>';
    }
    ?>
<?php
}

/** Rodapé do painel. Fecha o que cabecalho() abriu. */
function rodape(): void
{
    ?>
  </div>
</main>

<footer class="admin-footer">
  <div class="container">
    <p>Painel da Escola de Música Harmonia. Sessão iniciada como
      <?= e($_SESSION['utilizador_email'] ?? '') ?>.</p>
  </div>
</footer>

</body>
</html>
    <?php
}
