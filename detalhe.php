<?php
require_once 'conexao.php';
require_once 'image_helpers.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM estabelecimentos WHERE id = ?");
$stmt->execute([$id]);
$estabelecimento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estabelecimento) {
    echo "Estabelecimento não encontrado.";
    exit;
}

$galeria = galleryImagesFromStorage($estabelecimento['imagem_url'], $estabelecimento['galeria_fotos']);

// Processa envio de avaliação
$mensagem_aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_avaliacao'])) {
    $nome_usuario = trim($_POST['nome_usuario'] ?? '');
    $nota = (int) ($_POST['nota'] ?? 0);
    $comentario = trim($_POST['comentario'] ?? '');

    if (!empty($nome_usuario) && $nota >= 1 && $nota <= 5) {
        $stmt_av = $pdo->prepare("INSERT INTO avaliacoes (estabelecimento_id, nome_usuario, nota, comentario) VALUES (?, ?, ?, ?)");
        $stmt_av->execute([$id, $nome_usuario, $nota, $comentario]);
        $mensagem_aviso = "Avaliação enviada com sucesso!";
    } else {
        $mensagem_aviso = "Preencha seu nome e escolha uma nota.";
    }
}

$stmt_avaliacoes = $pdo->prepare("SELECT * FROM avaliacoes WHERE estabelecimento_id = ? ORDER BY id DESC");
$stmt_avaliacoes->execute([$id]);
$avaliacoes = $stmt_avaliacoes->fetchAll(PDO::FETCH_ASSOC);

$total_notas = count($avaliacoes);
$media_notas = 0;
if ($total_notas > 0) {
    $soma_notas = array_sum(array_column($avaliacoes, 'nota'));
    $media_notas = round($soma_notas / $total_notas, 1);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($estabelecimento['nome']) ?> — Conecta Corrente</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">
  <div class="mx-auto min-h-screen max-w-7xl bg-white pb-10 shadow-sm">
    <header class="border-b border-gray-200 bg-white px-4 py-4 sm:px-6 lg:px-8">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
        <a href="index.php" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700" aria-label="Voltar ao guia">Voltar ao guia</a>
        <span class="rounded-full bg-green-50 px-4 py-2 text-sm font-semibold text-green-900"><?= htmlspecialchars($estabelecimento['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </header>

    <main class="mx-auto max-w-6xl space-y-8 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
      <section aria-labelledby="galeria-titulo">
        <div class="mb-4 flex items-end justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-green-800">Conheça o local</p>
            <h2 id="galeria-titulo" class="text-xl font-bold">Fotos e cardápios</h2>
          </div>
          <span class="text-sm text-gray-600"><?= count($galeria) ?> imagens</span>
        </div>
        <?php if ($galeria): ?>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($galeria as $indice => $foto): ?>
              <a href="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-green-700 <?= $indice === 0 ? 'col-span-2 row-span-2' : '' ?>">
                <img src="<?= htmlspecialchars($foto, ENT_QUOTES, 'UTF-8') ?>" class="aspect-[4/3] h-full w-full object-cover" alt="<?= $indice === 0 ? 'Imagem principal' : 'Imagem da galeria' ?> de <?= htmlspecialchars($estabelecimento['nome'], ENT_QUOTES, 'UTF-8') ?>" loading="<?= $indice < 2 ? 'eager' : 'lazy' ?>" />
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">Ainda não há imagens cadastradas para este local.</p>
        <?php endif; ?>
      </section>

      <div class="grid gap-8 lg:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.8fr)]">
        <div class="space-y-6">
          <section>
            <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl"><?= htmlspecialchars($estabelecimento['nome'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mt-3 text-base text-gray-700"><?= htmlspecialchars($estabelecimento['endereco'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!empty($estabelecimento['whatsapp'])): ?>
              <?php $whatsapp = preg_replace('/\D+/', '', $estabelecimento['whatsapp']); ?>
              <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>?text=Olá!%20Encontrei%20seu%20estabelecimento%20no%20Conecta%20Corrente." target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-green-700 px-5 text-base font-bold text-white hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2 sm:w-auto">Conversar pelo WhatsApp</a>
            <?php endif; ?>
          </section>

          <section class="rounded-xl border border-gray-200 p-5 sm:p-6">
            <h2 class="text-xl font-bold">Sobre o local</h2>
            <p class="mt-3 whitespace-pre-line text-base leading-7 text-gray-700"><?= htmlspecialchars($estabelecimento['descricao'] ?? 'Sem descrição cadastrada.', ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!empty($estabelecimento['horario_funcionamento'])): ?>
              <div class="mt-5 border-t border-gray-200 pt-4">
                <h3 class="text-base font-bold">Horário de funcionamento</h3>
                <p class="mt-1 text-sm text-gray-700"><?= htmlspecialchars($estabelecimento['horario_funcionamento'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
            <?php endif; ?>
          </section>
        </div>

        <section aria-labelledby="avaliacoes-titulo" class="space-y-5">
          <div class="flex items-end justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-green-800">Opinião da comunidade</p>
              <h2 id="avaliacoes-titulo" class="text-xl font-bold">Avaliações</h2>
            </div>
            <p class="text-base font-bold text-amber-700" aria-label="Nota média <?= $media_notas ?> de 5"><?= $media_notas ?> / 5 <span class="text-sm font-normal text-gray-600">(<?= $total_notas ?>)</span></p>
          </div>

          <?php if ($mensagem_aviso): ?>
            <p role="status" class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm font-semibold text-green-900"><?= htmlspecialchars($mensagem_aviso, ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>

          <form method="POST" class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
            <h3 class="text-base font-bold">Deixe sua avaliação</h3>
            <div>
              <label for="nome-usuario" class="mb-1 block text-sm font-semibold">Seu nome</label>
              <input id="nome-usuario" type="text" name="nome_usuario" required class="min-h-12 w-full rounded-lg border border-gray-300 px-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100" />
            </div>
            <div>
              <label for="nota" class="mb-1 block text-sm font-semibold">Sua nota</label>
              <select id="nota" name="nota" required class="min-h-12 w-full rounded-lg border border-gray-300 bg-white px-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100">
                <option value="5">5 estrelas — Excelente</option>
                <option value="4">4 estrelas — Muito bom</option>
                <option value="3">3 estrelas — Bom</option>
                <option value="2">2 estrelas — Regular</option>
                <option value="1">1 estrela — Precisa melhorar</option>
              </select>
            </div>
            <div>
              <label for="comentario" class="mb-1 block text-sm font-semibold">Comentário</label>
              <textarea id="comentario" name="comentario" rows="4" class="w-full rounded-lg border border-gray-300 p-3 text-base focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-100"></textarea>
            </div>
            <button type="submit" name="enviar_avaliacao" class="min-h-12 w-full rounded-lg bg-green-800 px-5 text-base font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Enviar avaliação</button>
          </form>

          <div class="space-y-3">
            <?php if (!$avaliacoes): ?>
              <p class="text-sm text-gray-600">Ainda não há avaliações. Seja a primeira pessoa a avaliar.</p>
            <?php endif; ?>
            <?php foreach ($avaliacoes as $av): ?>
              <article class="rounded-lg border border-gray-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <h3 class="font-bold text-gray-900"><?= htmlspecialchars($av['nome_usuario'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <span class="text-sm font-semibold text-amber-700" aria-label="<?= (int) $av['nota'] ?> de 5 estrelas"><?= str_repeat('★', (int) $av['nota']) ?> <span class="text-gray-700"><?= (int) $av['nota'] ?>/5</span></span>
                </div>
                <?php if (!empty($av['comentario'])): ?>
                  <p class="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700"><?= htmlspecialchars($av['comentario'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      </div>
    </main>
  </div>
</body>
</html>