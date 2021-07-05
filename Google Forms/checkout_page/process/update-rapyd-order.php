<?php

include_once('../../config.php');
include_once('../../rapyd_functions.php');

header('Content-Type: application/json');

if(isset($_GET['payable_id'])){
	$payable_id = $_GET['payable_id'];
	$order_id = $_GET['order_id'];
	$payment_category = $_GET['payment_category'];
}else if (isset($_POST['payable_id'])){
	$payable_id = $_POST['payable_id'];
	$order_id = $_POST['order_id'];
	$payment_category = $_POST['payment_category'];
}else{
	echo "NO PAYABLE ID RECEIVED";
	die;
}



	$rapyd_checkout = create_order_from_payable_id($payable_id, $payment_category);



	echo json_encode($rapyd_checkout);




?>