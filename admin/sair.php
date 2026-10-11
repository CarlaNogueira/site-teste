<?php
declare(strict_types=1);

/*
 * Termina a sessão.
 *
 * Não aceita POST: é um link de saída, e um GET pode ser Following por um
 * Third-party sem querer. Destruir a sessão não muda nada para quem não tem
 * sessão, por isso não vale a pena exigir CSRF.
 *
 * O exit é o ponto: sem ele, o resto da página continuava a correr como se
 * houvesse sessão.
 */

require __DIR__ . '/_bootstrap.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

header('Location: login.php');
exit;