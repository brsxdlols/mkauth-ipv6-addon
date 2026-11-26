# Instalador nativo PGCORTE dinamico — 1.3.0

Em **Provedor → Ramais → Detalhes**, o barra **PGCORTE DUAL STACK**, logo abaixo de PGCORTE permite copiar o instalador de terminal. Ele cria/atualiza `ler_pgcorte_dual` e seu scheduler de 1 minuto; colar novamente nao duplica. Desativa o scheduler legado `ler_pgcorte`, que importava entradas IPv4 estaticas. Nao apaga listas, pools, profiles nem desconecta clientes. O RADIUS continua responsavel pelo bloqueio; o leitor apenas identifica entradas existentes.

A tela nativa protegida permanece intacta. O acordeao e acrescentado a resposta HTML autenticada por helper PHP, somente na pagina de detalhes do ramal. Usa `.user.ini` em `/opt/mk-auth/admin/`, com backup separado em `/var/backups/mkauth-pgcorte-native-admin/`. Configuracao PHP pode levar ate 300 segundos para atualizar no cache. Se necessario, recarregue a pagina depois desse prazo.

O codigo evita `:jobname` e outras funcoes exclusivas recentes; a leitura IPv4 e executada antes da parte IPv6, que e carregada com parse protegido. Assim, IPv6 ausente/indisponivel nao interrompe a identificacao IPv4. Validado em RouterOS 6.49.23 e 7.24.7, incluindo criacao/reaplicacao de script e scheduler e execucao com IPv6 ausente.

O bloco antigo PGCORTE continua disponivel com seu comportamento nativo. Escolha um modelo de agendamento; o instalador dinamico desativa o antigo para evitar importacao estatica concorrente. Entradas estaticas antigas nao sao apagadas automaticamente.

Comandos adicionais de diagnostico/rollback da tela:

```sh
python3 native_pgcorte.py check --admin
python3 native_pgcorte.py rollback --admin --backup /var/backups/mkauth-pgcorte-native-admin/DIRETORIO
```

# MK-Auth PGCORTE dual — 1.2.1

Inclui o reparo IPv4 RADIUS para clientes principais **e logins adicionais**, e integra o complemento IPv6 na resposta nativa `/mkt/pgcorte`. O provedor continua copiando PGCORTE em **Provedor → Ramais → Detalhes** e colando na CCR. A tela, as chaves e o caminho do Fetch nativo permanecem iguais. Instalacoes existentes recebem o complemento no proximo Fetch/import bem-sucedido.

O adicional usa o seu proprio IP em `sis_adicional.ip`, nao o IP do titular. Exige cadastro principal ativo, adicional ou titular bloqueado, atributo pgcorte presente, IPv4 valido e login adicional sem ambiguidade. Um Framed-IP-Address existente e preservado. Migra automaticamente o patch anterior, que cobria apenas `sis_cliente`.

## Integracao nativa

A fonte protegida `pgcorte.hhvm` e preservada byte por byte. A integracao usa `.user.ini` no diretorio nativo `mkt`, com helper PHP fora da raiz publica. Esse helper atua somente em `/mkt/pgcorte` e `/mkt/pgcorte.hhvm`, preserva a autorizacao nativa e acrescenta o complemento apenas a exportacoes HTTP 200 reconhecidas. HTML, erros e respostas negadas nao recebem complemento.

O IPv4 nativo continua sendo exportado como antes, inclusive com entradas estaticas: este pacote nao transforma o importador IPv4 nativo em mecanismo exclusivamente dinamico. O complemento identifica prefixos IPv6 ja presentes na pgcorte a partir de sessoes, pools e bindings ativos; nao cria delegacoes nem substitui o bloqueio RADIUS. Preserva entradas IPv6 dinamicas e comentarios particulares. Sem IPv6 disponivel, a parte opcional e ignorada e o IPv4 nativo continua funcionando.

## Instalacao

Extraia fora da raiz publica e execute como root:

```sh
sh /opt/mkauth-patches/mkauth-pgcorte-dual/install.sh
python3 /opt/mkauth-patches/mkauth-pgcorte-dual/patch.py check
python3 /opt/mkauth-patches/mkauth-pgcorte-dual/native_pgcorte.py check
```

O pacote confere SHA256SUMS, PHP e schema RADIUS. O FreeRADIUS reinicia apenas quando necessario. Nao reinicia Apache, host ou CCR nem desconecta assinantes. O PHP-FPM pode levar ate 300 segundos para reconhecer a configuracao `.user.ini`; o check confirma os arquivos instalados, nao substitui a validacao HTTP autenticada no roteador. A leitura nativa continua no intervalo ja configurado (normalmente 30 minutos). O codigo copiado da tela de Ramais nao muda visualmente, pois e o retorno do Fetch que agora inclui o complemento.

Recusa sobrescrever um auto_prepend_file personalizado existente. Exige PHP-FPM/CGI com `.user.ini` habilitado; Apache mod_php nao utiliza essa configuracao. Validado em MK-Auth 26.03/PHP-FPM 8.0.30 e RouterOS 7.24.7. RouterOS 6.49.23 e RouterOS 7.24.7 foram validados.

Helpers: `/usr/local/share/mkauth-pgcorte-dual/v1.3.0/`. Nao apague esses arquivos enquanto o auto_prepend estiver configurado ou no cache do PHP. Backups privados: `/var/backups/mkauth-pgcorte-native/`. O JSON informa o diretorio exato.

```sh
python3 native_pgcorte.py rollback --backup /var/backups/mkauth-pgcorte-native/DIRETORIO
python3 patch.py rollback --backup /var/backups/mkauth-radius-pgcorte/DIRETORIO --activate
```

Os componentes tem backups separados. O rollback preserva alteracoes de terceiros e restaura a configuracao anterior, removendo apenas a .user.ini que o proprio patch tiver criado. A reversao HTTP tambem respeita o cache PHP (ate 300 segundos). Reaplique o pacote apos atualizacoes nativas que regenerem a configuracao ou a consulta SQL. Integre isso ao instalador/atualizador da dashboard principal.

Evite agendar um segundo identificador dual em paralelo com o leitor nativo. Este pacote nao instala addon nem altera On Down nos profiles.

## Validacao e limites

Passou em 16 testes Python (incluindo migracao, idempotencia, rollback e preservacao de configuracao personalizada), 19 cenarios SQL em banco isolado e 24 casos dos filtros PHP. A validacao normal de instalacao usa EXPLAIN: nao grava clientes, senhas, contratos ou historico no banco.

A consulta so completa Framed-IP-Address ausente; nao corrige cadastro sem IPv4 valido nem substitui um atributo existente. Confere formato canonico e exclui 0.0.0.0 e broadcast. Nao altera check de autenticacao, accounting, velocidades ou atributos IPv6. Duplicidade de entradas estaticas/dinamicas na address-list e timeouts RADIUS sao problemas separados.

O instalador verifica e preserva a configuracao SQL reconhecida. Se encontrar consulta personalizada ou arquivo modificado por terceiros, recusa sobrescrever. Backups do RADIUS ficam em `/var/backups/mkauth-radius-pgcorte/`, privados, com hashes. Uma falha de validacao/ativacao tenta restaurar automaticamente a configuracao anterior. O retorno 4 do componente RADIUS indica ativacao pendente; nao trate como instalacao concluida.

Para testar novamente sem usar dados de clientes reais: `python3 -m unittest discover -s tests -v` e `php tests/test_native_hook.php`. O teste MySQL e separado, recebe a configuracao via stdin, cria apenas seu proprio banco temporario e o remove ao terminar; nao e executado automaticamente pelo instalador.

A versao 1.1.1 corrige a saida da protecao contra execucoes simultaneas no RouterOS (`:return 0`). Reconhece a configuracao nativa da 1.1.0 e migra com backup, sem sobrescrever auto_prepend personalizado.

A barra PGCORTE DUAL STACK utiliza as classes do acordeao nativo, inicia fechada e possui o mesmo botao de +/-. O codigo conserva escapes e quebras de linha e o botao de copia funciona tambem em HTTP.

Teste da interacao do acordeao (abrir/fechar, teclado, copia HTTP/HTTPS e posicionamento): `node tests/test_native_admin.js`.

## PPPoE de um ramal especifico

O botao ? no cabecalho PPPOE abre um popup. Informe o IPv4 cadastrado do ramal e clique em Copiar codigo deste ramal. O codigo e lido da propria secao PPPoE e somente a URL e alterada de `/mkt/pppoe` para `/mkt/pppoe/IP_DO_RAMAL`; preserva servidor, USER, PASS e escapes do source nativo. O formato foi fornecido pelo provedor como exemplo de uso funcional. O popup nao executa Fetch, importa listas nem altera scripts automaticamente.

IP invalido, ausencia de codigo ou varias URLs impedem a copia, evitando gerar um filtro ambiguo. Se ler_pppoe ja existe, edite sua URL no Source em vez de criar novamente o agendamento nativo.

Teste do filtro e popup PPPoE (IPs validos/invalidos, escapes, preservacao do codigo, copia HTTP/HTTPS): `node tests/test_native_pppoe_help.js`.
