<?php
declare(strict_types=1);

/*
 * Entrada no painel.
 *
 * A sessão não sobrevive à mudança de senha, e o token CSRF é regenerado no
 * login. Se não o fosse, um link aberto numa aba antes de o utilizador iniciar
 * sessão serviria para Assinar coisas em nome dele.
 */

require __DIR__ . '/_bootstrap.php';

if (autenticado()) {
    header('Location: index.php');
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');

    $chaveLimite = 'login:' . strtolower($email) . ':' . ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!passouLimite($chaveLimite)) {
        $erro = 'Demasiadas tentativas. Espera quinze minutos e tenta outra vez.';
    } elseif ($email === '' || $senha === '') {
        $erro = 'Preenche o email e a senha.';
    } else {
        $stmt = db()->prepare('SELECT id, email, nome, password_hash FROM utilizadores WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $utilizador = $stmt->fetch();

        /* password_verify com um hash falso devolve false sem erro. Corremos
           o mesmo caminho quando o email não existe, para que o tempo de
           resposta não diga ao atacante que emails estão registados. */
        $hash = $utilizador['password_hash'] ?? '$2y$12$' . str_repeat('.', 53);

        if (password_verify($senha, $hash) && $utilizador) {
            if (password_needs_rehash($utilizador['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE utilizadores SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($senha, PASSWORD_DEFAULT), $utilizador['id']]);
            }

            session_regenerate_id(true);

            $_SESSION['utilizador_id'] = (int) $utilizador['id'];
            $_SESSION['utilizador_email'] = $utilizador['email'];
            $_SESSION['utilizador_nome'] = $utilizador['nome'];

            /* Token novo depois de mudar de identidade. */
            unset($_SESSION['csrf']);

            limparLimite($chaveLimite);

            header('Location: index.php');
            exit;
        }

        $erro = 'Email ou senha errados.';
    }
}

cabecalho('Entrar', 'inicio');
?>

<h1 class="admin-title">Entrar no painel</h1>

<?php if ($erro !== null) : ?>
  <p class="admin-flash admin-flash--erro" role="alert"><?= e($erro) ?></p>
<?php endif; ?>

<form class="admin-form admin-form--narrow" method="post" action="login.php">
  <?= campoCsrf() ?>

  <div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autocomplete="username" autofocus>
  </div>

  <div class="field">
    <label for="senha">Senha</label>
    <input type="password" id="senha" name="senha" required autocomplete="current-password">
  </div>

  <button class="btn btn--primary" type="submit">Entrar</button>
</form>

<p class="admin-nota">
  Sem conta? Cria a primeira com <code>php tools/criar-utilizador.php</code> a
  partir da pasta do projecto.
</p>

<?php rodape(); ?>