<?php
declare(strict_types=1);

/*
 * Agendamento de aulas.
 *
 * Uma aula só é aceite se passar em três condições, todas verificadas aqui no
 * servidor e nunca no formulário:
 *
 *   1. O professor pertence à mesma escola do aluno.
 *   2. A aula está dentro do horário de funcionamento da escola.
 *   3. O professor não tem outra aula que se sobreponha a esta.
 *
 * A sobreposição não tem restrição na base de dados porque depende de uma
 * coluna calculada (início mais a duração), coisa que uma restrição UNIQUE
 * não consegue exprimir. Se algum dia houver duas versões do PHP a escrever
 * em simultâneo, esta verificação passa a ser apenas dissuasória — num
 * servidor normal o PHP é de processo único e as secções são sequenciais.
 */

require __DIR__ . '/_bootstrap.php';

exigirLogin();

$alunos = db()->query(
    'SELECT a.id, a.nome, a.escola_id, e.cidade
       FROM alunos a JOIN escolas e ON e.id = a.escola_id
      WHERE a.estado = \'ativo\'
      ORDER BY a.nome'
)->fetchAll();

$professores = db()->query(
    'SELECT p.id, p.nome, p.escola_id, e.cidade
       FROM professores p JOIN escolas e ON e.id = p.escola_id
      ORDER BY p.nome'
)->fetchAll();

$escolas = db()->query('SELECT id, cidade, hora_abertura, hora_fecho FROM escolas ORDER BY cidade')->fetchAll();

/* ------------------------------------------------------------------ ações */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'estado' && $id > 0) {
        $novo = (string) ($_POST['novo_estado'] ?? '');
        if (in_array($novo, ['agendada', 'realizada', 'cancelada'], true)) {
            db()->prepare('UPDATE aulas SET estado = ? WHERE id = ?')->execute([$novo, $id]);
            flash('Estado da aula actualizado.');
        }
        header('Location: aulas.php');
        exit;
    }

    if ($acao === 'apagar' && $id > 0) {
        db()->prepare('DELETE FROM aulas WHERE id = ?')->execute([$id]);
        flash('Aula apagada.');
        header('Location: aulas.php');
        exit;
    }

    if ($acao === 'agendar') {
        $alunoId = (int) ($_POST['aluno_id'] ?? 0);
        $professorId = (int) ($_POST['professor_id'] ?? 0);
        $inicioTexto = trim((string) ($_POST['inicio'] ?? ''));
        $duracao = (int) ($_POST['duracao_min'] ?? 45);

        $erros = [];

        if ($duracao < 15 || $duracao > 240) {
            $erros[] = 'A duração tem de estar entre 15 e 240 minutos.';
        }

        $inicio = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $inicioTexto);

        if (!$inicio) {
            $erros[] = 'A data e a hora não estão em formato válido.';
        }

        $aluno = null;
        if ($alunoId > 0) {
            $procura = db()->prepare('SELECT a.*, e.cidade, e.hora_abertura, e.hora_fecho
                                        FROM alunos a JOIN escolas e ON e.id = a.escola_id
                                       WHERE a.id = ?');
            $procura->execute([$alunoId]);
            $aluno = $procura->fetch() ?: null;
            if (!$aluno) {
                $erros[] = 'Esse aluno não existe.';
            }
        } else {
            $erros[] = 'Escolhe o aluno.';
        }

        $professor = null;
        if ($professorId > 0) {
            $procura = db()->prepare('SELECT * FROM professores WHERE id = ?');
            $procura->execute([$professorId]);
            $professor = $procura->fetch() ?: null;
            if (!$professor) {
                $erros[] = 'Esse professor não existe.';
            }
        } else {
            $erros[] = 'Escolhe o professor.';
        }

        /* 1. O professor ensina na escola do aluno? */
        if ($aluno && $professor && (int) $professor['escola_id'] !== (int) $aluno['escola_id']) {
            $erros[] = sprintf(
                '%s não dá aulas na escola de %s.',
                $professor['nome'],
                $aluno['cidade']
            );
        }

        if ($inicio && $aluno) {
            $fim = $inicio->modify('+' . $duracao . ' minutes');

            /* 2. Dentro do horário da escola?
               Compara-se só a hora, porque a tabela escolas tem um horário
               único e não um por dia da semana. A escola de Beja abre às
               09:30, por isso uma aula às 09:00 é recusada. */
            $horaInicio = $inicio->format('H:i:s');
            $horaFim = $fim->format('H:i:s');

            if ($horaInicio < $aluno['hora_abertura'] || $horaFim > $aluno['hora_fecho']) {
                $erros[] = sprintf(
                    'A aula fica fora do horário da escola de %s (%s às %s).',
                    $aluno['cidade'],
                    substr((string) $aluno['hora_abertura'], 0, 5),
                    substr((string) $aluno['hora_fecho'], 0, 5)
                );
            }

            /* 3. Sobreposição para o mesmo professor?
               Uma aula acaba quando a seguinte começa, por isso o <= é
               propositadamente estrito: [10:00, 11:00] e [11:00, 12:00]
               podem ser as duas. */
            if ($professor) {
                /* Todos os parâmetros têm nome: o PDO está com
                   EMULATE_PREPARES desligado, e o MySQL recusa uma consulta
                   que misture marcadores ? com :nome. */
                $procura = db()->prepare(
                    "SELECT COUNT(*) FROM aulas
                      WHERE professor_id = :professor
                        AND estado <> 'cancelada'
                        AND inicio < :fim
                        AND (inicio + INTERVAL duracao_min MINUTE) > :inicio"
                );
                $procura->execute([
                    'professor' => $professorId,
                    'fim'      => $fim->format('Y-m-d H:i:s'),
                    'inicio'   => $inicio->format('Y-m-d H:i:s'),
                ]);

                if ((int) $procura->fetchColumn() > 0) {
                    $erros[] = sprintf(
                        '%s já tem outra aula nesse horário.',
                        $professor['nome']
                    );
                }
            }
        }

        if ($erros) {
            flash(implode(' ', $erros));
        } else {
            db()->prepare(
                'INSERT INTO aulas (aluno_id, professor_id, escola_id, inicio, duracao_min, notas)
                 VALUES (:aluno, :professor, :escola, :inicio, :duracao, :notas)'
            )->execute([
                'aluno'     => $alunoId,
                'professor' => $professorId,
                /* A escola vem do aluno, nunca do formulário: assim não é
                   possível marcar uma aula numa escola que não é a dele. */
                'escola'    => $aluno['escola_id'],
                'inicio'    => $inicio->format('Y-m-d H:i:s'),
                'duracao'   => $duracao,
                'notas'     => trim((string) ($_POST['notas'] ?? '')) ?: null,
            ]);

            flash('Aula agendada.');
        }

        header('Location: aulas.php');
        exit;
    }
}

/* ------------------------------------------------------------------ listagem */

$aulas = db()->query(
    "SELECT a.*, al.nome AS aluno, pr.nome AS professor, e.cidade
       FROM aulas a
       JOIN alunos al ON al.id = a.aluno_id
       JOIN professores pr ON pr.id = a.professor_id
       JOIN escolas e ON e.id = a.escola_id
      ORDER BY a.inicio DESC
      LIMIT 60"
)->fetchAll();

$estadosAula = ['agendada' => 'Agendada', 'realizada' => 'Realizada', 'cancelada' => 'Cancelada'];

/* Valor por omissão do formulário: hoje, uma hora à frente, arredondada a
   meia hora — o mínimo que falta para o utilizador carregar. */
$inicioSugerido = (new DateTimeImmutable('+1 hour'))
    ->setTime((int) date('H'), (int) date('i'), 0)
    ->modify('-' . ((int) date('i')) . ' minutes');

cabecalho('Aulas', 'aulas');
?>

<h1 class="admin-title">Aulas</h1>

<?php if (!$alunos || !$professores) : ?>
  <p class="admin-flash admin-flash--erro">
    Precisas de pelo menos um aluno activo e um professor antes de agendar.
    <a href="alunos.php">Registar aluno</a> ·
    <a href="professores.php">Registar professor</a>
  </p>
<?php endif; ?>

<form class="admin-form" method="post">
  <?= campoCsrf() ?>
  <input type="hidden" name="acao" value="agendar">

  <h2>Agendar aula</h2>

  <div class="admin-form__grelha">
    <div class="field">
      <label for="aluno_id">Aluno <span aria-hidden="true">*</span></label>
      <select id="aluno_id" name="aluno_id" required>
        <?php foreach ($alunos as $a) : ?>
          <option value="<?= (int) $a['id'] ?>"><?= e($a['nome']) ?> — <?= e($a['cidade']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="professor_id">Professor <span aria-hidden="true">*</span></label>
      <select id="professor_id" name="professor_id" required>
        <?php foreach ($professores as $p) : ?>
          <option value="<?= (int) $p['id'] ?>"><?= e($p['nome']) ?> — <?= e($p['cidade']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="inicio">Data e hora <span aria-hidden="true">*</span></label>
      <input type="datetime-local" id="inicio" name="inicio" required
             value="<?= e($inicioSugerido->format('Y-m-d\TH:i')) ?>">
    </div>

    <div class="field">
      <label for="duracao_min">Duração (minutos)</label>
      <input type="number" id="duracao_min" name="duracao_min" value="45" min="15" max="240" step="5">
    </div>
  </div>

  <div class="field">
    <label for="notas">Notas</label>
    <input type="text" id="notas" name="notas" maxlength="500" placeholder="O que preparar, material a trazer.">
  </div>

  <button class="btn btn--primary" type="submit">Agendar</button>
</form>

<h2>Últimas aulas</h2>

<?php if (!$aulas) : ?>
  <p class="admin-nota">Ainda não há aulas agendadas.</p>
<?php else : ?>
  <table class="admin-tabela">
    <thead>
      <tr>
        <th scope="col">Quando</th>
        <th scope="col">Aluno</th>
        <th scope="col">Professor</th>
        <th scope="col">Escola</th>
        <th scope="col">Estado</th>
        <th scope="col">Acções</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($aulas as $a) : ?>
      <tr>
        <td><?= e(date('d/m/Y H:i', strtotime((string) $a['inicio']))) ?></td>
        <td><?= e($a['aluno']) ?></td>
        <td><?= e($a['professor']) ?></td>
        <td><?= e($a['cidade']) ?></td>
        <td><span class="admin-pill admin-pill--<?= e($a['estado']) ?>"><?= e($estadosAula[$a['estado']] ?? $a['estado']) ?></span></td>
        <td class="admin-acoes-tabela">
          <form method="post" class="admin-forma-inline">
            <?= campoCsrf() ?>
            <input type="hidden" name="acao" value="estado">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <label class="visually-hidden" for="estado_<?= (int) $a['id'] ?>">Estado da aula <?= (int) $a['id'] ?></label>
            <select id="estado_<?= (int) $a['id'] ?>" name="novo_estado">
              <?php foreach ($estadosAula as $valor => $texto) : ?>
                <option value="<?= e($valor) ?>"<?= $valor === $a['estado'] ? ' selected' : '' ?>><?= e($texto) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn--sm" type="submit">Guardar</button>
          </form>
          <form method="post" class="admin-forma-inline"
                onsubmit="return confirm('Apagar esta aula?');">
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