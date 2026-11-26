# Coleta IPv6 por Fetch

O addon registra observaÃ§Ãµes; nÃ£o distribui IPv6, modifica pools ou renova bindings.

## AtualizaÃ§Ã£o

Execute o instalador como root para criar os metadados adicionais e a tarefa de conciliaÃ§Ã£o. Depois copie novamente On Up e On Down da pÃ¡gina Scripts MikroTik para os perfis desejados. Atualizar o addon nÃ£o substitui automaticamente scripts existentes nos roteadores. Preserve comandos particulares do provedor.

O Fetch continua sendo o transporte. Esta atualizaÃ§Ã£o nÃ£o instala nem ativa o coletor alternativo por API RouterOS.

## WAN e PD

- WAN vem do campo IPv6 da sessÃ£o RADIUS, identificado separadamente no painel.
- PD/LAN vem de bindings DHCPv6 bound associados exatamente Ã  interface PPP. Prefixos vÃ¡lidos de /0 a /128 sÃ£o aceitos; /56, /60 e /64 nÃ£o recebem tratamento especial.
- Um prefixo configurado no cadastro nÃ£o comprova uma delegaÃ§Ã£o ativa e nÃ£o substitui um PD observado.
- A coluna de observaÃ§Ã£o representa a gravaÃ§Ã£o da evidÃªncia IPv6, nÃ£o o inÃ­cio retroativo de sua utilizaÃ§Ã£o.

O On Up inicia uma tarefa em segundo plano com atÃ© 12 tentativas, em aproximadamente um minuto, alÃ©m do tempo das requisiÃ§Ãµes. Um PD que apareÃ§a depois dessa janela exige nova coleta; a ausÃªncia observada nÃ£o comprova defeito no CPE. NÃ£o hÃ¡ promessa de recuperaÃ§Ã£o de eventos durante indisponibilidade prolongada do MK-Auth.

## Respostas e logs

O protocolo 2 confirma a gravaÃ§Ã£o com OK e o identificador da linha RADIUS. SÃ³ entÃ£o o script emite a confirmaÃ§Ã£o azul, com login e PD. WAN sem PD recebe uma mensagem distinta. Falhas de transporte nÃ£o sÃ£o classificadas automaticamente como token invÃ¡lido.

O identificador retornado Ã© mantido nas tentativas seguintes. A API rejeita a associaÃ§Ã£o a uma sessÃ£o encerrada. Eventos repetidos da mesma sessÃ£o e prefixo atualizam a observaÃ§Ã£o sem duplicar o registro.

A coleta tambÃ©m envia o identificador PPP lido em `/ppp active`. O valor numÃ©rico do RouterOS Ã© convertido para o formato hexadecimal do `Acct-Session-Id` no receptor. Isso permite identificar a sessÃ£o correta quando uma conexÃ£o anterior do mesmo login e IPv4 ainda aparece aberta no RADIUS. O script aguarda um identificador disponÃ­vel antes de enviar. RequisiÃ§Ãµes antigas continuam aceitas; se permanecerem ambÃ­guas, a API mantÃ©m a recusa HTTP 409 em vez de associar dados por suposiÃ§Ã£o.

O On Down solicita conciliaÃ§Ã£o. A tarefa local de minuto em minuto encerra somente registros cujo identificador RADIUS correspondente possui acctstoptime. Uma desconexÃ£o antiga nÃ£o encerra por suposiÃ§Ã£o a sessÃ£o nova do mesmo login. SessÃµes sem Accounting-Stop continuam dependendo da correÃ§Ã£o do accounting nativo; o addon nÃ£o inventa o encerramento.

O endpoint continua aceitando os campos anteriores de Fetch. InstalaÃ§Ãµes antigas devem atualizar os scripts para obter as tentativas adicionais, confirmaÃ§Ã£o detalhada e vÃ­nculo exato de sessÃ£o.

## ValidaÃ§Ã£o realizada

PHP 8.0; parser externo e interno do RouterOS 7.24.7; banco isolado com /0, /48, /56, /60, /64 e /128, prefixos invÃ¡lidos, mÃºltiplos PD, idempotÃªncia, reconexÃµes e mÃºltiplos NAS; autenticaÃ§Ã£o e respostas dos endpoints; consultas inicial e de busca. Em produÃ§Ã£o, confirmadas gravaÃ§Ãµes Fetch de clientes com /64 e /60 e uma recuperaÃ§Ã£o pontual de bindings ativos por HTTP. Nenhum assinante foi desconectado pela atualizaÃ§Ã£o.

A execuÃ§Ã£o no RouterOS 6 ainda exige validaÃ§Ã£o especÃ­fica em equipamento de teste; a seleÃ§Ã£o de versÃ£o existente foi preservada.

## Identificacao pgcorte IPv4 e IPv6

A pagina Scripts MikroTik oferece `ler_pgcorte_dual.rsc`. Instale como script `ler_pgcorte_dual`, com permissoes read,write,test, e execute centralmente a cada minuto. O script consulta sessoes PPP, pools e bindings ativos e acrescenta `api_corte_login` as entradas existentes da lista `pgcorte`. Nao cria entradas estaticas nem altera o estado de bloqueio. Preserva comentarios particulares; enderecos ambiguos ou sem sessao correspondente ficam sem nova identificacao. A entrada dinamica continua dinamica. O arquivo e um identificador local complementar/substituto da leitura de comentarios; nao modifica o endpoint nativo `/mkt/pgcorte`.

O On Down tem opcoes independentes para limpeza IPv4 e IPv6, desmarcadas por padrao. A limpeza limita-se a entradas estaticas na lista escolhida, com comentario exatamente igual ao login ou `api_corte_login`; no IPv4 exige tambem o IP da sessao. Se o login ja tiver reconectado, a limpeza e ignorada. Entradas dinamicas ficam a cargo do RouterOS. Copie novamente o On Down gerado ao profile para aplicar as opcoes. O leitor nativo pode recriar entradas estaticas: evite executa-lo junto se a intencao for usar apenas bloqueio dinamico por RADIUS.

Validado no RouterOS 7.24.7: parser das quatro combinacoes de limpeza, identificacao real de entradas IPv4/IPv6 e reaplicacao sem reescrever comentarios corretos. RouterOS 6 ainda requer teste especifico.
