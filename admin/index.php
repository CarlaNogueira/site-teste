<?php
declare(strict_types=1);

/*
 * Painel: o resumo do dia.
 */

require __DIR__ . '/_bootstrap.php';

exigirLogin();

$novos = (int) db()->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'novo'")->fetchColumn();
$alunosAtivos = (int) db()->query("SELECT COUNT(*) FROM alunos WHERE estado = 'ativo'")->fetchColumn();

/* Comparar com a data de hoje no fuso do servidor. A aula é "de hoje" se
   cair entre as 00:00 e as 23:59:59 de hoje. */
$aulasHoje = (int) db()->query(
    "SELECT COUNT(*) FROM aulas WHERE estado = 'agendada'
       AND inicio >= CURDATE() AND inicio < CURDATE() + INTERVAL 1 DAY"
)->fetchColumn();

/* Os dez pedidos mais recentes. Os pedidos que já foram tratados entram
   na mesma lista, ordenados pela data; o estado é que distingue. */
$pedidos = db()->query(
    'SELECT p.id, p.nome, p.email, p.escola_id, p.estado, p.criado_em, e.cidade
       FROM pedidos p
       JOIN escolas e ON e.id = p.escola_id
      ORDER BY p.criado_em DESC
      LIMIT 10'
)->fetchAll();

$aulas = db()->query(
    "SELECT a.id, a.inicio, a.duracao_min, a.estado,
            al.nome AS aluno, pr.nome AS professor, e.cidade
       FROM aulas a
       JOIN alunos al ON al.id = a.aluno_id
       JOIN professores pr ON pr.id = a.professor_id
       JOIN escolas e ON e.id = a.escola_id
      WHERE a.inicio >= CURDATE() AND a.inicio < CURDATE() + INTERVAL 1 DAY
      ORDER BY a.inicio"
)->fetchAll();

$estados = ['novo' => 'Novo', 'contactado' => 'Contactado', 'convertido' => 'Convertido', 'descartado' => 'Descartado'];

cabecalho('Painel', 'inicio');
?>

<h1 class="admin-title">Painel</h1>

<div class="admin-stats">
  <a class="admin-stat" href="pedidos.php?estado=novo">
    <span class="admin-stat__numero"><?= $novos ?></span>
    <span class="admin-stat__legenda">pedidos por responder</span>
  </a>
  <a class="admin-stat" href="alunos.php">
    <span class="admin-stat__numero"><?= $alunosAtivos ?></span>
    <span class="admin-stat__legenda">alunos activos</span>
  </a>
  <a class="admin-stat" href="aulas.php">
    <span class="admin-stat__numero"><?= $aulasHoje ?></span>
    <span class="admin-stat__legenda">aulas hoje</span>
  </a>
</div>

<section class="admin-seccao">
  <h2>Pedidos recentes</h2>

  <?php if (!$pedidos) : ?>
    <p class="admin-nota">Ainda não entrou nenhum pedido.</p>
  <?php else : ?>
    <table class="admin-tabela">
      <thead>
        <tr>
          <th scope="col">Quando</th>
          <th scope="col">Nome</th>
          <th scope="col">Email</th>
          <th scope="col">Escola</th>
          <th scope="col">Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pedidos as $p) : ?>
        <tr>
          <td><?= e(date('d/m H:i', strtotime((string) $p['criado_em']))) ?></td>
          <td>
            <a href="pedidos.php#pedido-<?= (int) $p['id'] ?>"><?= e($p['nome']) ?></a>
          </td>
          <td><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></td>
          <td><?= e($p['cidade']) ?></td>
          <td><span class="admin-pill admin-pill--<?= e($p['estado']) ?>"><?= e($estados[$p['estado']] ?? $p['estado']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="admin-seccao">
  <h2>Aulas de hoje</h2>

  <?php if (!$aulas) : ?>
    <p class="admin-nota">Não há aulas agendadas para hoje.</p>
  <?php else : ?>
    <table class="admin-tabela">
      <thead>
        <tr>
          <th scope="col">Hora</th>
          <th scope="col">Aluno</th>
          <th scope="col">Professor</th>
          <th scope="col">Escola</th>
          <th scope="col">Duração</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($aulas as $a) : ?>
        <tr>
          <td><?= e(date('H:i', strtotime((string) $a['inicio']))) ?></td>
          <td><?= e($a['aluno']) ?></td>
          <td><?= e($a['professor']) ?></td>
          <td><?= e($a['cidade']) ?></td>
          <td><?= (int) $a['duracao_min'] ?> min</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php rodape(); ?>