<?php

	use WHMCS\Module\Addon\RelidRemover\Admin\AdminDispatcher;
	if (!defined("WHMCS"))
	{
		die("This file cannot be accessed directly");
	}

	function relid_remover_config()
	{
  	$configarray = array(
    	"name" => "Invoice relid remover",
			"description" => "This addon sets relid field to 0 for a given incoice id.",
			"version" => "1.0",
			"author" => "MyIP Networks",
		);
    
		return $configarray;
	}

	function relid_remover_activate()
	{
		return [
			'status' => 'success',
			'description' => 'Addon activated.',
		];
	}

	function relid_remover_deactivate()
	{
		return [
			'status' => 'success',
			'description' => 'Addon deactivated.',
		];
	}

	function relid_remover_output($vars)
	{
		$modulelink = $vars['modulelink'];


		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
		$dispatcher = new AdminDispatcher();
		$response = $dispatcher->dispatch($action, $vars);
		echo $response;

	}
