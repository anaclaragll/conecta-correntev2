<?php
require_once 'conexao.php';
require_once 'image_helpers.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
$stmt->execute([$id]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
    echo "Evento não encontrado.";
    exit;
}

$galeria = galleryImagesFromStorage($evento['imagem_url'], $evento['galeria_fotos']);

$lat = !empty($evento['latitude']) ? floatval($evento['latitude']) : -10.4431;
$lng = !empty($evento['longitude']) ? floatval($evento['longitude']) : -45.1611;
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($evento['titulo']) ?> — Conecta Corrente</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    #mapa-evento {
      height: 340px;
      width: 100%;
      border-radius: 1rem;
      z-index: 10;
    }
    @media (max-width: 640px) {
      #mapa-evento { height: 280px; }
    }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">
  <div class="mx-auto min-h-screen max-w-7xl bg-white shadow-sm">
    <header class="border-b border-gray-200 px-4 py-4 sm:px-6 lg:px-8">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
        <a href="index.php" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700">Voltar ao guia</a>
        <span class="rounded-full bg-green-50 px-4 py-2 text-sm font-semibold text-green-900">Evento local</span>
      </div>
    </header>

    <main class="mx-auto max-w-6xl space-y-8 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
      <section aria-labelledby="galeria-evento-titulo">
        <div class="mb-4 flex items-end justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-green-800">Registros do evento</p>
            <h2 id="galeria-evento-titulo" class="text-xl font-bold">Galeria de fotos</h2>
          </div>
          <span class="text-sm text-gray-600"><?= count($galeria) ?> imagens</span>
        </div>
        <?php if ($galeria): ?>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($galeria as $indice => $foto): ?>
              <a href="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-green-700 <?= $indice === 0 ? 'col-span-2 row-span-2' : '' ?>">
                <img src="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>" class="aspect-[4/3] h-full w-full object-cover" alt="<?= $indice === 0 ? 'Imagem principal' : 'Imagem da galeria' ?> do evento <?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?>" loading="<?= $indice < 2 ? 'eager' : 'lazy' ?>" />
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">Ainda não há imagens cadastradas para este evento.</p>
        <?php endif; ?>
      </section>

      <section class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(360px,0.9fr)]">
        <div class="space-y-5">
          <div>
            <p class="text-sm font-semibold text-green-800"><?= date('d/m/Y', strtotime($evento['data_evento'])) ?></p>
            <h1 class="mt-1 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl"><?= htmlspecialchars($evento['titulo'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mt-3 text-base text-gray-700"><?= htmlspecialchars($evento['local'], ENT_QUOTES, 'UTF-8') ?></p>
          </div>
          <div class="rounded-xl border border-gray-200 p-5 sm:p-6">
            <h2 class="text-xl font-bold">Sobre o evento</h2>
            <p class="mt-3 whitespace-pre-line text-base leading-7 text-gray-700"><?= htmlspecialchars($evento['descricao'] ?? 'Sem descrição detalhada cadastrada.', ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>

        <section aria-labelledby="mapa-evento-titulo">
          <h2 id="mapa-evento-titulo" class="mb-3 text-xl font-bold">Localização no mapa</h2>
          <div id="mapa-evento" role="region" aria-label="Mapa com o local do evento" class="border border-gray-300 shadow-sm"></div>
        </section>
      </section>
    </main>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const lat = <?= $lat ?>;
    const lng = <?= $lng ?>;

    const map = L.map('mapa-evento').setView([lat, lng], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap'
    }).addTo(map);

    const popup = document.createElement('div');
    const title = document.createElement('strong');
    const place = document.createElement('p');
    title.className = 'text-sm text-green-800';
    title.textContent = <?= json_encode($evento['titulo'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    place.className = 'text-xs text-gray-600';
    place.textContent = <?= json_encode($evento['local'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    popup.append(title, place);

    L.marker([lat, lng])
      .addTo(map)
      .bindPopup(popup)
      .openPopup();
  </script>
</body>
</html>