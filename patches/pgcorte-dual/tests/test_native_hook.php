<?php
define('MKAUTH_PGCORTE_DUAL_TEST', true);
require __DIR__.'/../native_hook.php';
$native="/ip firewall address-list\nadd list=pgcorte address=192.0.2.1 comment=api_corte_fixture\n";
$worker=file_get_contents(__DIR__.'/../pgcorte_dual.rsc');
$result=mkauthPgcorteDualTransform($native,200,$worker);
$cases=[
 'native_preserved'=>strpos($result,rtrim($native)."\n")===0,
 'dual_appended'=>substr_count($result,'# BEGIN MKAUTH_PGCORTE_DUAL')===1,
 'idempotent'=>mkauthPgcorteDualTransform($result,200,$worker)===$result,
 'unauthorized'=>mkauthPgcorteDualTransform($native,403,$worker)===$native,
 'failure'=>mkauthPgcorteDualTransform($native,500,$worker)===$native,
 'denied_body'=>mkauthPgcorteDualTransform('Acesso negado',200,$worker)==='Acesso negado',
 'login_page'=>mkauthPgcorteDualTransform('<html>'.$native.'</html>',200,$worker)==='<html>'.$native.'</html>',
 'missing_worker'=>mkauthPgcorteDualTransform($native,200,false)===$native,
 'ipv4_without_clients'=>strpos(mkauthPgcorteDualTransform('/ip firewall address-list remove [find comment~"api_corte_"]',200,$worker),'# BEGIN MKAUTH_PGCORTE_DUAL')!==false
];
$html='<html><body><h2>PGCORTE</h2></body></html>';
$installer='{ :put "fixture" }';
$panel=mkauthPgcorteDualAdmin($html,200,$installer);
$cases['admin_panel']=strpos($panel,'mkauth-pgcorte-dual-installer')!==false;
$cases['admin_escaped']=strpos($panel,'&quot;fixture&quot;')!==false;
$cases['admin_idempotent']=mkauthPgcorteDualAdmin($panel,200,$installer)===$panel;
$cases['admin_unauthorized']=mkauthPgcorteDualAdmin($html,403,$installer)===$html;
$cases['admin_wrong_page']=mkauthPgcorteDualAdmin('<html><body>Login</body></html>',200,$installer)==='<html><body>Login</body></html>';
$cases['admin_missing_installer']=mkauthPgcorteDualAdmin($html,200,false)===$html;
$fullInstaller=file_get_contents(__DIR__.'/../instalar_pgcorte_dual.rsc');
$fullPanel=mkauthPgcorteDualAdmin($html,200,$fullInstaller);
preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s',$fullPanel,$match);
$cases['installer_round_trip']=isset($match[1])&&html_entity_decode($match[1],ENT_QUOTES,'UTF-8')===$fullInstaller;
$nativeAccordion='<article class="accordion is-small is-active"><div class="accordion-header toggle"><p>PGCORTE</p><button class="toggle"></button></div><div class="accordion-body"><div class="accordion-content"><pre>original fixture</pre></div></div></article>';
$nextAccordion='<article class="accordion is-small"><div class="accordion-header"><p>PGAVISO</p></div><div class="accordion-body">fixture</div></article>';
$accordionHtml='<html><body><section class="accordions">'.$nativeAccordion.$nextAccordion.'</section></body></html>';
$accordionResult=mkauthPgcorteDualAdmin($accordionHtml,200,$installer);
$cases['native_accordion_unchanged']=strpos($accordionResult,$nativeAccordion)!==false;
$cases['native_accordion_position']=strpos($accordionResult,'id="mkauth-pgcorte-dual-installer"')>strpos($accordionResult,$nativeAccordion)&&strpos($accordionResult,'id="mkauth-pgcorte-dual-installer"')<strpos($accordionResult,$nextAccordion);
$cases['native_accordion_title']=strpos($accordionResult,'<p>PGCORTE DUAL STACK</p>')!==false;
$cases['native_accordion_classes']=strpos($accordionResult,'id="mkauth-pgcorte-dual-installer" class="accordion is-small"')!==false;
$cases['native_accordion_starts_closed']=strpos($accordionResult,'aria-expanded="false"')!==false;
$cases['native_accordion_no_fallback']=strpos($accordionResult,'id="mkauth-pgcorte-dual-fallback"')===false;
$singleQuotes=str_replace('class="accordion-header toggle"',"class='accordion-header toggle'",$accordionHtml);
$cases['native_single_quote_header']=strpos(mkauthPgcorteDualAdmin($singleQuotes,200,$installer),'id="mkauth-pgcorte-dual-fallback"')===false;
foreach($cases as $name=>$ok)if(!$ok){fwrite(STDERR,"FAILED $name\n");exit(1);}
echo 'NATIVE_HOOK_'.count($cases)."_CASES_OK\n";
