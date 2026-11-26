# MK-Auth PGCORTE dual — 1.1.0

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

Recusa sobrescrever um auto_prepend_file personalizado existente. Exige PHP-FPM/CGI com `.user.ini` habilitado; Apache mod_php nao utiliza essa configuracao. Validado em MK-Auth 26.03/PHP-FPM 8.0.30 e RouterOS 7.24.7. RouterOS 6 requer validacao especifica antes da distribuicao geral.

Helpers: `/usr/local/share/mkauth-pgcorte-dual/v1.1.0/`. Nao apague esses arquivos enquanto o auto_prepend estiver configurado ou no cache do PHP. Backups privados: `/var/backups/mkauth-pgcorte-native/`. O JSON informa o diretorio exato.

```sh
python3 native_pgcorte.py rollback --backup /var/backups/mkauth-pgcorte-native/DIRETORIO
python3 patch.py rollback --backup /var/backups/mkauth-radius-pgcorte/DIRETORIO --activate
```

Os componentes tem backups separados. O rollback preserva alteracoes de terceiros e restaura a configuracao anterior, removendo apenas a .user.ini que o proprio patch tiver criado. A reversao HTTP tambem respeita o cache PHP (ate 300 segundos). Reaplique o pacote apos atualizacoes nativas que regenerem a configuracao ou a consulta SQL. Integre isso ao instalador/atualizador da dashboard principal.

Evite agendar um segundo identificador dual em paralelo com o leitor nativo. Este pacote nao instala addon nem altera On Down nos profiles.

## Validacao e limites

Passou em 15 testes Python (incluindo migracao, idempotencia, rollback e preservacao de configuracao personalizada), 19 cenarios SQL em banco isolado e nove casos do filtro PHP. A validacao normal de instalacao usa EXPLAIN: nao grava clientes, senhas, contratos ou historico no banco.

A consulta so completa Framed-IP-Address ausente; nao corrige cadastro sem IPv4 valido nem substitui um atributo existente. Confere formato canonico e exclui 0.0.0.0 e broadcast. Nao altera check de autenticacao, accounting, velocidades ou atributos IPv6. Duplicidade de entradas estaticas/dinamicas na address-list e timeouts RADIUS sao problemas separados.

O instalador verifica e preserva a configuracao SQL reconhecida. Se encontrar consulta personalizada ou arquivo modificado por terceiros, recusa sobrescrever. Backups do RADIUS ficam em `/var/backups/mkauth-radius-pgcorte/`, privados, com hashes. Uma falha de validacao/ativacao tenta restaurar automaticamente a configuracao anterior. O retorno 4 do componente RADIUS indica ativacao pendente; nao trate como instalacao concluida.

Para testar novamente sem usar dados de clientes reais: `python3 -m unittest discover -s tests -v` e `php tests/test_native_hook.php`. O teste MySQL e separado, recebe a configuracao via stdin, cria apenas seu proprio banco temporario e o remove ao terminar; nao e executado automaticamente pelo instalador.
