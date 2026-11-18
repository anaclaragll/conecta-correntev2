<?php
session_start();

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

require_once 'conexao.php';
require_once 'image_helpers.php';

$msg_est = '';
$msg_ev = '';
$erro_est = '';
$erro_ev = '';
$erro_admin = '';

function selecionarImagemPrincipal(array $imagens, $indice)
{
    if (!$imagens) {
        return [];
    }

    $indice = filter_var($indice, FILTER_VALIDATE_INT);
    if ($indice === false || $indice < 0 || $indice >= count($imagens)) {
        throw new RuntimeException('Selecione uma das imagens enviadas como imagem principal.');
    }

    $principal = $imagens[$indice];
    unset($imagens[$indice]);
    array_unshift($imagens, $principal);
    return array_values($imagens);
}

function salvarGaleria(PDO $pdo, $tipo, $id, array $imagens)
{
    $config = [
        'estabelecimentos' => ['tabela' => 'estabelecimentos'],
        'eventos' => ['tabela' => 'eventos']
    ];
    if (!isset($config[$tipo])) {
        throw new RuntimeException('Tipo de cadastro inválido.');
    }

    $colunas = galleryColumnsFromImages($imagens);
    $sql = "UPDATE {$config[$tipo]['tabela']} SET imagem_url = ?, galeria_fotos = ? WHERE id = ?";
    $pdo->prepare($sql)->execute([$colunas['imagem_url'], $colunas['galeria_fotos'], $id]);
}

function apagarUploads(array $imagens, $tipo)
{
    foreach ($imagens as $imagem) {
        $path = managedUploadPath($imagem, $tipo);
        if ($path !== null && !@unlink($path)) {
            throw new RuntimeException('Não foi possível apagar um arquivo de imagem.');
        }
    }
}

function validarCoordenadas($latitude, $longitude)
{
    if ((!is_string($latitude) && !is_int($latitude) && !is_float($latitude))
        || (!is_string($longitude) && !is_int($longitude) && !is_float($longitude))) {
        return null;
    }

    $latitude = filter_var(trim((string) $latitude), FILTER_VALIDATE_FLOAT);
    $longitude = filter_var(trim((string) $longitude), FILTER_VALIDATE_FLOAT);

    if ($latitude === false || $longitude === false
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180) {
        return null;
    }

    return [$latitude, $longitude];
}

function coordenadasPadrao($latitude, $longitude)
{
    return $latitude !== null && $longitude !== null
        && abs((float) $latitude - (-10.4431)) < 0.0000001
        && abs((float) $longitude - (-45.1611)) < 0.0000001;
}

function necessitaRevisaoLocalizacao($latitude, $longitude)
{
    return validarCoordenadas($latitude, $longitude) === null
        || coordenadasPadrao($latitude, $longitude);
}

if (isset($_GET['geocode'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $queryValue = $_GET['q'] ?? '';
    if (!is_string($queryValue)) {
        http_response_code(400);
        echo json_encode(['error' => 'Endereço de busca inválido.']);
        exit;
    }

    $query = trim($queryValue);
    if ($query === '' || strlen($query) > 300) {
        http_response_code(400);
        echo json_encode(['error' => 'Informe um endereço com até 300 caracteres.']);
        exit;
    }

    $cacheKey = hash('sha256', strtolower($query));
    $cache = $_SESSION['geocode_cache'][$cacheKey] ?? null;
    if (is_array($cache) && ($cache['expires'] ?? 0) > time()) {
        echo json_encode($cache['results'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!function_exists('curl_init')) {
        http_response_code(503);
        echo json_encode(['error' => 'A busca de endereços requer a extensão cURL do PHP.']);
        exit;
    }

    $lastRequest = (float) ($_SESSION['last_geocode_request'] ?? 0);
    $waitMicroseconds = 1000000 - (int) ((microtime(true) - $lastRequest) * 1000000);
    if ($waitMicroseconds > 0) {
        usleep($waitMicroseconds);
    }
    $_SESSION['last_geocode_request'] = microtime(true);

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'countrycodes' => 'br',
        'limit' => 5,
        'accept-language' => 'pt-BR',
        'viewbox' => '-45.4908838,-10.1282674,-44.794,-10.655',
        'bounded' => 1,
        'q' => $query . ', Corrente, Piauí, Brasil'
    ]);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'ConectaCorrente/1.0 (https://github.com/anaclaragll/conecta-correntev2)',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    $results = is_string($response) ? json_decode($response, true) : null;
    if ($response === false || $status !== 200 || !is_array($results)) {
        http_response_code(502);
        echo json_encode([
            'error' => $curlError !== ''
                ? 'Não foi possível consultar a busca de endereços. Tente novamente ou marque o ponto manualmente.'
                : 'O serviço de busca de endereços está indisponível. Tente novamente ou marque o ponto manualmente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['geocode_cache'][$cacheKey] = [
        'expires' => time() + 86400,
        'results' => $results
    ];
    if (count($_SESSION['geocode_cache']) > 30) {
        $_SESSION['geocode_cache'] = [$cacheKey => $_SESSION['geocode_cache'][$cacheKey]];
    }

    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['removido'])) {
    $msg_est = 'Estabelecimento removido com sucesso.';
}

if (isset($_GET['estabelecimento_salvo'])) {
    $msg_est = 'Estabelecimento cadastrado com sucesso.';
}

if (isset($_GET['evento_salvo'])) {
    $msg_ev = 'Evento publicado com sucesso.';
}

if (isset($_GET['imagem_removida'])) {
    $msg_admin = "Imagem removida com sucesso.";
} elseif (isset($_GET['localizacao_salva'])) {
    $msg_admin = 'Localização atualizada com sucesso.';
} else {
    $msg_admin = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir'])) {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        $erro_admin = 'Cadastro inválido.';
    } else {
        $stmt = $pdo->prepare("SELECT imagem_url, galeria_fotos FROM estabelecimentos WHERE id = ?");
        $stmt->execute([$id]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$registro) {
            $erro_admin = 'Estabelecimento não encontrado.';
        } else {
            $imagens = galleryImagesFromStorage($registro['imagem_url'], $registro['galeria_fotos']);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM avaliacoes WHERE estabelecimento_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM estabelecimentos WHERE id = ?")->execute([$id]);
                $pdo->commit();
                apagarUploads($imagens, 'estabelecimentos');
                header("Location: admin.php?removido=1");
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_imagem'])) {
    $tipo = $_POST['tipo'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $config = [
        'estabelecimentos' => 'estabelecimentos',
        'eventos' => 'eventos'
    ];

    if (!is_string($tipo) || !isset($config[$tipo]) || $id === false || $id < 1) {
        $erro_admin = 'Imagem ou cadastro inválido.';
    } else {
        $table = $config[$tipo];
        $stmt = $pdo->prepare("SELECT imagem_url, galeria_fotos FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        $imagemSolicitada = $_POST['imagem'] ?? '';
        $imagens = $registro
            ? galleryImagesFromStorage($registro['imagem_url'], $registro['galeria_fotos'])
            : [];
        $indice = array_search($imagemSolicitada, $imagens, true);

        if (!$registro || $indice === false) {
            $erro_admin = 'Imagem não encontrada ou já removida.';
        } else {
            unset($imagens[$indice]);
            $imagens = array_values($imagens);
            salvarGaleria($pdo, $table, $id, $imagens);

            if (managedUploadPath($imagemSolicitada, $tipo) !== null
                && !deleteManagedUpload($imagemSolicitada, $tipo)) {
                $erro_admin = 'A imagem saiu do cadastro, mas não foi possível apagar o arquivo.';
            } else {
                header("Location: admin.php?imagem_removida=1");
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_localizacao'])) {
    $tipo = $_POST['tipo'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $config = [
        'estabelecimentos' => 'estabelecimentos',
        'eventos' => 'eventos'
    ];
    $coordenadas = validarCoordenadas($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');

    if (!is_string($tipo) || !isset($config[$tipo]) || $id === false || $id < 1) {
        $erro_admin = 'Cadastro inválido para atualizar a localização.';
    } elseif ($coordenadas === null) {
        $erro_admin = 'Informe uma latitude entre -90 e 90 e uma longitude entre -180 e 180.';
    } else {
        $table = $config[$tipo];
        $stmt = $pdo->prepare("UPDATE {$table} SET latitude = ?, longitude = ? WHERE id = ?");
        $stmt->execute([$coordenadas[0], $coordenadas[1], $id]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                $erro_admin = 'Cadastro não encontrado.';
            } else {
                header("Location: admin.php?localizacao_salva=1");
                exit;
            }
        } else {
            header("Location: admin.php?localizacao_salva=1");
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_estabelecimento'])) {
    $nome = trim($_POST['nome'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $horario = trim($_POST['horario_funcionamento'] ?? '');
    $coordenadas = validarCoordenadas($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');
    $imagens = [];

    if ($nome === '' || $categoria === '' || $endereco === '' || $whatsapp === '') {
        $erro_est = 'Preencha nome, categoria, endereço e WhatsApp.';
    } elseif ($coordenadas === null) {
        $erro_est = 'Marque a localização no mapa ou informe coordenadas válidas antes de salvar.';
    } else {
        [$latitude, $longitude] = $coordenadas;
        try {
            $imagens = selecionarImagemPrincipal(
                storeUploadedImages('imagens', 'estabelecimentos'),
                $_POST['imagem_principal'] ?? 0
            );
            $colunas = galleryColumnsFromImages($imagens);
            try {
                $stmt = $pdo->prepare("INSERT INTO estabelecimentos (nome, categoria, descricao, endereco, whatsapp, imagem_url, galeria_fotos, horario_funcionamento, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $nome, $categoria, $descricao, $endereco, $whatsapp,
                    $colunas['imagem_url'], $colunas['galeria_fotos'], $horario, $latitude, $longitude
                ]);
                header("Location: admin.php?estabelecimento_salvo=1");
                exit;
            } catch (Throwable $exception) {
                apagarUploads($imagens, 'estabelecimentos');
                throw $exception;
            }
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $erro_est = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_evento'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $local = trim($_POST['local'] ?? '');
    $dataEvento = trim($_POST['data_evento'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $coordenadas = validarCoordenadas($_POST['latitude'] ?? '', $_POST['longitude'] ?? '');
    $imagens = [];

    if ($titulo === '' || $local === '' || $dataEvento === '') {
        $erro_ev = 'Preencha o título, local e data do evento.';
    } elseif ($coordenadas === null) {
        $erro_ev = 'Marque a localização no mapa ou informe coordenadas válidas antes de salvar.';
    } else {
        [$latitude, $longitude] = $coordenadas;
        try {
            $imagens = selecionarImagemPrincipal(
                storeUploadedImages('imagens', 'eventos'),
                $_POST['imagem_principal'] ?? 0
            );
            $colunas = galleryColumnsFromImages($imagens);
            try {
                $stmt = $pdo->prepare("INSERT INTO eventos (titulo, local, data_evento, descricao, imagem_url, galeria_fotos, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $titulo, $local, $dataEvento, $descricao,
                    $colunas['imagem_url'], $colunas['galeria_fotos'], $latitude, $longitude
                ]);
                header("Location: admin.php?evento_salvo=1");
                exit;
            } catch (Throwable $exception) {
                apagarUploads($imagens, 'eventos');
                throw $exception;
            }
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $erro_ev = $exception->getMessage();
        }
    }
}

$estabelecimentos = $pdo->query("SELECT id, nome, categoria, endereco, latitude, longitude, imagem_url, galeria_fotos FROM estabelecimentos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$eventos = $pdo->query("SELECT id, titulo, data_evento, local, latitude, longitude, imagem_url, galeria_fotos FROM eventos ORDER BY data_evento DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Painel Administrativo — Conecta Corrente</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>[data-picker-map] { min-height: 260px; }</style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">
  <div class="mx-auto min-h-screen max-w-6xl px-4 py-6 pb-16 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-col gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-green-800">Conecta Corrente</p>
        <h1 class="mt-1 text-2xl font-extrabold text-gray-900">Painel administrativo</h1>
        <p class="mt-1 text-sm text-gray-600">Cadastre locais, publique eventos e organize suas imagens.</p>
      </div>
      <nav class="flex gap-2" aria-label="Navegação do painel">
        <a href="index.php" class="rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700">Ver guia</a>
        <a href="admin.php?logout=1" class="rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700">Sair</a>
      </nav>
    </header>

    <?php if ($msg_admin): ?>
      <p role="status" class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-900"><?= htmlspecialchars($msg_admin, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($erro_admin): ?>
      <p role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800"><?= htmlspecialchars($erro_admin, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <main class="space-y-8">
      <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
          <div class="mb-5">
            <p class="text-sm font-semibold text-green-800">Estabelecimentos</p>
            <h2 class="mt-1 text-xl font-bold">Cadastrar local</h2>
          </div>
          <?php if ($msg_est): ?><p role="status" class="mb-4 rounded-lg bg-green-50 p-3 text-sm font-semibold text-green-900"><?= htmlspecialchars($msg_est, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <?php if ($erro_est): ?><p role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-800"><?= htmlspecialchars($erro_est, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
              <label for="nome-estabelecimento" class="mb-1 block text-sm font-semibold">Nome do local <span aria-hidden="true">*</span></label>
              <input id="nome-estabelecimento" type="text" name="nome" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="categoria-estabelecimento" class="mb-1 block text-sm font-semibold">Categoria <span aria-hidden="true">*</span></label>
                <select id="categoria-estabelecimento" name="categoria" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100">
                  <option value="Alimentação">Alimentação</option>
                  <option value="Hotelaria">Hotelaria</option>
                  <option value="Serviços">Serviços</option>
                  <option value="Artesanato">Artesanato</option>
                  <option value="Ecoturismo">Ecoturismo</option>
                </select>
              </div>
              <div>
                <label for="whatsapp-estabelecimento" class="mb-1 block text-sm font-semibold">WhatsApp <span aria-hidden="true">*</span></label>
                <input id="whatsapp-estabelecimento" type="tel" name="whatsapp" required placeholder="5589999999999" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
            </div>
            <div>
              <label for="endereco-estabelecimento" class="mb-1 block text-sm font-semibold">Endereço <span aria-hidden="true">*</span></label>
              <input id="endereco-estabelecimento" type="text" name="endereco" data-geocode-query required placeholder="Bairro, rua e número" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
            </div>
            <div>
              <label for="descricao-estabelecimento" class="mb-1 block text-sm font-semibold">Sobre o local</label>
              <textarea id="descricao-estabelecimento" name="descricao" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100"></textarea>
            </div>
            <div>
              <label for="horario-estabelecimento" class="mb-1 block text-sm font-semibold">Horário de funcionamento</label>
              <input id="horario-estabelecimento" type="text" name="horario_funcionamento" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
            </div>
            <div data-location-picker class="space-y-3">
              <div>
                <p class="text-sm font-semibold">Localização no mapa <span aria-hidden="true">*</span></p>
                <p class="mt-1 text-sm text-gray-600">Busque o endereço ou clique no mapa para posicionar o marcador. Confira o ponto antes de salvar.</p>
              </div>
              <button type="button" data-geocode-button class="min-h-11 rounded-lg border border-green-800 px-4 py-2 text-sm font-semibold text-green-900 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700">Buscar endereço</button>
              <p data-location-status role="status" aria-live="polite" class="text-sm text-gray-600"></p>
              <div data-geocode-results class="space-y-2"></div>
              <div data-picker-map role="region" aria-label="Selecione a localização do estabelecimento no mapa" class="z-0 w-full rounded-lg border border-gray-300"></div>
              <p class="text-xs text-gray-500">Mapa e busca: © OpenStreetMap. A busca pode não encontrar ruas ou locais ainda não mapeados; nesse caso, posicione o marcador manualmente.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="latitude-estabelecimento" class="mb-1 block text-sm font-semibold">Latitude</label>
                <input id="latitude-estabelecimento" type="number" name="latitude" min="-90" max="90" step="any" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
              <div>
                <label for="longitude-estabelecimento" class="mb-1 block text-sm font-semibold">Longitude</label>
                <input id="longitude-estabelecimento" type="number" name="longitude" min="-180" max="180" step="any" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
            </div>
            <div data-image-picker data-primary-name="imagem_principal">
              <label for="imagens-estabelecimento" class="mb-1 block text-sm font-semibold">Fotos do estabelecimento</label>
              <input id="imagens-estabelecimento" type="file" name="imagens[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="ajuda-imagens-estabelecimento" class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-2 file:font-semibold file:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-700" />
              <p id="ajuda-imagens-estabelecimento" class="mt-2 text-sm text-gray-600">Até 10 imagens JPG, PNG ou WebP; máximo de 5 MB cada. Escolha a principal.</p>
              <p data-picker-message role="status" aria-live="polite" class="mt-2 text-sm font-medium text-red-700"></p>
              <div data-image-previews class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3"></div>
            </div>
            <button type="submit" name="cadastrar_estabelecimento" class="w-full rounded-lg bg-green-800 px-5 py-3 text-base font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Salvar estabelecimento</button>
          </form>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
          <div class="mb-5">
            <p class="text-sm font-semibold text-green-800">Agenda local</p>
            <h2 class="mt-1 text-xl font-bold">Publicar evento</h2>
          </div>
          <?php if ($msg_ev): ?><p role="status" class="mb-4 rounded-lg bg-green-50 p-3 text-sm font-semibold text-green-900"><?= htmlspecialchars($msg_ev, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <?php if ($erro_ev): ?><p role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-800"><?= htmlspecialchars($erro_ev, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
              <label for="titulo-evento" class="mb-1 block text-sm font-semibold">Título <span aria-hidden="true">*</span></label>
              <input id="titulo-evento" type="text" name="titulo" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="local-evento" class="mb-1 block text-sm font-semibold">Local <span aria-hidden="true">*</span></label>
                <input id="local-evento" type="text" name="local" data-geocode-query required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
              <div>
                <label for="data-evento" class="mb-1 block text-sm font-semibold">Data <span aria-hidden="true">*</span></label>
                <input id="data-evento" type="date" name="data_evento" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
            </div>
            <div>
              <label for="descricao-evento" class="mb-1 block text-sm font-semibold">Sobre o evento</label>
              <textarea id="descricao-evento" name="descricao" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100"></textarea>
            </div>
            <div data-location-picker class="space-y-3">
              <div>
                <p class="text-sm font-semibold">Localização no mapa <span aria-hidden="true">*</span></p>
                <p class="mt-1 text-sm text-gray-600">Busque o local do evento ou clique no mapa para posicionar o marcador. Confira o ponto antes de publicar.</p>
              </div>
              <button type="button" data-geocode-button class="min-h-11 rounded-lg border border-green-800 px-4 py-2 text-sm font-semibold text-green-900 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700">Buscar local</button>
              <p data-location-status role="status" aria-live="polite" class="text-sm text-gray-600"></p>
              <div data-geocode-results class="space-y-2"></div>
              <div data-picker-map role="region" aria-label="Selecione a localização do evento no mapa" class="z-0 w-full rounded-lg border border-gray-300"></div>
              <p class="text-xs text-gray-500">Mapa e busca: © OpenStreetMap. A busca pode não encontrar ruas ou locais ainda não mapeados; nesse caso, posicione o marcador manualmente.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label for="latitude-evento" class="mb-1 block text-sm font-semibold">Latitude</label>
                <input id="latitude-evento" type="number" name="latitude" min="-90" max="90" step="any" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
              <div>
                <label for="longitude-evento" class="mb-1 block text-sm font-semibold">Longitude</label>
                <input id="longitude-evento" type="number" name="longitude" min="-180" max="180" step="any" required class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
              </div>
            </div>
            <div data-image-picker data-primary-name="imagem_principal">
              <label for="imagens-evento" class="mb-1 block text-sm font-semibold">Fotos do evento</label>
              <input id="imagens-evento" type="file" name="imagens[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="ajuda-imagens-evento" class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-2 file:font-semibold file:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-700" />
              <p id="ajuda-imagens-evento" class="mt-2 text-sm text-gray-600">Até 10 imagens JPG, PNG ou WebP; máximo de 5 MB cada. Escolha a principal.</p>
              <p data-picker-message role="status" aria-live="polite" class="mt-2 text-sm font-medium text-red-700"></p>
              <div data-image-previews class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3"></div>
            </div>
            <button type="submit" name="cadastrar_evento" class="w-full rounded-lg bg-green-800 px-5 py-3 text-base font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Publicar evento</button>
          </form>
        </section>
      </div>

      <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
          <p class="text-sm font-semibold text-green-800">Conteúdo publicado</p>
          <h2 class="mt-1 text-xl font-bold">Gerenciar imagens e estabelecimentos</h2>
        </div>
        <?php if (empty($estabelecimentos)): ?>
          <p class="text-sm text-gray-600">Nenhum estabelecimento cadastrado.</p>
        <?php else: ?>
          <div class="grid gap-4 xl:grid-cols-2">
            <?php foreach ($estabelecimentos as $est): ?>
              <?php $imagensEst = galleryImagesFromStorage($est['imagem_url'], $est['galeria_fotos']); ?>
              <article class="rounded-lg border border-gray-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h3 class="font-bold text-gray-900"><?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="text-sm text-gray-600"><?= htmlspecialchars($est['categoria'], ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                  <form method="POST" onsubmit="return confirm('Excluir este estabelecimento e suas avaliações?');">
                    <input type="hidden" name="id" value="<?= (int) $est['id'] ?>" />
                    <button type="submit" name="excluir" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600">Excluir estabelecimento</button>
                  </form>
                </div>
                <?php if (necessitaRevisaoLocalizacao($est['latitude'], $est['longitude'])): ?>
                  <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Este cadastro não tem um ponto confiável no mapa. Busque o endereço ou marque manualmente a localização correta.</p>
                <?php endif; ?>
                <details class="mt-3 rounded-lg border border-gray-200 p-3">
                  <summary class="cursor-pointer font-semibold text-green-900">Corrigir localização</summary>
                  <form method="POST" data-location-picker class="mt-4 space-y-3">
                    <input type="hidden" name="tipo" value="estabelecimentos" />
                    <input type="hidden" name="id" value="<?= (int) $est['id'] ?>" />
                    <div>
                      <label for="busca-estabelecimento-<?= (int) $est['id'] ?>" class="mb-1 block text-sm font-semibold">Endereço para busca</label>
                      <input id="busca-estabelecimento-<?= (int) $est['id'] ?>" type="text" data-geocode-query value="<?= htmlspecialchars($est['nome'] . ', ' . $est['endereco'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                    </div>
                    <button type="button" data-geocode-button class="min-h-11 rounded-lg border border-green-800 px-4 py-2 text-sm font-semibold text-green-900 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700">Buscar endereço</button>
                    <p data-location-status role="status" aria-live="polite" class="text-sm text-gray-600"></p>
                    <div data-geocode-results class="space-y-2"></div>
                    <div data-picker-map role="region" aria-label="Corrija a localização de <?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?> no mapa" class="z-0 w-full rounded-lg border border-gray-300"></div>
                    <p class="text-xs text-gray-500">Escolha um resultado ou clique/arraste o marcador. Busca: © OpenStreetMap.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                      <div>
                        <label for="latitude-estabelecimento-<?= (int) $est['id'] ?>" class="mb-1 block text-sm font-semibold">Latitude</label>
                        <input id="latitude-estabelecimento-<?= (int) $est['id'] ?>" type="number" name="latitude" min="-90" max="90" step="any" required value="<?= necessitaRevisaoLocalizacao($est['latitude'], $est['longitude']) ? '' : htmlspecialchars((string) ($est['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                      </div>
                      <div>
                        <label for="longitude-estabelecimento-<?= (int) $est['id'] ?>" class="mb-1 block text-sm font-semibold">Longitude</label>
                        <input id="longitude-estabelecimento-<?= (int) $est['id'] ?>" type="number" name="longitude" min="-180" max="180" step="any" required value="<?= necessitaRevisaoLocalizacao($est['latitude'], $est['longitude']) ? '' : htmlspecialchars((string) ($est['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                      </div>
                    </div>
                    <button type="submit" name="atualizar_localizacao" class="w-full rounded-lg bg-green-800 px-4 py-3 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700">Salvar localização</button>
                  </form>
                </details>
                <?php if ($imagensEst): ?>
                  <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <?php foreach ($imagensEst as $imagemEst): ?>
                      <figure class="overflow-hidden rounded-lg border border-gray-200">
                        <img src="<?= htmlspecialchars($imagemEst, ENT_QUOTES, 'UTF-8') ?>" alt="Imagem de <?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" class="aspect-square w-full object-cover" />
                        <figcaption class="p-2">
                          <p class="mb-2 text-xs font-medium text-gray-600"><?= $imagemEst === $est['imagem_url'] ? 'Imagem principal' : 'Imagem da galeria' ?></p>
                          <form method="POST" onsubmit="return confirm('Remover esta imagem?');">
                            <input type="hidden" name="tipo" value="estabelecimentos" />
                            <input type="hidden" name="id" value="<?= (int) $est['id'] ?>" />
                            <input type="hidden" name="imagem" value="<?= htmlspecialchars($imagemEst, ENT_QUOTES, 'UTF-8') ?>" />
                            <button type="submit" name="excluir_imagem" class="w-full rounded-md border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600" aria-label="Remover imagem de <?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?>">Remover imagem</button>
                          </form>
                        </figcaption>
                      </figure>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="mt-3 text-sm text-gray-500">Nenhuma imagem cadastrada.</p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="mb-5">
          <p class="text-sm font-semibold text-green-800">Agenda local</p>
          <h2 class="mt-1 text-xl font-bold">Imagens dos eventos</h2>
        </div>
        <?php if (empty($eventos)): ?>
          <p class="text-sm text-gray-600">Nenhum evento cadastrado.</p>
        <?php else: ?>
          <div class="grid gap-4 xl:grid-cols-2">
            <?php foreach ($eventos as $evento): ?>
              <?php $imagensEvento = galleryImagesFromStorage($evento['imagem_url'], $evento['galeria_fotos']); ?>
              <article class="rounded-lg border border-gray-200 p-4">
                <h3 class="font-bold text-gray-900"><?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($evento['local'], ENT_QUOTES, 'UTF-8') ?> · <?= date('d/m/Y', strtotime($evento['data_evento'])) ?></p>
                <?php if (necessitaRevisaoLocalizacao($evento['latitude'], $evento['longitude'])): ?>
                  <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Este evento não tem um ponto confiável no mapa. Busque o local ou marque manualmente a localização correta.</p>
                <?php endif; ?>
                <details class="mt-3 rounded-lg border border-gray-200 p-3">
                  <summary class="cursor-pointer font-semibold text-green-900">Corrigir localização</summary>
                  <form method="POST" data-location-picker class="mt-4 space-y-3">
                    <input type="hidden" name="tipo" value="eventos" />
                    <input type="hidden" name="id" value="<?= (int) $evento['id'] ?>" />
                    <div>
                      <label for="busca-evento-<?= (int) $evento['id'] ?>" class="mb-1 block text-sm font-semibold">Local para busca</label>
                      <input id="busca-evento-<?= (int) $evento['id'] ?>" type="text" data-geocode-query value="<?= htmlspecialchars($evento['titulo'] . ', ' . $evento['local'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                    </div>
                    <button type="button" data-geocode-button class="min-h-11 rounded-lg border border-green-800 px-4 py-2 text-sm font-semibold text-green-900 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700">Buscar local</button>
                    <p data-location-status role="status" aria-live="polite" class="text-sm text-gray-600"></p>
                    <div data-geocode-results class="space-y-2"></div>
                    <div data-picker-map role="region" aria-label="Corrija a localização de <?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?> no mapa" class="z-0 w-full rounded-lg border border-gray-300"></div>
                    <p class="text-xs text-gray-500">Escolha um resultado ou clique/arraste o marcador. Busca: © OpenStreetMap.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                      <div>
                        <label for="latitude-evento-<?= (int) $evento['id'] ?>" class="mb-1 block text-sm font-semibold">Latitude</label>
                        <input id="latitude-evento-<?= (int) $evento['id'] ?>" type="number" name="latitude" min="-90" max="90" step="any" required value="<?= necessitaRevisaoLocalizacao($evento['latitude'], $evento['longitude']) ? '' : htmlspecialchars((string) ($evento['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                      </div>
                      <div>
                        <label for="longitude-evento-<?= (int) $evento['id'] ?>" class="mb-1 block text-sm font-semibold">Longitude</label>
                        <input id="longitude-evento-<?= (int) $evento['id'] ?>" type="number" name="longitude" min="-180" max="180" step="any" required value="<?= necessitaRevisaoLocalizacao($evento['latitude'], $evento['longitude']) ? '' : htmlspecialchars((string) ($evento['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 px-3 py-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
                      </div>
                    </div>
                    <button type="submit" name="atualizar_localizacao" class="w-full rounded-lg bg-green-800 px-4 py-3 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700">Salvar localização</button>
                  </form>
                </details>
                <?php if ($imagensEvento): ?>
                  <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <?php foreach ($imagensEvento as $imagemEvento): ?>
                      <figure class="overflow-hidden rounded-lg border border-gray-200">
                        <img src="<?= htmlspecialchars($imagemEvento, ENT_QUOTES, 'UTF-8') ?>" alt="Imagem de <?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" class="aspect-square w-full object-cover" />
                        <figcaption class="p-2">
                          <p class="mb-2 text-xs font-medium text-gray-600"><?= $imagemEvento === $evento['imagem_url'] ? 'Imagem principal' : 'Imagem da galeria' ?></p>
                          <form method="POST" onsubmit="return confirm('Remover esta imagem?');">
                            <input type="hidden" name="tipo" value="eventos" />
                            <input type="hidden" name="id" value="<?= (int) $evento['id'] ?>" />
                            <input type="hidden" name="imagem" value="<?= htmlspecialchars($imagemEvento, ENT_QUOTES, 'UTF-8') ?>" />
                            <button type="submit" name="excluir_imagem" class="w-full rounded-md border border-red-200 px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600" aria-label="Remover imagem de <?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?>">Remover imagem</button>
                          </form>
                        </figcaption>
                      </figure>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="mt-3 text-sm text-gray-500">Nenhuma imagem cadastrada.</p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const mapCenter = [-10.4401356, -45.1627472];
    let geocodeQueue = Promise.resolve();
    let previousGeocodeAt = 0;

    function requestGeocode(query) {
      const request = geocodeQueue.then(async () => {
        const delay = Math.max(0, 1000 - (Date.now() - previousGeocodeAt));
        if (delay > 0) {
          await new Promise(resolve => setTimeout(resolve, delay));
        }
        previousGeocodeAt = Date.now();

        const response = await fetch('admin.php?geocode=1&q=' + encodeURIComponent(query), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || 'Não foi possível buscar esse endereço.');
        }
        return data;
      });
      geocodeQueue = request.catch(() => undefined);
      return request;
    }

    function parsePastedCoordinates(value) {
      const text = value.trim();
      const decimal = '[+-]?\\d+(?:[.,]\\d+)?';
      const pairs = [
        new RegExp('^(' + decimal + ')\\s*;\\s*(' + decimal + ')$'),
        new RegExp('^(' + decimal + ')\\s+(' + decimal + ')$')
      ];

      for (const pair of pairs) {
        const match = text.match(pair);
        if (match) {
          return {
            latitude: Number(match[1].replace(',', '.')),
            longitude: Number(match[2].replace(',', '.'))
          };
        }
      }

      const commaPair = text.match(/^([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)$/);
      if (commaPair) {
        const latitude = Number(commaPair[1]);
        const longitude = Number(commaPair[2]);
        const hasPairSeparator = /,\s+|\s+,/.test(text);
        const hasDecimalPoints = commaPair[1].includes('.') || commaPair[2].includes('.');
        const hasSignedLongitude = /,\s*[+-]/.test(text);
        if (latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180
            && (hasPairSeparator || hasDecimalPoints || hasSignedLongitude)) {
          return { latitude, longitude };
        }
      }

      if (new RegExp('^' + decimal + '$').test(text)) {
        return { value: Number(text.replace(',', '.')) };
      }

      if (commaPair) {
        return { latitude: Number(commaPair[1]), longitude: Number(commaPair[2]) };
      }

      return null;
    }

    document.querySelectorAll('[data-location-picker]').forEach((picker) => {
      const form = picker.closest('form');
      const latitude = form.querySelector('input[name="latitude"]');
      const longitude = form.querySelector('input[name="longitude"]');
      const mapElement = picker.querySelector('[data-picker-map]');
      const searchInput = form.querySelector('[data-geocode-query]');
      const searchButton = picker.querySelector('[data-geocode-button]');
      const status = picker.querySelector('[data-location-status]');
      const results = picker.querySelector('[data-geocode-results]');
      let map = null;
      let marker = null;

      function setMarker(lat, lng, updateView, updateInputs = true) {
        const point = [Number(lat), Number(lng)];
        if (updateInputs) {
          latitude.value = point[0].toFixed(7);
          longitude.value = point[1].toFixed(7);
        }
        if (!map) {
          return;
        }
        if (!marker) {
          marker = L.marker(point, { draggable: true }).addTo(map);
          marker.on('dragend', () => {
            const position = marker.getLatLng();
            latitude.value = position.lat.toFixed(7);
            longitude.value = position.lng.toFixed(7);
          });
        } else {
          marker.setLatLng(point);
        }
        if (updateView) {
          map.setView(point, 17);
        }
      }

      function initializeMap() {
        if (map) {
          return;
        }
        if (typeof L === 'undefined') {
          status.textContent = 'O mapa não carregou. Você ainda pode informar as coordenadas manualmente.';
          return;
        }

        map = L.map(mapElement).setView(mapCenter, 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '© OpenStreetMap'
        }).addTo(map);
        map.on('click', (event) => {
          setMarker(event.latlng.lat, event.latlng.lng, false);
          status.textContent = 'Ponto marcado. Confira o marcador e as coordenadas.';
        });

        const initialLat = Number(latitude.value);
        const initialLng = Number(longitude.value);
        if (latitude.value !== '' && longitude.value !== ''
            && Number.isFinite(initialLat) && Number.isFinite(initialLng)
            && initialLat >= -90 && initialLat <= 90
            && initialLng >= -180 && initialLng <= 180) {
          setMarker(initialLat, initialLng, false, false);
          map.setView([initialLat, initialLng], 16);
        }
      }

      [latitude, longitude].forEach((input) => {
        input.addEventListener('change', () => {
          const lat = Number(latitude.value);
          const lng = Number(longitude.value);
          if (latitude.value !== '' && longitude.value !== ''
              && Number.isFinite(lat) && Number.isFinite(lng)
              && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
            setMarker(lat, lng, true);
            status.textContent = 'Coordenadas atualizadas.';
          }
        });

        input.addEventListener('paste', (event) => {
          const pasted = event.clipboardData?.getData('text') || '';
          const coordinates = parsePastedCoordinates(pasted);
          if (!coordinates) {
            return;
          }

          event.preventDefault();
          if ('latitude' in coordinates) {
            if (!Number.isFinite(coordinates.latitude) || !Number.isFinite(coordinates.longitude)
                || coordinates.latitude < -90 || coordinates.latitude > 90
                || coordinates.longitude < -180 || coordinates.longitude > 180) {
              status.textContent = 'As coordenadas copiadas estão fora dos limites permitidos.';
              return;
            }
            setMarker(coordinates.latitude, coordinates.longitude, true);
            status.textContent = 'Coordenadas copiadas e aplicadas. Confira o marcador.';
            return;
          }

          const isLatitude = input === latitude;
          const limit = isLatitude ? 90 : 180;
          if (!Number.isFinite(coordinates.value) || Math.abs(coordinates.value) > limit) {
            status.textContent = isLatitude
              ? 'A latitude deve estar entre -90 e 90.'
              : 'A longitude deve estar entre -180 e 180.';
            return;
          }
          input.value = String(coordinates.value);
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });

      searchButton.addEventListener('click', async () => {
        const query = searchInput.value.trim();
        results.replaceChildren();
        if (!query) {
          status.textContent = 'Digite um endereço ou local antes de buscar.';
          searchInput.focus();
          return;
        }

        searchButton.disabled = true;
        status.textContent = 'Buscando endereço…';
        try {
          const matches = await requestGeocode(query);
          if (!Array.isArray(matches) || matches.length === 0) {
            status.textContent = 'Nenhum resultado encontrado. Clique no mapa para marcar o ponto manualmente.';
            return;
          }

          status.textContent = 'Escolha o resultado correto e confira o marcador no mapa.';
          matches.forEach((match) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'block min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-left text-sm text-gray-800 hover:border-green-700 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700';
            button.textContent = match.display_name || 'Resultado sem descrição';
            button.addEventListener('click', () => {
              const lat = Number(match.lat);
              const lng = Number(match.lon);
              if (!Number.isFinite(lat) || !Number.isFinite(lng)
                  || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
                status.textContent = 'O serviço retornou coordenadas inválidas. Marque o local manualmente.';
                return;
              }
              setMarker(lat, lng, true);
              status.textContent = 'Resultado selecionado. Confira se o marcador está no endereço correto.';
            });
            results.append(button);
          });
          results.firstElementChild.click();
        } catch (error) {
          status.textContent = error.message || 'Falha ao buscar. Clique no mapa para marcar o ponto manualmente.';
        } finally {
          searchButton.disabled = false;
        }
      });

      const details = picker.closest('details');
      if (details) {
        details.addEventListener('toggle', () => {
          if (details.open) {
            initializeMap();
            if (map) {
              setTimeout(() => map.invalidateSize(), 0);
            }
          }
        });
      } else {
        initializeMap();
      }
    });

    document.querySelectorAll('[data-image-picker]').forEach((picker) => {
      const input = picker.querySelector('input[type="file"]');
      const previews = picker.querySelector('[data-image-previews]');
      const message = picker.querySelector('[data-picker-message]');
      let primaryIndex = 0;
      let objectUrls = [];

      function setFiles(files) {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
      }

      function renderPreviews() {
        objectUrls.forEach((url) => URL.revokeObjectURL(url));
        objectUrls = [];
        previews.replaceChildren();
        const files = Array.from(input.files);

        files.forEach((file, index) => {
          const card = document.createElement('div');
          card.className = 'overflow-hidden rounded-lg border border-gray-200 bg-white';
          const image = document.createElement('img');
          const url = URL.createObjectURL(file);
          objectUrls.push(url);
          image.src = url;
          image.alt = 'Pré-visualização: ' + file.name;
          image.className = 'aspect-square w-full object-cover';
          const footer = document.createElement('div');
          footer.className = 'space-y-2 p-2';
          const filename = document.createElement('p');
          filename.className = 'truncate text-xs text-gray-700';
          filename.textContent = file.name;
          const primaryLabel = document.createElement('label');
          primaryLabel.className = 'flex min-h-9 items-center gap-2 text-xs font-semibold text-gray-700';
          const radio = document.createElement('input');
          radio.type = 'radio';
          radio.name = picker.dataset.primaryName;
          radio.value = String(index);
          radio.checked = index === primaryIndex;
          radio.className = 'h-4 w-4 accent-green-800';
          radio.addEventListener('change', () => {
            primaryIndex = index;
          });
          primaryLabel.append(radio, document.createTextNode('Imagem principal'));
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'w-full rounded-md border border-gray-300 px-2 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700';
          remove.textContent = 'Remover';
          remove.setAttribute('aria-label', 'Remover ' + file.name);
          remove.addEventListener('click', () => {
            const remaining = files.filter((_, fileIndex) => fileIndex !== index);
            primaryIndex = primaryIndex === index ? 0 : (primaryIndex > index ? primaryIndex - 1 : primaryIndex);
            setFiles(remaining);
            renderPreviews();
          });
          footer.append(filename, primaryLabel, remove);
          card.append(image, footer);
          previews.append(card);
        });
      }

      input.addEventListener('change', () => {
        const selected = Array.from(input.files);
        const valid = [];
        const errors = [];
        if (selected.length > 10) {
          errors.push('O limite é de 10 imagens. As primeiras 10 foram mantidas.');
        }
        selected.forEach((file) => {
          if (file.size > 5 * 1024 * 1024) {
            errors.push(file.name + ' ultrapassa 5 MB e foi removida.');
          } else if (valid.length < 10) {
            valid.push(file);
          }
        });
        setFiles(valid);
        primaryIndex = Math.min(primaryIndex, Math.max(valid.length - 1, 0));
        message.textContent = errors.join(' ');
        renderPreviews();
      });

      renderPreviews();
    });
  </script>
</body>
</html>