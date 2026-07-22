# MK-Auth IPv6

Addon para correlacionar sessoes IPv4 do `radacct` com IPv6/prefixos coletados no MikroTik.

## Instalacao

O instalador copia o addon, faz backup da versao anterior e executa migracoes idempotentes. Nao e mais necessario executar `ALTER TABLE` ou `CREATE TABLE` manualmente.

```sh
git clone https://github.com/brsxdlols/mkauth-ipv6-addon.git
cd mkauth-ipv6-addon
sh installers/install.sh
```

O token da API e criado automaticamente em `ipv6_settings`. Credenciais e tokens nao sao versionados.

## Roadmap em desenvolvimento

- filtros de investigacao por usuario, IP, porta, periodo, status e NAS;
- gerador On Up/On Down para RouterOS 6 e 7;
- CGNAT opcional, mantendo o historico IPv4 independente;
- importacao de mapas externos (CSV/XLSX e extracao assistida de PDF/DOCX);
- correlacao IP publico + porta + horario com IP privado e sessao PPPoE.

