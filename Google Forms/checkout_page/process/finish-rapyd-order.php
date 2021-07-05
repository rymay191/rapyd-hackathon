<?php

include_once('../../config.php');
include_once('../../rapyd_functions.php');

header('Content-Type: application/json');

if(isset($_GET['payable_id'])){
	$payable_id = $_GET['payable_id'];
	$order_id = $_GET['order_id'];
	$payment_category = $_GET['payment_category'];
	$payment_id = $_GET['payment_id'];
	$payment_method = $_GET['payment_method'];
}else if (isset($_POST['payable_id'])){
	$payable_id = $_POST['payable_id'];
	$order_id = $_POST['order_id'];
	$payment_category = $_POST['payment_category'];
	$payment_id = $_POST['payment_id'];
	$payment_method = $_POST['payment_method'];
}else{
	echo "NO PAYABLE ID RECEIVED";
	die;
}

$response_array = array();
$response_array['success'] = false;
$response_array['message'] = '';

$rapyd_checkout = get_latest_from_rapyd($payable_id);
$payable_order = get_order($payable_id);
$payable_order_internal_id = $payable_order['order']['id'];

if($rapyd_checkout['data']['status'] == "ACT"){
	$payable_status = 'active';
	$payment_date = "Null";
	if($rapyd_checkout['data']['payment']['status'] =="ACT"){
		if(!empty($rapyd_checkout['data']['payment']['redirect_url'])){
			$response_array['redirect_url'] = $rapyd_checkout['data']['payment']['redirect_url'];
		}
	}else if($rapyd_checkout['data']['payment']['status'] =="CLO"){
		$payable_status = 'paid';
		$payment_date = "'".date('Y-m-d H:i:s', $rapyd_checkout['data']['payment']['paid_at'])."'";
	}
}else if($rapyd_checkout['data']['status'] == "CLO"){
	$payable_status = 'paid';
	$payment_date = "'".date('Y-m-d H:i:s', $rapyd_checkout['data']['payment']['paid_at'])."'";
}

$update_q = "UPDATE `orders` SET `status` = '$payable_status', `payment_method` = '$payment_method', `payment_provider_order_id` = '$order_id', `payment_provider_transaction_number` = '$payment_id', `payment_date` = $payment_date, `updated` = now() WHERE `orders`.`id` = $payable_order_internal_id;";

if(mysqli_query($DB_LINK, $update_q)){
	$response_array['success'] = true;
	$response_array['message'] = 'Updated order to in DB to have a status of: '.$payable_status;
}else{
	$response_array['message'] = 'Failed to save update in database.';
}


echo json_encode($response_array);


?>