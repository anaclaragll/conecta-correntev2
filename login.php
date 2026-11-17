<?php
session_start();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $adminUsuario = getenv('ADMIN_USER');
    $adminSenha = getenv('ADMIN_PASSWORD');

    if ($adminUsuario === false || $adminUsuario === '' || $adminSenha === false || $adminSenha === '') {
        $erro = "Credenciais do administrador não configuradas no servidor.";
    } elseif ($usuario === $adminUsuario && hash_equals($adminSenha, $senha)) {
        $_SESSION['admin_logado'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $erro = "Utilizador ou palavra-passe incorretos.";
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login Administrativo — Conecta Corrente</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 p-4 font-sans text-gray-900">
  <main class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
    <div class="text-center mb-6">
      <p class="text-sm font-semibold uppercase tracking-wide text-green-800">Conecta Corrente</p>
      <h1 class="mt-1 text-2xl font-extrabold text-gray-900">Área administrativa</h1>
      <p class="mt-2 text-sm text-gray-600">Entre com suas credenciais de acesso.</p>
    </div>

    <?php if ($erro): ?>
      <p role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <label for="usuario" class="mb-1 block text-sm font-semibold text-gray-800">Usuário</label>
        <input id="usuario" type="text" name="usuario" autocomplete="username" required class="min-h-12 w-full rounded-lg border border-gray-300 px-3 text-base outline-none focus:border-green-700 focus:ring-2 focus:ring-green-100" />
      </div>

      <div>
        <label for="senha" class="mb-1 block text-sm font-semibold text-gray-800">Senha</label>
        <input id="senha" type="password" name="senha" autocomplete="current-password" required class="min-h-12 w-full rounded-lg border border-gray-300 px-3 text-base outline-none focus:border-green-700 focus:ring-2 focus:ring-green-100" />
      </div>

      <button type="submit" class="min-h-12 w-full rounded-lg bg-green-800 px-5 text-base font-bold text-white hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Entrar no painel</button>
    </form>

    <a href="index.php" class="mt-6 inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-700">Voltar ao guia</a>
  </main>
</body>
</html>