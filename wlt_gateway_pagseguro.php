<?php
/*
Plugin Name: Pagseguro Brazil for PremiumPress Directory Theme
Plugin URI: http://www.devhouse.com.br
Description: This plugin will add Pagseguro BR to your PremiumPress payment gateways list.
Version: 1.0
Author: Leonardo Lima de Vasconcellos
Author URI: http://www.devhouse.com.br
Updated: 17th Feb  2017
License:
*/

//1. HOOK INTO THE GATEWAY ARRAY
function wlt_gateway_pagseguro_admin($gateways){
	$nId = count($gateways) + 1;
	$gateways[$nId]['name'] 		= "Pagseguro Brazil";
	$gateways[$nId]['logo'] 		= plugins_url()."/wlt_gateway_pagseguro/img/logo.png";
	$gateways[$nId]['function'] 	= "wlt_gateway_pagseguro_form";
	$gateways[$nId]['website'] 		= "http://www.pagseguro.com.br";
	$gateways[$nId]['callback'] 	= "yes";
	$gateways[$nId]['ownform'] 		= "yes";
	$gateways[$nId]['fields'] 		= array(
		'1' => array('name' => 'Ativar Gateway', 'type' => 'listbox','fieldname' => $gateways[$nId]['function'],'list' => array('yes'=>'Sim','no'=>'Não',) ),
		'2' => array('name' => 'Email Pagseguro', 'type' => 'text', 'fieldname' => 'pagseguro_email' ),
		'3' => array('name' => 'Token Autenticação', 'type' => 'text', 'fieldname' => 'pagseguro_token' ),
		'4' => array('name' => 'Usar Sandbox', 'type' => 'listbox','fieldname' => 'pagseguro_sandbox','list' => array('yes'=>'Sim','no'=>'Não',) ),
		'5' => array('name' => 'Email Sandbox', 'type' => 'text', 'fieldname' => 'pagseguro_email_sandbox' ),
		'6' => array('name' => 'Token Sandbox', 'type' => 'text', 'fieldname' => 'pagseguro_token_sandbox' ),
	);
	$gateways[$nId]['notes'] 	= "";
	return $gateways;
}
add_action('hook_payments_gateways','wlt_gateway_pagseguro_admin');


//3. ADICIONA UM VALOR A QUERYSTRING PARA DAR RELOAD NA PÁGINA
function add_custom_query_var($vars){
  $vars[] = "process_payment";
  return $vars;
}
add_filter('query_vars', 'add_custom_query_var');


//4. BUILD THE PAYMENT FORM DATA
function wlt_gateway_pagseguro_form($data=""){

	global $CORE, $wpdb, $userdata;
	
    /* 
	    DATA AVAILABLE:

		$GLOBALS['total'] 	 
		$GLOBALS['subtotal'] 	 
		$GLOBALS['shipping'] 	 
		$GLOBALS['tax'] 		 
		$GLOBALS['discount'] 	 
		$GLOBALS['items'] 		 
		$GLOBALS['orderid']
		$GLOBALS['description'] 
    */

	$xml_str = '<?xml version="1.0"?>
					<checkout>
					  <currency>BRL</currency>
					  <items>
					    <item>
					      <id>0001</id>
					      <description>' . $GLOBALS['description'] . '</description>
					      <amount>' . number_format($GLOBALS['total'], 2, '.', '') . '</amount>
					      <quantity>1</quantity>
					    </item>
					  </items>
					  <notificationURL>' . get_site_url() . '/wp-admin/admin-post.php?action=pagseguro_notification</notificationURL>
					  <redirectURL>http://lojamodelo.com.br/return.html</redirectURL>
					  <reference>' . $GLOBALS['orderid'] . '</reference>
					  <maxAge>999999999</maxAge>
					</checkout>';
	
	if(get_option('pagseguro_sandbox') == 'yes'){
		$url = "https://ws.sandbox.pagseguro.uol.com.br/v2/checkout?email=" . get_option('pagseguro_email_sandbox') . "&token=" . get_option('pagseguro_token_sandbox');
	} else {
		$url = "https://ws.pagseguro.uol.com.br/v2/checkout?email=" . get_option('pagseguro_email') . "&token=" . get_option('pagseguro_token');
	}

	$stream_options = array(
	    'http' => array(
	        'method'  => 'POST',
	        'header'  => 'Content-Type: application/xml; charset=ISO-8859-1' . "\r\n",
	        'content' =>  $xml_str
	    )
	);

	$context  = stream_context_create($stream_options);
	$response = file_get_contents($url, null, $context);
	$xmlResponse = simplexml_load_string($response);

	if(get_option('pagseguro_sandbox') == 'yes'){
		$string = '<script type="text/javascript" src="https://stc.sandbox.pagseguro.uol.com.br/pagseguro/api/v2/checkout/pagseguro.lightbox.js"></script>';
	} else {
		$string = '<script type="text/javascript" src="https://stc.pagseguro.uol.com.br/pagseguro/api/v2/checkout/pagseguro.lightbox.js"></script>';
	}

	$string .= '<script type="text/javascript">';

	$process_payment = get_query_var( 'process_payment' );
	if ($process_payment != "false"){
		$string .= '
			PagSeguroLightbox({
			    code: \''. $xmlResponse->code . '\'
			    }, {
			    success : function(transactionCode) {
			        window.location = window.location + "&process_payment=false";
			    },
			    abort : function() {
			        alert("Seu pagamento não foi processado.");
			    }
			});
		';
	}
	$string .= '
			jQuery(\'#myPaymentOptions\').on(\'show.bs.modal\', function (e) {
				PagSeguroLightbox({
				    code: \''. $xmlResponse->code . '\'
				    }, {
				    success : function(transactionCode) {
				        window.location = window.location + "&process_payment=false";
				    },
				    abort : function() {
				        alert("Seu pagamento não foi processado.");
				        jQuery(\'#myPaymentOptions\').modal(\'hide\');
				    }
				});
			});
		</script>
		Abrindo o Pagseguro...';

	return $string;
}



////////////////////////////////////////////////////////////////////////////////
//5. CONSTROI A URL DE NOTIFICAÇÃO

add_action('admin_post_pagseguro_notification', 'get_pagseguro_notification');
add_action( 'admin_post_nopriv_pagseguro_notification', 'get_pagseguro_notification' );

function get_pagseguro_notification(){
	if(!isset($_POST['notificationType']) && !($_POST['notificationType'] == 'transaction')){
		wp_die();
	}

	if(get_option('pagseguro_sandbox') == 'yes'){
		$url = "https://ws.sandbox.pagseguro.uol.com.br/v2/transactions/notifications/" . $_POST['notificationCode'] . "?email=" . get_option('pagseguro_email_sandbox') . "&token=" . get_option('pagseguro_token_sandbox');
	} else {
		$url = "https://ws.pagseguro.uol.com.br/v2/transactions/notifications/" . $_POST['notificationCode'] . "?email=" . get_option('pagseguro_email') . "&token=" . get_option('pagseguro_token');
	}

	$response = file_get_contents($url);
	$xmlResponse = simplexml_load_string($response);

    if($response == 'Unauthorized'){
        //Insira seu código avisando que o sistema está com problemas, sugiro enviar um e-mail avisando para alguém fazer a manutenção
        $n = date("dmYhis");
        mail(get_option('pagseguro_email'), "Log Notificações $n", "Unauthorized");
        mail("leo.lima.web@gmail.com", "Log Notificações $n", "Unauthorized");
        wp_die();
    }
    
    $data = $xmlResponse->date;
    $code = $xmlResponse->code;
    $ref = $xmlResponse->reference;
    $status = $xmlResponse->status;
    $preco = $xmlResponse->grossAmount;
    $cliente = $xmlResponse->sender->name;
    $meioPagamentoTipo = $xmlResponse->paymentMethod->type;
    $meioPagamentoCodigo = $xmlResponse->paymentMethod->code;
    
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
    
    $assunto = "Pagamento $ref";
    $mensagem = "<h1>Notifica&ccedil;&atilde;o referente ao pagamento $ref</h1>";
    $mensagem.= "Data: $data<br>";
    $mensagem.= "Status: $status - " . get_pagseguro_status_desc($status) . "<br>";
    $mensagem.= "Meio de Pagamento: $meioPagamentoTipo - " . get_pagseguro_tipo_pag($meioPagamentoTipo) . "<br>";
    $mensagem.= "Código Meio de Pagamento: $meioPagamentoCodigo - " . get_pagseguro_cod_pag($meioPagamentoCodigo) . "<br>";
    $mensagem.= "Pre&ccedil;o: $preco<br>";
    $mensagem.= "Cliente: $cliente<br>";
    $mensagem.= "Code: $code";
    
    mail(get_option('pagseguro_email'), $assunto, $mensagem, $headers);
    mail("leo.lima.web@gmail.com", $assunto, $mensagem, $headers);

    if ($code == "3"){
		core_generic_gateway_callback($GLOBALS['orderid'], array('description' =>  '', 'email' => $userdata->user_email, 'shipping' => 0, 'shipping_label' => "", 'tax' => 0, 'total' => $GLOBALS['total']));
	}
}

function get_pagseguro_status_desc($status){
	switch ($status) {
		case '1':
			return "Aguardando pagamento: o comprador iniciou a transação, mas até o momento o PagSeguro não recebeu nenhuma informação sobre o pagamento.";
			break;
		
		case '2':
			return "Em análise: o comprador optou por pagar com um cartão de crédito e o PagSeguro está analisando o risco da transação.";
			break;
		
		case '3':
			return "Paga: a transação foi paga pelo comprador e o PagSeguro já recebeu uma confirmação da instituição financeira responsável pelo processamento.";
			break;
		
		case '4':
			return "Disponível: a transação foi paga e chegou ao final de seu prazo de liberação sem ter sido retornada e sem que haja nenhuma disputa aberta.";
			break;
		
		case '5':
			return "Em disputa: o comprador, dentro do prazo de liberação da transação, abriu uma disputa.";
			break;
		
		case '6':
			return "Devolvida: o valor da transação foi devolvido para o comprador.";
			break;
		
		case '7':
			return "Cancelada: a transação foi cancelada sem ter sido finalizada.";
			break;
		
		case '8':
			return "Debitado: o valor da transação foi devolvido para o comprador.";
			break;

		case '9':
			return "Retenção temporária: o comprador contestou o pagamento junto à operadora do cartão de crédito ou abriu uma demanda judicial ou administrativa (Procon).";
			break;
		
		default:
			return "Status desconhecido.";
			break;
	}
}


function get_pagseguro_tipo_pag($tipo){
	switch ($tipo) {
		case '1':
			return "Cartão de crédito: O comprador pagou pela transação com um cartão de crédito. Neste caso, o pagamento é processado imediatamente ou no máximo em algumas horas, dependendo da sua classificação de risco.";
			break;
		
		case '2':
			return "Boleto: O comprador optou por pagar com um boleto bancário. Ele terá que imprimir o boleto e pagá-lo na rede bancária. Este tipo de pagamento é confirmado em geral de um a dois dias após o pagamento do boleto. O prazo de vencimento do boleto é de 3 dias.";
			break;
		
		case '3':
			return "Débito online (TEF): O comprador optou por pagar com débito online de algum dos bancos com os quais o PagSeguro está integrado. O PagSeguro irá abrir uma nova janela com o Internet Banking do banco escolhido, onde o comprador irá efetuar o pagamento. Este tipo de pagamento é confirmado normalmente em algumas horas.";
			break;
		
		case '4':
			return "Saldo PagSeguro: O comprador possuía saldo suficiente na sua conta PagSeguro e pagou integralmente pela transação usando seu saldo.";
			break;
		
		case '5':
			return "Oi Paggo *: o comprador paga a transação através de seu celular Oi. A confirmação do pagamento acontece em até duas horas.";
			break;
		
		case '7':
			return "Depósito em conta: o comprador optou por fazer um depósito na conta corrente do PagSeguro. Ele precisará ir até uma agência bancária, fazer o depósito, guardar o comprovante e retornar ao PagSeguro para informar os dados do pagamento. A transação será confirmada somente após a finalização deste processo, que pode levar de 2 a 13 dias úteis.";
			break;
		
		default:
			return "Tipo desconhecido.";
			break;
	}
}

function get_pagseguro_cod_pag($cod){
	switch ($cod) {
		case '101':
			return "Cartão de crédito Visa.";
			break;
		
		case '102':
			return "Cartão de crédito MasterCard.";
			break;
		
		case '103':
			return "Cartão de crédito American Express.";
			break;
		
		case '104':
			return "Cartão de crédito Diners.";
			break;
		
		case '105':
			return "Cartão de crédito Hipercard.";
			break;
		
		case '106':
			return "Cartão de crédito Aura.";
			break;
		
		case '107':
			return "Cartão de crédito Elo.";
			break;
		
		case '108':
			return "Cartão de crédito PLENOCard.";
			break;

		case '109':
			return "Cartão de crédito PersonalCard.";
			break;

		case '110':
			return "Cartão de crédito JCB.";
			break;

		case '111':
			return "Cartão de crédito Discover.";
			break;
		
		case '112':
			return "Cartão de crédito BrasilCard.";
			break;
		
		case '113':
			return "Cartão de crédito FORTBRASIL.";
			break;
		
		case '114':
			return "Cartão de crédito CARDBAN.";
			break;
		
		case '115':
			return "Cartão de crédito VALECARD.";
			break;
		
		case '116':
			return "Cartão de crédito Cabal.";
			break;
		
		case '117':
			return "Cartão de crédito Mais!.";
			break;
		
		case '118':
			return "Cartão de crédito Avista.";
			break;

		case '119':
			return "Cartão de crédito GRANDCARD.";
			break;

		case '120':
			return "Cartão de crédito Sorocred.";
			break;

		case '201':
			return "Boleto Bradesco.";
			break;
		
		case '202':
			return "Boleto Santander.";
			break;
		
		case '301':
			return "Débito online Bradesco.";
			break;
		
		case '302':
			return "Débito online Itaú.";
			break;
		
		case '303':
			return "Débito online Unibanco.";
			break;
		
		case '304':
			return "Débito online Banco do Brasil.";
			break;
		
		case '305':
			return "Débito online Banco Real.";
			break;
		
		case '306':
			return "Débito online Banrisul.";
			break;

		case '307':
			return "Débito online HSBC.";
			break;

		case '401':
			return "Saldo PagSeguro.";
			break;

		case '501':
			return "Oi Paggo.";
			break;

		case '701':
			return "Depósito em conta - Banco do Brasil.";
			break;

		case '702':
			return "Depósito em conta - HSBC.";
			break;

		default:
			return "Código desconhecido.";
			break;
	}
}