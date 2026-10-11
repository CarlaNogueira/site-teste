<?php
declare(strict_types=1);

/*
 * Professores.
 *
 * Uma aula precisa de um professor, e um professor pertence a uma escola:
 * a validação em aulas.php recusa uma combinação que não faça sentido. Por
 * isso esta página é pequena — registar, editar, apagar.
 */

require __DIR__ . '/_bootstrap.php';

exigirLogin();

$escolas = db()->query('SELECT id, cidade FROM escolas ORDER BY cidade')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    $nome = trim((string) ($_POST['nome'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $telefone = trim((string) ($_POST['telefone'] ?? ''));
    $escolaId = (int) ($_POST['escola_id'] ?? 0);

    if ($acao === 'apagar' && $id > 0) {
        /* O esquema tem ON DELETE RESTRICT: se o professor tiver aulas, a
           base recusa o apagamento. É o comportamento certo — apagar o
           professor deixaria aulas órfãs de nome. */
        try {
            db()->prepare('DELETE FROM professores WHERE id = ?')->execute([$id]);
            flash('Professor apagado.');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash('Não é possível apagar: este professor tem aulas marcadas.');
            } else {
                throw $ex;
            }
        }

        header('Location: professores.php');
        exit;
    }

    if ($acao === 'guardar') {
        if ($nome === '') {
            flash('O professor precisa de um nome.');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            flash('Esse email não parece válido.');
        } elseif ($escolaId <= 0) {
            flash('Escolhe a escola onde o professor dá aulas.');
        } elseif ($id > 0) {
            db()->prepare(
                'UPDATE professores SET nome = :nome, email = :email,
                        telefone = :telefone, escola_id = :escola
                  WHERE id = :id'
            )->execute([
                'nome'     => $nome,
                'email'    => $email,
                'telefone' => $telefone !== '' ? $telefone : null,
                'escola'   => $escolaId,
                'id'       => $id,
            ]);

            flash('Professor actualizado.');
        } else {
            db()->prepare(
                'INSERT INTO professores (nome, email, telefone, escola_id)
                 VALUES (:nome, :email, :telefone, :escola)'
            )->execute([
                'nome'     => $nome,
                'email'    => $email,
                'telefone' => $telefone !== '' ? $telefone : null,
                'escola'   => $escolaId,
            ]);

            flash('Professor registado.');
        }

        header('Location: professores.php');
        exit;
    }
}

$emEdicao = null;
if (isset($_GET['editar'])) {
    $procura = db()->prepare('SELECT * FROM professores WHERE id = ?');
    $procura->execute([(int) $_GET['editar']]);
    $emEdicao = $procura->fetch() ?: null;
}

$professor = $emEdicao ?: [
    'nome'      => '',
    'email'     => '',
    'telefone'  => '',
    'escola_id' => '',
];

$professores = db()->query(
    'SELECT p.*, e.cidade, (SELECT COUNT(*) FROM aulas a WHERE a.professor_id = p.id) AS n_aulas
       FROM professores p JOIN escolas e ON e.id = p.escola_id
      ORDER BY p.nome'
)->fetchAll();

cabecalho('Professores', 'professores');
?>

<h1 class="admin-title">Professores</h1>

<?php if ($emEdicao) : ?>
  <form class="admin-form" method="post">
    <?= campoCsrf() ?>
    <input type="hidden" name="acao" value="guardar">
    <input type="hidden" name="id" value="<?= (int) $emEdicao['id'] ?>">

    <h2>A editar <?= e($emEdicao['nome']) ?></h2>
    <?php require __DIR__ . '/_campos_professor.php'; ?>

    <div class="cluster">
      <button class="btn btn--primary" type="submit">Guardar alterações</button>
      <a class="btn btn--ghost" href="professores.php">Cancelar</a>
    </div>
  </form>
<?php endif; ?>

<form class="admin-form" method="post">
  <?= campoCsrf() ?>
  <input type="hidden" name="acao" value="guardar">

  <h2>Registar professor</h2>
  <?php require __DIR__ . '/_campos_professor.php'; ?>

  <button class="btn btn--primary" type="submit">Criar professor</button>
</form>

<h2>Todos os professores (<?= count($professores) ?>)</h2>

<?php if (!$professores) : ?>
  <p class="admin-nota">Ainda não há professores. Regista um acima.</p>
<?php else : ?>
  <table class="admin-tabela">
    <thead>
      <tr>
        <th scope="col">Nome</th>
        <th scope="col">Email</th>
        <th scope="col">Escola</th>
        <th scope="col">Aulas</th>
        <th scope="col">Acções</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($professores as $p) : ?>
      <tr>
        <td><?= e($p['nome']) ?></td>
        <td><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></td>
        <td><?= e($p['cidade']) ?></td>
        <td><?= (int) $p['n_aulas'] ?></td>
        <td class="admin-acoes-tabela">
          <a class="btn btn--sm btn--ghost" href="professores.php?editar=<?= (int) $p['id'] ?>">Editar</a>
          <form method="post" class="admin-forma-inline"
                onsubmit="return confirm('Apagar <?= e($p['nome']) ?>');">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="apagar">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn btn--sm btn--erro" type="submit">Apagar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php rodape(); ?>