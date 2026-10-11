<?php
declare(strict_types=1);

/*
 * Campos do formulário de professor. Partial partilhado entre o formulário
 * de edição e o de criação em professores.php.
 *
 * Espera: $professor (valores actuais) e $escolas (lista de escolas).
 */
?>

<div class="admin-form__grelha">
  <div class="field">
    <label for="nome">Nome <span aria-hidden="true">*</span></label>
    <input type="text" id="nome" name="nome" required maxlength="120"
           value="<?= e($professor['nome']) ?>">
  </div>

  <div class="field">
    <label for="email">Email <span aria-hidden="true">*</span></label>
    <input type="email" id="email" name="email" required maxlength="190"
           value="<?= e($professor['email']) ?>">
  </div>

  <div class="field">
    <label for="telefone">Telefone</label>
    <input type="tel" id="telefone" name="telefone" maxlength="40"
           value="<?= e($professor['telefone']) ?>">
  </div>

  <div class="field">
    <label for="escola_id">Escola <span aria-hidden="true">*</span></label>
    <select id="escola_id" name="escola_id" required>
      <?php foreach ($escolas as $esc) : ?>
        <option value="<?= (int) $esc['id'] ?>"<?= (string) $esc['id'] === (string) $professor['escola_id'] ? ' selected' : '' ?>>
          <?= e($esc['cidade']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>