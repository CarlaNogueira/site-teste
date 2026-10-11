<?php
declare(strict_types=1);

/*
 * Pedidos de aula experimental.
 *
 * Cada pedido pode mudar de estado e ser convertido em aluno. A conversão
 * copia os campos para a tabela alunos e marca o pedido como convertido: o
 * registo original do contacto é preservado, que é o que permite responder
 * ao pedido inicial meses depois.
 */

require __DIR__ . '/_bootstrap.php';

exigirLogin();

/* ------------------------------------------------------------------ ações */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($acao === 'estado') {
            $novo = (string) ($_POST['novo_estado'] ?? '');
            $permitidos = ['novo', 'contactado', 'descartado'];

            if (in_array($novo, $permitidos, true)) {
                db()->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$novo, $id]);
                flash('Estado do pedido actualizado.');
            }
        }

        if ($acao === 'converter') {
            $stmt = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
            $stmt->execute([$id]);
            $pedido = $stmt->fetch();

            if (!$pedido) {
                flash('Esse pedido já não existe.');
            } elseif ($pedido['estado'] === 'convertido') {
                flash('Este pedido já tinha sido convertido.');
            } else {
                /* Os campos do aluno são os mesmos do pedido. Copiar com um
                   INSERT mantém o pedido intacto e auditável. */
                db()->beginTransaction();

                try {
                    $novoAluno = db()->prepare(
                        'INSERT INTO alunos (nome, email, telefone, escola_id, instrumento_id, nivel)
                         VALUES (:nome, :email, :telefone, :escola, :instrumento, :nivel)'
                    );

                    $novoAluno->execute([
                        'nome'        => $pedido['nome'],
                        'email'       => $pedido['email'],
                        'telefone'    => $pedido['telefone'],
                        'escola'      => $pedido['escola_id'],
                        'instrumento' => $pedido['instrumento_id'],
                        'nivel'       => $pedido['nivel'],
                    ]);

                    $novoId = (int) db()->lastInsertId();

                    db()->prepare("UPDATE pedidos SET estado = 'convertido' WHERE id = ?")
                        ->execute([$id]);

                    /* A transacção garante que ou os dois passos acontecem ou
                       nenhum: nunca um aluno sem pedido marcado como convertido,
                       nem um pedido marcado sem aluno. */
                    db()->commit();

                    flash("Pedido convertido. Aluno nº $novoId criado.");
                } catch (Throwable $e) {
                    db()->rollBack();
                    error_log('[harmonia] falha ao converter pedido: ' . $e->getMessage());
                    flash('Não foi possível converter o pedido.');
                }
            }
        }
    }

    /* Depois de escrever, voltamos ao GET. Sem isto, recarregar a página
       repetiria a acção. */
    header('Location: pedidos.php' . (isset($_POST['estado']) ? '?estado=' . urlencode((string) $_POST['estado']) : ''));
    exit;
}

/* ------------------------------------------------------------------ listagem */

$filtro = (string) ($_GET['estado'] ?? 'todos');
$estados = ['novo', 'contactado', 'convertido', 'descartado'];

if (!in_array($filtro, array_merge(['todos'], $estados), true)) {
    $filtro = 'todos';
}

$sql = 'SELECT p.*, e.cidade, i.nome AS instrumento
          FROM pedidos p
          JOIN escolas e ON e.id = p.escola_id
     LEFT JOIN instrumentos i ON i.id = p.instrumento_id';

if ($filtro !== 'todos') {
    $sql .= ' WHERE p.estado = ?';
}

$sql .= ' ORDER BY p.criado_em DESC';

$stmt = db()->prepare($sql);
$stmt->execute($filtro === 'todos' ? [] : [$filtro]);
$pedidos = $stmt->fetchAll();

$rotulos = ['novo' => 'Novo', 'contactado' => 'Contactado', 'convertido' => 'Convertido', 'descartado' => 'Descartado'];
$niveis = ['iniciante' => 'Iniciante', 'basico' => 'Básico', 'intermedio' => 'Intermédio', 'avancado' => 'Avançado'];

cabecalho('Pedidos', 'pedidos');
?>

<h1 class="admin-title">Pedidos</h1>

<nav class="admin-filtros" aria-label="Filtrar pedidos por estado">
  <a class="admin-filtro<?= $filtro === 'todos' ? ' admin-filtro--activo' : '' ?>" href="pedidos.php">Todos</a>
  <?php foreach ($rotulos as $chave => $texto) : ?>
    <a class="admin-filtro<?= $filtro === $chave ? ' admin-filtro--activo' : '' ?>" href="pedidos.php?estado=<?= e($chave) ?>"><?= e($texto) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$pedidos) : ?>
  <p class="admin-nota">Nenhum pedido com este estado.</p>
<?php endif; ?>

<?php foreach ($pedidos as $p) : ?>
  <article class="admin-pedido" id="pedido-<?= (int) $p['id'] ?>">
    <header class="admin-pedido__cabecalho">
      <h2><?= e($p['nome']) ?></h2>
      <span class="admin-pill admin-pill--<?= e($p['estado']) ?>"><?= e($rotulos[$p['estado']] ?? $p['estado']) ?></span>
    </header>

    <dl class="admin-dados">
      <div><dt>Email</dt><dd><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></dd></div>
      <?php if ($p['telefone']) : ?>
        <div><dt>Telefone</dt><dd><?= e($p['telefone']) ?></dd></div>
      <?php endif; ?>
      <div><dt>Escola</dt><dd><?= e($p['cidade']) ?></dd></div>
      <?php if ($p['instrumento']) : ?>
        <div><dt>Instrumento</dt><dd><?= e($p['instrumento']) ?></dd></div>
      <?php endif; ?>
      <?php if ($p['nivel']) : ?>
        <div><dt>Nível</dt><dd><?= e($niveis[$p['nivel']] ?? $p['nivel']) ?></dd></div>
      <?php endif; ?>
      <div><dt>Recebido</dt><dd><?= e(date('d/m/Y H:i', strtotime((string) $p['criado_em']))) ?></dd></div>
    </dl>

    <?php if ($p['mensagem']) : ?>
      <p class="admin-pedido__mensagem"><?= nl2br(e($p['mensagem'])) ?></p>
    <?php endif; ?>

    <div class="admin-acoes">
      <form method="post" class="admin-forma-inline">
        <?= campoCsrf() ?>
        <input type="hidden" name="acao" value="estado">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <label class="visually-hidden" for="novo_estado_<?= (int) $p['id'] ?>">Mudar estado do pedido <?= (int) $p['id'] ?></label>
        <select id="novo_estado_<?= (int) $p['id'] ?>" name="novo_estado">
          <?php foreach ($rotulos as $chave => $texto) : ?>
            <option value="<?= e($chave) ?>"<?= $chave === $p['estado'] ? ' selected' : '' ?>><?= e($texto) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--sm" type="submit">Guardar estado</button>
      </form>

      <?php if ($p['estado'] !== 'convertido') : ?>
        <form method="post" class="admin-forma-inline">
          <?= campoCsrf() ?>
          <input type="hidden" name="acao" value="converter">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn btn--sm btn--primary" type="submit">Converter em aluno</button>
        </form>
      <?php else : ?>
        <p class="admin-nota">Já convertido em aluno.</p>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>

<?php rodape(); ?>