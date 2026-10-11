<?php
declare(strict_types=1);

/*
 * Ligação à base de dados.
 *
 * Este ficheiro NÃO tem credenciais. Lê-as de config/database.local.php,
 * que está no .gitignore e nunca deve ser versionado — é onde a senha vive.
 * Assim este ficheiro pode ir para o Git sem risco.
 *
 * Uso:
 *   require __DIR__ . '/../config/database.php';
 *   $pdo = db();
 *   $linhas = db()->query('SELECT * FROM escolas')->fetchAll();
 */

if (!function_exists('db')) {

    /**
     * Devolve uma ligação PDO à base Harmonia, pronta a usar.
     *
     * A variável `static` guarda a ligação para o resto do pedido. Cada
     * visita a uma página é um programa novo que morre no fim, por isso
     * `static` não partilha nada entre pedidos — só evita abrir duas
     * ligações quando db() é chamado mais do que uma vez no mesmo ficheiro.
     */
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $ficheiro = __DIR__ . '/database.local.php';

        if (!is_readable($ficheiro)) {
            throw new RuntimeException(
                "Falta config/database.local.php com as credenciais. "
                . "Copia o exemplo (config/database.local.exemplo.php) e preenche."
            );
        }

        /** @var array{host:string,port:int,db:string,user:string,pass:string} $cfg */
        $cfg = require $ficheiro;

        /* charset=utf8mb4 no DSN é o que diz ao MySQL para devolver os
           acentos e emojis corretamente, sem pontos de interrogação. */
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['port'],
            $cfg['db']
        );

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            /* ERRMODE_EXCEPTION: o PDO deixa de devolver false em erro e
               passa a lançar excepção. Sem isto, um erro de SQL passa
               despercebido e o código continua a correr com dados falsos. */
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            /* Cada linha como array associativo nome=>valor. */
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /* false: os prepared statements são enviados mesmo separados ao
               servidor. É o que garante que um valor vindo do formulário
               nunca é interpretado como SQL. */
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}