<?php
require_once 'conexao.php';

// Captura e limpa os filtros de busca e categoria
$categoria_filtro = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$busca = isset($_GET['q']) ? trim($_GET['q']) : '';

// Construção da consulta SQL dinâmica para busca
$sql = "SELECT * FROM estabelecimentos WHERE ativo = 1";
$params = [];

if (!empty($categoria_filtro)) {
    $sql .= " AND categoria = ?";
    $params[] = $categoria_filtro;
}

if (!empty($busca)) {
    $sql .= " AND (nome LIKE ? OR descricao LIKE ? OR endereco LIKE ? OR categoria LIKE ?)";
    $searchTerm = "%{$busca}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$estabelecimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Busca próximos eventos
$stmt_ev = $pdo->query("SELECT * FROM eventos WHERE data_evento >= CURDATE() ORDER BY data_evento ASC LIMIT 5");
$eventos = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);

// Formata pontos do mapa Leaflet
$pontos_mapa = [];
foreach ($estabelecimentos as $est) {
    $lat = !empty($est['latitude']) ? floatval($est['latitude']) : -10.4431;
    $lng = !empty($est['longitude']) ? floatval($est['longitude']) : -45.1611;
    
    $pontos_mapa[] = [
        'id' => $est['id'],
        'nome' => $est['nome'],
        'categoria' => $est['categoria'],
        'endereco' => $est['endereco'],
        'lat' => $lat,
        'lng' => $lng
    ];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Conecta Corrente — Guia Interativo & Eventos</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    .hide-scrollbar { scrollbar-width: none; }
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    #mapa {
      height: 340px;
      width: 100%;
      border-radius: 1rem;
      z-index: 10;
    }
    @media (max-width: 640px) {
      #mapa { height: 280px; }
    }
  </style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">
  <div class="mx-auto min-h-screen max-w-7xl bg-white pb-24 shadow-sm md:pb-10">
    <header class="sticky top-0 z-40 border-b border-gray-200 bg-white/95 px-4 py-4 backdrop-blur sm:px-6 lg:px-8">
      <div class="mx-auto flex max-w-6xl flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-green-800">Guia local e turismo</p>
            <h1 class="whitespace-nowrap text-2xl font-extrabold tracking-tight text-gray-900">Conecta <span class="text-green-800">Corrente</span></h1>
          </div>
          <a href="login.php" class="shrink-0 rounded-lg bg-green-800 px-4 py-3 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2 md:hidden">Acessar painel</a>
        </div>
        <nav class="hidden items-center gap-6 md:flex" aria-label="Navegação principal">
          <a href="#mapa-secao" class="whitespace-nowrap text-sm font-semibold text-gray-700 hover:text-green-800">Mapa</a>
          <a href="#locais" class="whitespace-nowrap text-sm font-semibold text-gray-700 hover:text-green-800">Estabelecimentos</a>
          <a href="#eventos" class="whitespace-nowrap text-sm font-semibold text-gray-700 hover:text-green-800">Eventos</a>
          <a href="login.php" class="whitespace-nowrap rounded-lg bg-green-800 px-4 py-3 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Acessar painel</a>
        </nav>
        <form method="GET" action="index.php" class="relative w-full md:max-w-md">
          <label for="busca" class="sr-only">Buscar estabelecimentos e serviços</label>
          <input id="busca" type="search" name="q" value="<?= htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar estabelecimentos e serviços" class="h-12 w-full rounded-lg border border-gray-300 bg-white px-4 pr-20 text-base outline-none focus:border-green-700 focus:ring-2 focus:ring-green-100" />
          <?php if (!empty($categoria_filtro)): ?>
            <input type="hidden" name="cat" value="<?= htmlspecialchars($categoria_filtro, ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; ?>
          <button type="submit" class="absolute right-1 top-1 h-10 rounded-md bg-green-800 px-4 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700" aria-label="Buscar">Buscar</button>
        </form>
      </div>
    </header>

    <nav class="border-b border-gray-200 bg-white" aria-label="Filtrar por categoria">
      <div class="hide-scrollbar mx-auto flex max-w-6xl gap-2 overflow-x-auto px-4 py-3 text-sm font-semibold sm:px-6 lg:px-8">
        <a href="index.php<?= !empty($busca) ? '?q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= empty($categoria_filtro) ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Todas</a>
        <a href="index.php?cat=Alimentação<?= !empty($busca) ? '&q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= $categoria_filtro === 'Alimentação' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Alimentação</a>
        <a href="index.php?cat=Hotelaria<?= !empty($busca) ? '&q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= $categoria_filtro === 'Hotelaria' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Hotelaria</a>
        <a href="index.php?cat=Serviços<?= !empty($busca) ? '&q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= $categoria_filtro === 'Serviços' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Serviços</a>
        <a href="index.php?cat=Artesanato<?= !empty($busca) ? '&q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= $categoria_filtro === 'Artesanato' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Artesanato</a>
        <a href="index.php?cat=Ecoturismo<?= !empty($busca) ? '&q=' . urlencode($busca) : '' ?>" class="shrink-0 rounded-full border px-4 py-2.5 <?= $categoria_filtro === 'Ecoturismo' ? 'border-green-800 bg-green-800 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-green-700' ?>">Ecoturismo</a>
      </div>
    </nav>

    <main class="mx-auto max-w-6xl space-y-10 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
      <section id="mapa-secao" aria-labelledby="mapa-titulo" class="scroll-mt-40">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-green-800">Encontre no mapa</p>
            <h2 id="mapa-titulo" class="text-xl font-bold sm:text-2xl">Mapa comercial e turístico</h2>
          </div>
          <p class="text-sm text-gray-600">Selecione um ponto para ver os detalhes.</p>
        </div>
        <div id="mapa" role="region" aria-label="Mapa de Corrente com estabelecimentos" class="border border-gray-300 shadow-sm"></div>
      </section>

      <section id="locais" aria-labelledby="locais-titulo">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-green-800">Guia comercial</p>
            <h2 id="locais-titulo" class="text-xl font-bold sm:text-2xl">Estabelecimentos</h2>
          </div>
          <span class="text-sm text-gray-600"><?= count($estabelecimentos) ?> encontrados</span>
        </div>
        <?php if (empty($estabelecimentos)): ?>
          <div class="rounded-xl border border-gray-200 bg-gray-50 p-6 text-center">
            <p class="text-base text-gray-700">Nenhum local encontrado para esta busca.</p>
            <a href="index.php" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-green-800 px-5 text-sm font-bold text-white hover:bg-green-900">Limpar filtros</a>
          </div>
        <?php else: ?>
          <div class="grid gap-4 md:grid-cols-2">
            <?php foreach ($estabelecimentos as $est): ?>
              <?php
                $lat = !empty($est['latitude']) ? (float) $est['latitude'] : -10.4431;
                $lng = !empty($est['longitude']) ? (float) $est['longitude'] : -45.1611;
                $imagem = !empty($est['imagem_url']) ? $est['imagem_url'] : 'https://images.unsplash.com/photo-1445116572660-236099ec97a0?auto=format&fit=crop&w=600&q=80';
              ?>
              <article class="overflow-hidden rounded-xl border border-gray-200 bg-white transition hover:border-green-700 hover:shadow-sm">
                <div class="flex gap-4 p-4">
                  <img class="h-28 w-28 shrink-0 rounded-lg bg-gray-100 object-cover sm:h-32 sm:w-32" src="<?= htmlspecialchars($imagem, ENT_QUOTES, 'UTF-8') ?>" alt="Imagem principal de <?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" />
                  <div class="flex min-w-0 flex-1 flex-col">
                    <span class="w-fit rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-900"><?= htmlspecialchars($est['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
                    <h3 class="mt-2 text-lg font-bold leading-snug text-gray-900"><?= htmlspecialchars($est['nome'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="mt-1 line-clamp-2 text-sm text-gray-600"><?= htmlspecialchars($est['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-auto pt-3 text-sm text-gray-600"><?= htmlspecialchars($est['endereco'], ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                </div>
                <div class="flex gap-3 border-t border-gray-100 px-4 py-3">
                  <button type="button" data-map-target="<?= (int) $est['id'] ?>" data-lat="<?= $lat ?>" data-lng="<?= $lng ?>" class="min-h-11 flex-1 rounded-lg border border-green-800 px-3 text-sm font-semibold text-green-900 hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-700">Ver no mapa</button>
                  <a href="detalhe.php?id=<?= (int) $est['id'] ?>" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-lg bg-green-800 px-3 text-sm font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Ver detalhes</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section id="eventos" aria-labelledby="eventos-titulo" class="rounded-xl border border-gray-200 bg-white p-4 sm:p-6">
        <div class="mb-4">
          <p class="text-sm font-semibold text-green-800">Agenda cultural</p>
          <h2 id="eventos-titulo" class="text-xl font-bold sm:text-2xl">Próximos eventos em Corrente</h2>
        </div>
        <?php if (empty($eventos)): ?>
          <p class="text-sm text-gray-600">Nenhum evento agendado para os próximos dias.</p>
        <?php else: ?>
          <div class="grid gap-3 md:grid-cols-2">
            <?php foreach ($eventos as $ev): ?>
              <a href="evento_detalhe.php?id=<?= (int) $ev['id'] ?>" class="flex min-h-20 items-center gap-4 rounded-lg border border-gray-200 p-3 hover:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-700">
                <div class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-green-800 text-white">
                  <span class="text-xs font-semibold uppercase"><?= date('M', strtotime($ev['data_evento'])) ?></span>
                  <span class="text-xl font-bold leading-none"><?= date('d', strtotime($ev['data_evento'])) ?></span>
                </div>
                <div class="min-w-0 flex-1">
                  <h3 class="truncate font-bold text-gray-900"><?= htmlspecialchars($ev['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <p class="mt-1 truncate text-sm text-gray-600"><?= htmlspecialchars($ev['local'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span aria-hidden="true" class="text-xl text-green-800">›</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <nav class="fixed bottom-0 left-0 z-50 w-full border-t border-gray-200 bg-white px-3 py-2 md:hidden" aria-label="Navegação inferior">
      <div class="mx-auto grid max-w-md grid-cols-2 gap-2">
        <a href="#mapa-secao" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-green-50 px-3 text-sm font-bold text-green-900">Mapa e guia</a>
        <a href="login.php" class="inline-flex min-h-12 items-center justify-center rounded-lg px-3 text-sm font-bold text-gray-700 hover:bg-gray-50">Acessar painel</a>
      </div>
    </nav>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const correnteLat = -10.4431;
    const correnteLng = -45.1611;

    const map = L.map('mapa').setView([correnteLat, correnteLng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap'
    }).addTo(map);

    const pontos = <?= json_encode($pontos_mapa, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const markers = new Map();

    pontos.forEach(ponto => {
      const popupContent = document.createElement('div');
      popupContent.className = 'text-center';
      const title = document.createElement('strong');
      title.className = 'text-sm text-green-800';
      title.textContent = ponto.nome;
      const category = document.createElement('p');
      category.className = 'text-xs text-gray-600';
      category.textContent = ponto.categoria;
      const link = document.createElement('a');
      link.className = 'mt-2 inline-block rounded bg-green-800 px-3 py-2 text-xs font-bold text-white';
      link.href = `detalhe.php?id=${encodeURIComponent(ponto.id)}`;
      link.textContent = 'Ver perfil';
      popupContent.append(title, category, link);

      const marker = L.marker([ponto.lat, ponto.lng])
        .addTo(map)
        .bindPopup(popupContent);

      markers.set(String(ponto.id), marker);
    });

    document.querySelectorAll('[data-map-target]').forEach(button => {
      button.addEventListener('click', () => {
        const lat = Number(button.dataset.lat);
        const lng = Number(button.dataset.lng);
        map.flyTo([lat, lng], 16, { duration: 0.8 });
        const marker = markers.get(button.dataset.mapTarget);
        if (marker) marker.openPopup();
        document.getElementById('mapa-secao').scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    });
  </script>
</body>
</html>