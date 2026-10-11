<?php
declare(strict_types=1);

/*
 * Campos do formulário de aluno. Usado duas vezes em alunos.php — para
 * editar e para criar — por isso é um partial.
 *
 * Espera variáveis do ficheiro que o inclui:
 *   $aluno        valores actuais
 *   $escolas      lista de escolas
 *   $instrumentos lista de instrumentos
 *   $niveis       mapa slug => texto
 *   $estadosAluno mapa valor => texto
 *
 * Todos os valores passam por e() na saída: um aluno chamado
 * <script>alert(1)</script> tem de aparecer como texto, não como código.
 */
?>

<div class="admin-form__grelha">
  <div class="field">
    <label for="nome">Nome <span aria-hidden="true">*</span></label>
    <input type="text" id="nome" name="nome" required maxlength="120"
           value="<?= e($aluno['nome']) ?>">
  </div>

  <div class="field">
    <label for="email">Email <span aria-hidden="true">*</span></label>
    <input type="email" id="email" name="email" required maxlength="190"
           value="<?= e($aluno['email']) ?>">
  </div>

  <div class="field">
    <label for="telefone">Telefone</label>
    <input type="tel" id="telefone" name="telefone" maxlength="40"
           value="<?= e($aluno['telefone']) ?>">
  </div>

  <div class="field">
    <label for="escola_id">Escola <span aria-hidden="true">*</span></label>
    <select id="escola_id" name="escola_id" required>
      <?php foreach ($escolas as $esc) : ?>
        <option value="<?= (int) $esc['id'] ?>"<?= (string) $esc['id'] === (string) $aluno['escola_id'] ? ' selected' : '' ?>>
          <?= e($esc['cidade']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="instrumento">Instrumento</label>
    <select id="instrumento" name="instrumento">
      <option value="">Ainda não sei</option>
      <?php foreach ($instrumentos as $inst) : ?>
        <option value="<?= e($inst['nome']) ?>"<?= $inst['nome'] === $aluno['instrumento_slug'] ? ' selected' : '' ?>>
          <?= e($inst['nome']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="nivel">Nível</label>
    <select id="nivel" name="nivel">
      <?php foreach ($niveis as $valor => $texto) : ?>
        <option value="<?= e($valor) ?>"<?= $valor === $aluno['nivel'] ? ' selected' : '' ?>>
          <?= e($texto) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="estado">Estado</label>
    <select id="estado" name="estado">
      <?php foreach ($estadosAluno as $valor => $texto) : ?>
        <option value="<?= e($valor) ?>"<?= $valor === $aluno['estado'] ? ' selected' : '' ?>>
          <?= e($texto) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>