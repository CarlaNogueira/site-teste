<?php
declare(strict_types=1);

/*
 * Alunos.
 *
 * A matrícula normal é o pedido convertido em aluno. Este formulário existe
 * para o caso comum de uma pessoa aparecer ao balcão sem terGone pelo site.
 */

require __DIR__ . '/_bootstrap.php';

exigirLogin();

$escolas = db()->query('SELECT id, cidade FROM escolas ORDER BY cidade')->fetchAll();
$instrumentos = db()->query('SELECT id, nome FROM instrumentos ORDER BY nome')->fetchAll();

$niveis = [
    '' => '—',
    'iniciante' => 'Iniciante',
    'basico' => 'Básico',
    'intermedio' => 'Intermédio',
    'avancado' => 'Avançado',
];

$estadosAluno = [
    'ativo' => 'Activo',
    'pausado' => 'Pausado',
    'saiu' => 'Saiu',
];

/* ------------------------------------------------------------------ ações */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    $campos = [
        'nome'        => trim((string) ($_POST['nome'] ?? '')),
        'email'       => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'telefone'    => trim((string) ($_POST['telefone'] ?? '')),
        'escola_id'   => (int) ($_POST['escola_id'] ?? 0),
        'instrumento' => trim((string) ($_POST['instrumento'] ?? '')),
        'nivel'       => (string) ($_POST['nivel'] ?? ''),
        'estado'      => (string) ($_POST['estado'] ?? 'ativo'),
    ];

    /* O instrumento vem como slug (é o que os selects usam), a base guarda o
       id. Um valor vazio é legítimo: "Ainda não sei". */
    $instrumentoId = null;
    if ($campos['instrumento'] !== '') {
        $procura = db()->prepare('SELECT id FROM instrumentos WHERE slug = ?');
        $procura->execute([$campos['instrumento']]);
        $instrumentoId = $procura->fetchColumn() ?: null;
    }

    if ($acao === 'guardar') {
        if ($campos['nome'] === '') {
            flash('O aluno precisa de um nome.');
        } elseif (filter_var($campos['email'], FILTER_VALIDATE_EMAIL) === false) {
            flash('Esse email não parece válido.');
        } elseif ($campos['escola_id'] <= 0) {
            flash('Escolhe a escola do aluno.');
        } elseif ($id > 0) {
            db()->prepare(
                'UPDATE alunos
                    SET nome = :nome, email = :email, telefone = :telefone,
                        escola_id = :escola, instrumento_id = :instrumento,
                        nivel = :nivel, estado = :estado
                  WHERE id = :id'
            )->execute([
                'nome'        => $campos['nome'],
                'email'       => $campos['email'],
                'telefone'    => $campos['telefone'] !== '' ? $campos['telefone'] : null,
                'escola'      => $campos['escola_id'],
                'instrumento' => $instrumentoId,
                'nivel'       => $niveis[$campos['nivel']] ? $campos['nivel'] : null,
                'estado'      => in_array($campos['estado'], array_keys($estadosAluno), true) ? $campos['estado'] : 'ativo',
                'id'          => $id,
            ]);

            flash('Aluno actualizado.');
        } else {
            db()->prepare(
                'INSERT INTO alunos (nome, email, telefone, escola_id, instrumento_id, nivel, estado)
                 VALUES (:nome, :email, :telefone, :escola, :instrumento, :nivel, :estado)'
            )->execute([
                'nome'        => $campos['nome'],
                'email'       => $campos['email'],
                'telefone'    => $campos['telefone'] !== '' ? $campos['telefone'] : null,
                'escola'      => $campos['escola_id'],
                'instrumento' => $instrumentoId,
                'nivel'       => $niveis[$campos['nivel']] ? $campos['nivel'] : null,
                'estado'      => in_array($campos['estado'], array_keys($estadosAluno), true) ? $campos['estado'] : 'ativo',
            ]);

            flash('Aluno criado.');
        }

        header('Location: alunos.php');
        exit;
    }

    if ($acao === 'apagar' && $id > 0) {
        /* O ON DELETE CASCADE do esquema apaga também as aulas do aluno.
           Por isso a base não trava e o botão insiste no aviso. */
        db()->prepare('DELETE FROM alunos WHERE id = ?')->execute([$id]);
        flash('Aluno apagado, e com ele as aulas que tinha.');
    }

    header('Location: alunos.php');
    exit;
}

/* ------------------------------------------------------------------ listagem */

$emEdicao = null;
if (isset($_GET['editar'])) {
    /* O LEFT JOIN traz o slug do instrumento, que é o que os selects usam —
       a tabela alunos só guarda o id. */
    $procura = db()->prepare(
        'SELECT a.*, i.slug AS instrumento_slug
           FROM alunos a
      LEFT JOIN instrumentos i ON i.id = a.instrumento_id
          WHERE a.id = ?'
    );
    $procura->execute([(int) $_GET['editar']]);
    $emEdicao = $procura->fetch() ?: null;
}

/* Valores dos campos do formulário: os do aluno em edição, ou os vazios
   para um aluno novo. O partial recebe sempre a mesma forma. */
$aluno = $emEdicao ?: [
    'nome'            => '',
    'email'           => '',
    'telefone'        => '',
    'escola_id'       => '',
    'instrumento_slug' => '',
    'nivel'           => '',
    'estado'          => 'ativo',
];

$procuraInstrumento = db()->query(
    'SELECT a.*, e.cidade, i.slug AS instrumento_slug, i.nome AS instrumento
       FROM alunos a
       JOIN escolas e ON e.id = a.escola_id
  LEFT JOIN instrumentos i ON i.id = a.instrumento_id
      ORDER BY a.nome'
);

$alunos = $procuraInstrumento->fetchAll();

cabecalho('Alunos', 'alunos');
?>

<h1 class="admin-title">Alunos</h1>

<?php if ($emEdicao) : ?>
  <form class="admin-form" method="post">
    <?= campoCsrf() ?>
    <input type="hidden" name="acao" value="guardar">
    <input type="hidden" name="id" value="<?= (int) $emEdicao['id'] ?>">

    <h2>A editar <?= e($emEdicao['nome']) ?></h2>
    <?php require __DIR__ . '/_campos_aluno.php'; ?>

    <div class="cluster">
      <button class="btn btn--primary" type="submit">Guardar alterações</button>
      <a class="btn btn--ghost" href="alunos.php">Cancelar</a>
    </div>
  </form>
<?php endif; ?>

<form class="admin-form" method="post">
  <?= campoCsrf() ?>
  <input type="hidden" name="acao" value="guardar">

  <h2>Registar aluno</h2>
  <?php require __DIR__ . '/_campos_aluno.php'; ?>

  <button class="btn btn--primary" type="submit">Criar aluno</button>
</form>

<h2>Todos os alunos (<?= count($alunos) ?>)</h2>

<?php if (!$alunos) : ?>
  <p class="admin-nota">Ainda não há alunos. Regista um aqui acima, ou converte um pedido.</p>
<?php else : ?>
  <table class="admin-tabela">
    <thead>
      <tr>
        <th scope="col">Nome</th>
        <th scope="col">Email</th>
        <th scope="col">Escola</th>
        <th scope="col">Instrumento</th>
        <th scope="col">Estado</th>
        <th scope="col">Acções</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($alunos as $a) : ?>
      <tr>
        <td><?= e($a['nome']) ?></td>
        <td><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></td>
        <td><?= e($a['cidade']) ?></td>
        <td><?= e($a['instrumento'] ?? '—') ?></td>
        <td><span class="admin-pill admin-pill--<?= e($a['estado']) ?>"><?= e($estadosAluno[$a['estado']] ?? $a['estado']) ?></span></td>
        <td class="admin-acoes-tabela">
          <a class="btn btn--sm btn--ghost" href="alunos.php?editar=<?= (int) $a['id'] ?>">Editar</a>
          <form method="post" class="admin-forma-inline"
                onsubmit="return confirm('Apagar <?= e($a['nome']) ?> e todas as aulas dele? Esta acção não se desfaz.');">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="apagar">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn--sm btn--erro" type="submit">Apagar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php rodape(); ?>