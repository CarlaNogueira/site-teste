-- ==========================================================================
--  Escola de Música Harmonia — dados iniciais
--  Aplicar depois de schema.sql:
--    mysql -u harmonia -p harmonia < db/seed.sql
--
--  Estes dados são fictícios (ver README, secção "Dados fictícios") e têm de
--  coincidir com os que aparecem no HTML: tools/check_site.php verifica a
--  coerência das moradas, telefones e emails entre o llms.txt e as páginas.
--  Se mudares um telefone aqui, muda-o também no site.
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
--  Escolas
--
--  hora_abertura e hora_fecho são a envolvente dos horários reais, que são
--  por dia da semana: Segunda a sexta 14h-21h e sábado 9h-13h em Lisboa e
--  Porto; Segunda a sexta 14h-20h e sábado 9h30-13h em Beja. Dois campos
--  não expressam isso, por isso a validação das aulas usa só a envolvente.
--  O horário completo continua a ser o que está escrito no contactos.html.
-- --------------------------------------------------------------------------
INSERT INTO escolas (nome, cidade, email, telefone, whatsapp, morada, hora_abertura, hora_fecho) VALUES
  ('Escola de Música Harmonia Lisboa', 'Lisboa', 'lisboa@harmonia.pt',
   '+351 21 000 0111', '351210000111', 'Rua Augusta 120, 1100-053 Lisboa',
   '09:00:00', '21:00:00'),
  ('Escola de Música Harmonia Porto', 'Porto', 'porto@harmonia.pt',
   '+351 22 000 0222', '351220000222', 'Rua de Cedofeita 250, 4050-174 Porto',
   '09:00:00', '21:00:00'),
  ('Escola de Música Harmonia Beja', 'Beja', 'beja@harmonia.pt',
   '+351 26 000 0333', '351260000333', 'Rua Comenda Rui Rafael 12, 7800-000 Beja',
   '09:30:00', '20:00:00');

-- --------------------------------------------------------------------------
--  Instrumentos
--  O slug é o valor que vai no formulário e no HTML.
-- --------------------------------------------------------------------------
INSERT INTO instrumentos (nome, slug) VALUES
  ('Guitarra', 'guitarra'),
  ('Piano', 'piano'),
  ('Voz', 'voz');