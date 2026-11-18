# Conecta Corrente

Aplicação PHP para divulgar estabelecimentos e eventos de Corrente.

## Configuração local

1. Use PHP com Apache e MySQL/MariaDB. No XAMPP, o site pode ser acessado em `http://localhost:8080/conecta-corrente/`.
2. Crie o banco e as tabelas importando `database.sql`.
   Para uma instalação existente, selecione `conecta_corrente` e execute `migration_imagens.sql`.
3. Configure a conexão do banco em `conexao.php`.
4. Configure `ADMIN_USER` e `ADMIN_PASSWORD` no ambiente do Apache e reinicie o servidor. No Apache, por exemplo:

   ```apache
   SetEnv ADMIN_USER admin
   SetEnv ADMIN_PASSWORD substitua-por-uma-senha-forte
   ```

   Use uma senha forte e não a inclua no repositório.
5. Acesse `index.php` pelo servidor local.

O MySQL deve estar acessível na porta `3307`. O PHP deve ter Fileinfo habilitado. Para permitir o envio de até 10 imagens de 5 MB, configure `upload_max_filesize = 5M`, `post_max_size = 55M` e `max_file_uploads = 10` no `php.ini`. As pastas de imagens são criadas automaticamente em `uploads/estabelecimentos/` e `uploads/eventos/`. O `.htaccess` em `uploads/` desativa a execução de scripts nessa pasta.

No painel, use **Buscar endereço** e escolha o resultado correspondente; se a rua ou o local não aparecer, clique no mapa ou arraste o marcador para o ponto correto. A busca usa o Nominatim do OpenStreetMap, limitada ao município de Corrente, e pode não conhecer todos os endereços locais. Novos cadastros exigem coordenadas válidas e não recebem mais um ponto padrão da cidade. A migração remove as coordenadas padrão antigas para que não apareçam como pinos falsos; esses registros devem ser localizados novamente no painel. A página pública omite marcadores sem coordenadas válidas. A busca é feita somente quando solicitada, com cache por sessão; os mapas e resultados devem manter a atribuição ao OpenStreetMap.
