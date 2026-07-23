# MK-Auth IPv6

Addon para correlacionar sessoes IPv4 do `radacct` com IPv6/prefixos coletados no MikroTik.

## Instalacao

O instalador copia o addon, faz backup da versao anterior, executa migracoes
idempotentes e registra o atalho `Conexoes > Painel IPv4/IPv6` no `addon.js`.
Nao e mais necessario executar `ALTER TABLE`, `CREATE TABLE` ou editar o menu
manualmente.

Instalacao direta no terminal do MK-Auth:

```sh
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-ipv6-addon/main/installers/install-mkauth.sh | tr -d '\r' | sh
```

Ou por clone:

```sh
git clone https://github.com/brsxdlols/mkauth-ipv6-addon.git
cd mkauth-ipv6-addon
sh installers/install.sh
```

O token da API e criado automaticamente em `ipv6_settings`. Credenciais e tokens nao sao versionados.

Durante a transicao, `allow_legacy_disconnect=1` mantem scripts On Down antigos funcionando. Depois de atualizar os perfis MikroTik, altere para `0` para exigir token em todas as desconexoes.

## Roadmap em desenvolvimento

- filtros de investigacao por usuario, IP, porta, periodo, status e NAS;
- gerador On Up/On Down para RouterOS 6 e 7;
- CGNAT opcional, mantendo o historico IPv4 independente;
- importacao de mapas externos (CSV/XLSX e extracao assistida de PDF/DOCX);
- correlacao IP publico + porta + horario com IP privado e sessao PPPoE.
