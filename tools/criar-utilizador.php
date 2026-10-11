<?php
declare(strict_types=1);

/*
 * Criar um utilizador para o painel /admin/.
 *
 * Uso:  php tools/criar-utilizador.php
 *
 * Pede email, nome e senha. A senha é lida sem eco no ecrã e nunca é
 * guardada em claro: o que fica na base é o resultado de password_hash(),
 * de que não se pode voltar à senha.
 *
 * Este script não é preciso em produção: cria-se o primeiro utilizador
 * localmente e a partir daí cria-se os restantes a partir do painel.
 */

require __DIR__ . '/../config/database.php';

/* 12 caracteres: o mínimo abaixo do qual um painel fica dependente de adivinhar. */
const MIN_SENHA = 12;

/**
 * Lê uma linha do teclado. Com $oculto, os caracteres não são mostrados
 * enquanto se escreve. Quando a entrada não é um terminal (em automação),
 * o stty é ignorado e a linha é lida na mesma.
 */
function perguntar(string $rotulo, bool $oculto = false): string
{
    if ($oculto && stream_isatty(STDIN)) {
        shell_exec('stty -echo');
        fwrite(STDOUT, $rotulo);
        $linha = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);

        return trim((string) $linha);
    }

    fwrite(STDOUT, $rotulo);

    return trim((string) fgets(STDIN));
}

function falhar(string $msg): never
{
    fwrite(STDERR, "\nERRO: $msg\n");
    exit(1);
}

// ------------------------------------------------------------------ email
$email = strtolower(perguntar('Email: '));

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    falhar("'$email' não é um email válido.");
}

// ------------------------------------------------------------------ nome
$nome = perguntar('Nome: ');

if (mb_strlen($nome) < 2) {
    falhar('O nome é demasiado curto.');
}

// ------------------------------------------------------------------ senha
$senha = perguntar('Senha: ', true);

if (mb_strlen($senha) < MIN_SENHA) {
    falhar("A senha precisa de pelo menos " . MIN_SENHA . ' caracteres.');
}

$confirmacao = perguntar('Repetir a senha: ', true);

if (!hash_equals($senha, $confirmacao)) {
    falhar('As senhas não coincidem.');
}

/* password_hash com PASSWORD_DEFAULT usa o algoritmo mais forte que o PHP
   conhece. Se um dia o PHP passar a conhecer um algoritmo melhor, o mesmo
   código passa a usá-lo sem que nada tenha de ser mudado. */
$hash = password_hash($senha, PASSWORD_DEFAULT);

// ------------------------------------------------------------------ gravar
try {
    $stmt = db()->prepare(
        'INSERT INTO utilizadores (email, password_hash, nome)
         VALUES (:email, :hash, :nome)'
    );

    $stmt->execute([
        'email' => $email,
        'hash'  => $hash,
        'nome'  => $nome,
    ]);
} catch (PDOException $e) {
    /* 23000 é a violação de restrição; a única aqui é o email único. */
    if ($e->getCode() === '23000') {
        falhar("Já existe um utilizador com o email $email.");
    }

    throw $e;
}

echo "\nUtilizador criado.\n";
echo "  Email  $email\n";
echo "  Hash   " . substr($hash, 0, 20) . "...\n";
echo "\nA senha não foi guardada em lado nenhum: só o hash foi. Se a\n";
echo "perderes, cria outro utilizador e apaga este com:\n";
echo "  DELETE FROM utilizadores WHERE email = '$email';\n";
exit(0);