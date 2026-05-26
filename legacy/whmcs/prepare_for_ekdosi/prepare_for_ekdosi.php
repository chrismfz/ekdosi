<?php

	use WHMCS\Module\Addon\PrepareForEkdosi\Admin\AdminDispatcher;
	if (!defined("WHMCS"))
	{
		die("This file cannot be accessed directly");
	}

	function prepare_for_ekdosi_config()
	{
  	$configarray = array(
    	"name" => "Prepare invoices for Ekdosi",
			"description" => "Edit <code>invoiced</code> field for a given invoice #.",
			"version" => "1.0",
			"author" => "MyIP Networks",
		);
    
		return $configarray;
	}

	function prepare_for_ekdosi_activate()
	{
		return [
			'status' => 'success',
			'description' => 'Addon activated.',
		];
	}

	function prepare_for_ekdosi_deactivate()
	{
		return [
			'status' => 'success',
			'description' => 'Addon deactivated.',
		];
	}

	function prepare_for_ekdosi_output($vars)
	{
		$modulelink = $vars['modulelink'];


		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
		$dispatcher = new AdminDispatcher();
		$response = $dispatcher->dispatch($action, $vars);
		echo $response;

	}
