# Coleta IPv6 por Fetch

O addon registra observações; não distribui IPv6, modifica pools ou renova bindings.

## Atualização

Execute o instalador como root para criar os metadados adicionais e a tarefa de conciliação. Depois copie novamente On Up e On Down da página Scripts MikroTik para os perfis desejados. Atualizar o addon não substitui automaticamente scripts existentes nos roteadores. Preserve comandos particulares do provedor.

O Fetch continua sendo o transporte. Esta atualização não instala nem ativa o coletor alternativo por API RouterOS.

## WAN e PD

- WAN vem do campo IPv6 da sessão RADIUS, identificado separadamente no painel.
- PD/LAN vem de bindings DHCPv6 bound associados exatamente à interface PPP. Prefixos válidos de /0 a /128 são aceitos; /56, /60 e /64 não recebem tratamento especial.
- Um prefixo configurado no cadastro não comprova uma delegação ativa e não substitui um PD observado.
- A coluna de observação representa a gravação da evidência IPv6, não o início retroativo de sua utilização.

O On Up inicia uma tarefa em segundo plano com até 12 tentativas, em aproximadamente um minuto, além do tempo das requisições. Um PD que apareça depois dessa janela exige nova coleta; a ausência observada não comprova defeito no CPE. Não há promessa de recuperação de eventos durante indisponibilidade prolongada do MK-Auth.

## Respostas e logs

O protocolo 2 confirma a gravação com OK e o identificador da linha RADIUS. Só então o script emite a confirmação azul, com login e PD. WAN sem PD recebe uma mensagem distinta. Falhas de transporte não são classificadas automaticamente como token inválido.

O identificador retornado é mantido nas tentativas seguintes. A API rejeita a associação a uma sessão encerrada. Eventos repetidos da mesma sessão e prefixo atualizam a observação sem duplicar o registro.

A coleta também envia o identificador PPP lido em `/ppp active`. O valor numérico do RouterOS é convertido para o formato hexadecimal do `Acct-Session-Id` no receptor. Isso permite identificar a sessão correta quando uma conexão anterior do mesmo login e IPv4 ainda aparece aberta no RADIUS. O script aguarda um identificador disponível antes de enviar. Requisições antigas continuam aceitas; se permanecerem ambíguas, a API mantém a recusa HTTP 409 em vez de associar dados por suposição.

O On Down solicita conciliação. A tarefa local de minuto em minuto encerra somente registros cujo identificador RADIUS correspondente possui acctstoptime. Uma desconexão antiga não encerra por suposição a sessão nova do mesmo login. Sessões sem Accounting-Stop continuam dependendo da correção do accounting nativo; o addon não inventa o encerramento.

O endpoint continua aceitando os campos anteriores de Fetch. Instalações antigas devem atualizar os scripts para obter as tentativas adicionais, confirmação detalhada e vínculo exato de sessão.

## Validação realizada

PHP 8.0; parser externo e interno do RouterOS 7.24.7; banco isolado com /0, /48, /56, /60, /64 e /128, prefixos inválidos, múltiplos PD, idempotência, reconexões e múltiplos NAS; autenticação e respostas dos endpoints; consultas inicial e de busca. Em produção, confirmadas gravações Fetch de clientes com /64 e /60 e uma recuperação pontual de bindings ativos por HTTP. Nenhum assinante foi desconectado pela atualização.

A execução no RouterOS 6 ainda exige validação específica em equipamento de teste; a seleção de versão existente foi preservada.
