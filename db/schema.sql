-- ==========================================================================
--  Escola de Música Harmonia — esquema da base de dados
--  MySQL 8 / MariaDB 10.5+
--
--  Aplicar:  mysql -u harmonia -p harmonia < db/schema.sql
--
--  Convenção: todas as tabelas em utf8mb4, todas as chaves estrangeiras
--  declaradas. O InnoDB é obrigatório para as chaves estrangeiras.
-- ==========================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------------
--  escolas — as três escolas, dados de referência
-- --------------------------------------------------------------------------
CREATE TABLE escolas (
  id            TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(80)  NOT NULL,
  cidade        VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  telefone      VARCHAR(40)  NOT NULL,
  whatsapp      VARCHAR(20)  NOT NULL,
  morada        VARCHAR(190) NOT NULL,
  -- Horário de funcionamento: a validação das aulas usa estes dois campos,
  -- por isso vivem na tabela e não no código.
  hora_abertura TIME NOT NULL DEFAULT '09:00:00',
  hora_fecho    TIME NOT NULL DEFAULT '20:00:00',

  UNIQUE KEY uq_escolas_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  instrumentos — guitarra, piano, voz
-- --------------------------------------------------------------------------
CREATE TABLE instrumentos (
  id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_instrumentos_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  professores
-- --------------------------------------------------------------------------
CREATE TABLE professores (
  id        SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(120) NOT NULL,
  email     VARCHAR(190) NOT NULL,
  telefone  VARCHAR(40)  NULL,
  escola_id TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_professores_escola FOREIGN KEY (escola_id)
    REFERENCES escolas (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  alunos
--  nivel e estado: os valores válidos são decididos aqui, pelo tipo ENUM.
--  O PHP valida outra vez antes de escrever, mas a base é a última linha.
-- --------------------------------------------------------------------------
CREATE TABLE alunos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  telefone       VARCHAR(40)  NULL,
  escola_id      TINYINT UNSIGNED NOT NULL,
  instrumento_id TINYINT UNSIGNED NULL,
  nivel          ENUM('iniciante','basico','intermedio','avancado') NULL,
  estado         ENUM('ativo','pausado','saiu') NOT NULL DEFAULT 'ativo',
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alunos_escola FOREIGN KEY (escola_id)
    REFERENCES escolas (id) ON DELETE RESTRICT,
  -- SET NULL: apagar um instrumento não pode apagar alunos.
  CONSTRAINT fk_alunos_instrumento FOREIGN KEY (instrumento_id)
    REFERENCES instrumentos (id) ON DELETE SET NULL,
  KEY idx_alunos_escola (escola_id),
  KEY idx_alunos_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  aulas
--  CASCADE em aluno_id: apagar um aluno leva as aulas dele, é o que se quer.
--  RESTRICT nos outros: uma escola com aulas não pode ser apagada.
-- --------------------------------------------------------------------------
CREATE TABLE aulas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  aluno_id    INT UNSIGNED NOT NULL,
  professor_id SMALLINT UNSIGNED NOT NULL,
  escola_id   TINYINT UNSIGNED NOT NULL,
  inicio      DATETIME NOT NULL,
  duracao_min TINYINT UNSIGNED NOT NULL DEFAULT 45,
  estado      ENUM('agendada','realizada','cancelada') NOT NULL DEFAULT 'agendada',
  notas       TEXT NULL,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aulas_aluno FOREIGN KEY (aluno_id)
    REFERENCES alunos (id) ON DELETE CASCADE,
  CONSTRAINT fk_aulas_professor FOREIGN KEY (professor_id)
    REFERENCES professores (id) ON DELETE RESTRICT,
  CONSTRAINT fk_aulas_escola FOREIGN KEY (escola_id)
    REFERENCES escolas (id) ON DELETE RESTRICT,
  KEY idx_aulas_inicio (inicio),
  KEY idx_aulas_professor (professor_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  pedidos — o que chega pelo formulário de contacto
--  Não é igual a aluno: um pedido ainda não é uma matrícula.
-- --------------------------------------------------------------------------
CREATE TABLE pedidos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  telefone       VARCHAR(40)  NULL,
  escola_id      TINYINT UNSIGNED NOT NULL,
  instrumento_id TINYINT UNSIGNED NULL,
  nivel          ENUM('iniciante','basico','intermedio','avancado') NULL,
  mensagem       TEXT NULL,
  estado         ENUM('novo','contactado','convertido','descartado')
                   NOT NULL DEFAULT 'novo',
  origem         VARCHAR(190) NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pedidos_escola FOREIGN KEY (escola_id)
    REFERENCES escolas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pedidos_instrumento FOREIGN KEY (instrumento_id)
    REFERENCES instrumentos (id) ON DELETE SET NULL,
  KEY idx_pedidos_estado (estado, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
--  utilizadores — acesso ao painel /admin/
--  password_hash guarda o resultado de password_hash(), nunca a senha.
-- --------------------------------------------------------------------------
CREATE TABLE utilizadores (
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nome          VARCHAR(120) NOT NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_utilizadores_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;