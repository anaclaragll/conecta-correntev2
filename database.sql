CREATE DATABASE IF NOT EXISTS conecta_corrente CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE conecta_corrente;


CREATE TABLE IF NOT EXISTS estabelecimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    descricao TEXT,
    endereco VARCHAR(255) NOT NULL,
    latitude DECIMAL(10, 8) NULL DEFAULT NULL,
    longitude DECIMAL(11, 8) NULL DEFAULT NULL,
    whatsapp VARCHAR(20) NOT NULL,
    imagem_url VARCHAR(500) DEFAULT NULL,
    galeria_fotos TEXT DEFAULT NULL, 
    horario_funcionamento VARCHAR(100) DEFAULT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS avaliacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estabelecimento_id INT NOT NULL,
    nome_usuario VARCHAR(100) NOT NULL,
    nota INT CHECK (nota >= 1 AND nota <= 5),
    comentario TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (estabelecimento_id) REFERENCES estabelecimentos(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    categoria VARCHAR(50) DEFAULT 'Cultura',
    descricao TEXT,
    data_evento DATE NOT NULL,
    local VARCHAR(150) NOT NULL,
    imagem_url VARCHAR(500) DEFAULT NULL,
    galeria_fotos TEXT DEFAULT NULL,
    latitude DECIMAL(10, 8) NULL DEFAULT NULL,
    longitude DECIMAL(11, 8) NULL DEFAULT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
