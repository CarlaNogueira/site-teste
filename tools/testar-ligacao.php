<?php
declare(strict_types=1);

/*
 * Teste da ligação à base de dados.
 *
 * Uso:  php tools/testar-ligacao.php
 *
 * Serve para três coisas:
 *   - confirmar que o PHP consegue falar com o MySQL;
 *   - confirmar que as credenciais de config/database.local.php estão certas;
 *   - confirmar que db/schema.sql e db/seed.sql já foram aplicados.
 *
 * Sai com código 1 se algo estiver errado.
 */

require __DIR__ . '/../config/database.php';

$pdo = db();

echo "Ligação à base de dados OK\n\n";

// ---------- Ambiente ----------
$servidor = $pdo->query('SELECT VERSION()')->fetchColumn();

$charset = $pdo->query("SELECT @@character_set_database")->fetchColumn();
$collation = $pdo->query("SELECT @@collation_database")->fetchColumn();

printf("MySQL          %s\n", $servidor);
printf("Base de dados  %s\n", $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Charset        %s (%s)\n", $charset, $collation);
printf("Emulate prepares  %s\n", $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) ? 'ON' : 'off');

// ---------- Tabelas ----------
$tabelas = $pdo
    ->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')
    ->fetchAll(PDO::FETCH_COLUMN);

echo "\nTabelas (" . count($tabelas) . ")\n";

$esperadas = ['alunos', 'aulas', 'escolas', 'instrumentos', 'pedidos', 'professores', 'utilizadores'];

foreach ($esperadas as $t) {
    printf("  %-14s %s\n", $t, in_array($t, $tabelas, true) ? 'ok' : 'EM FALTA');
}

// ---------- Seed ----------
echo "\nDados\n";

$escolas = (int) $pdo->query('SELECT COUNT(*) FROM escolas')->fetchColumn();
$instrumentos = (int) $pdo->query('SELECT COUNT(*) FROM instrumentos')->fetchColumn();

printf("  Escolas        %d (esperado 3)\n", $escolas);
printf("  Instrumentos   %d (esperado 3)\n", $instrumentos);

// ---------- Integridade referencial ----------
/* O InnoDB não permite gravar uma aula com um aluno_id que não exista,
   por isso esta contagem deve ser sempre zero. Serve de canário: se um dia
   der != 0, é sinal de que alguém mexeu nas tabelas por fora da aplicação. */
$orfaos = (int) $pdo->query(
    'SELECT COUNT(*) FROM aulas a
       LEFT JOIN alunos al ON al.id = a.aluno_id
      WHERE al.id IS NULL'
)->fetchColumn();

printf("  Aulas órfãs   %d\n", $orfaos);

// ---------- Veredito ----------
$falhas = array_filter($esperadas, static fn(string $t): bool => !in_array($t, $tabelas, true));

echo "\n";

if ($falhas || $escolas !== 3 || $instrumentos !== 3) {
    echo "Alguma coisa falta. Se as tabelas não existirem, aplicar:\n";
    echo "  mysql -u harmonia -p harmonia < db/schema.sql\n";
    echo "  mysql -u harmonia -p harmonia < db/seed.sql\n";
    exit(1);
}

echo "Tudo pronto.\n";
exit(0);