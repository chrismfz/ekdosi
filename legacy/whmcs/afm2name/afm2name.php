<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
/**
 * Define addon module configuration parameters.
 *
 * Includes a number of required system fields including name, description,
 * author, language and version.
 *
 * Also allows you to define any configuration parameters that should be
 * presented to the user when activating and configuring the module. These
 * values are then made available in all module function calls.
 *
 * Examples of each and their possible configuration parameters are provided in
 * the fields parameter below.
 *
 * @return array
 */
function afm2name_config()
{
    return [
        // Display name for your module
        'name' => 'AFM2Name',
        // Description displayed within the admin interface
        'description' => 'Αναζήτηση στοιχείων με βάση τον ΑΦΜ',
        // Module author name
        'author' => 'MyIP Networks',
        // Default language
        'language' => 'english',
        // Version number
        'version' => '1.0',
        'fields' => [
            // a text field type allows for single line text input
            'Username' => [
                'FriendlyName' => 'Username token από το gsis',
                'Type' => 'text',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Description goes here',
            ],
            // a password field type allows for masked text input
            'Password' => [
                'FriendlyName' => 'Password για το username token',
                'Type' => 'password',
                'Size' => '25',
                'Default' => '',
                'Description' => 'Enter secret value here',
            ],
        ]
    ];
}

function afm2name_activate()
{
    return [
        // Supported values here include: success, error or info
        'status' => 'success',
        'description' => 'Ενεργοποιήθηκε.',
    ];
}

function afm2name_deactivate()
{
   
    return [
        // Supported values here include: success, error or info
        'status' => 'success',
        'description' => 'Απενεργοποιήθηκε.',
    ];
}

function AddWSSUsernameToken($client, $username, $password)
{
    $wssNamespace = "http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd";
    
    $username = new SoapVar($username, 
                            XSD_STRING, 
                            null, null, 
                            'Username', 
                            $wssNamespace);
                            
    $password = new SoapVar($password, 
                            XSD_STRING, 
                            null, null, 
                            'Password', 
                            $wssNamespace);
    
    $usernameToken = new SoapVar(array($username, $password), 
                                    SOAP_ENC_OBJECT, 
                                    null, null, 'UsernameToken', 
                                    $wssNamespace);
                            
    $usernameToken = new SoapVar(array($usernameToken), 
                            SOAP_ENC_OBJECT, 
                            null, null, null, 
                            $wssNamespace);
    
    $wssUsernameTokenHeader = new SoapHeader($wssNamespace, 'Security', $usernameToken);
    
    $client->__setSoapHeaders($wssUsernameTokenHeader); 
}

function afm2name_output($vars)
{
    // Get common module parameters
    $modulelink = $vars['modulelink'];
    $version = $vars['version'];
    // Get module configuration parameters
    $gsisUser = $vars['Username'];
    $gsisPass = $vars['Password'];
    
    //if $_POST["afm"] is set, search on gsis
    if(isset($_POST["afm"]))
    {
        $WSDL = "https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2?WSDL";
        $soap_options = array(
            'encoding' => 'UTF-8',
            'exceptions' => true,
            'uri' => 'http://www.w3.org/2003/05/soap-envelope',
            'style' => SOAP_RPC,
            'use' => SOAP_ENCODED,
            'soap_version' => SOAP_1_2,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => 30,
            'trace' => true,
            'encoding' => 'UTF-8',
            'location' => $WSDL,
        );
        $client = new SoapClient($WSDL, $soap_options);
        AddWSSUsernameToken($client, $gsisUser, $gsisPass);
        $search_afm = array('INPUT_REC' => array('afm_called_for' => $_POST["afm"]));
        try {
        $data = $client->rgWsPublic2AfmMethod($search_afm);
        ?>
        
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Ιδιότητα</th>
                    <th>Τιμή</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Όνομα</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->onomasia; ?></td>
                </tr>
                <tr>
                    <td>ΑΦΜ</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->afm; ?></td>
                </tr>
                <tr>
                    <td>ΔΟΥ (Κωδ. ΔΟΥ)</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->doy_descr . " (" . $data->result->rg_ws_public2_result_rtType->basic_rec->doy . ")"; ?></td>
                </tr>
                <tr>
                    <td>Κατάσταση</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->deactivation_flag_descr; ?></td>
                </tr>
                <tr>
                    <td>Περιοχή</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->postal_area_description; ?></td>
                </tr>
                <tr>
                    <td>Δ/νση</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->postal_address . " " . $data->result->rg_ws_public2_result_rtType->basic_rec->postal_address_no; ?></td>
                </tr>
                <tr>
                    <td>Τ.Κ.</td>
                    <td><?php echo $data->result->rg_ws_public2_result_rtType->basic_rec->postal_zip_code; ?></td>
                </tr>
            </tbody>
        </table>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Δραστηριότητα (Κωδικός)</th>
                    <th>Είδος</th>
                </tr>
            </thead>
            <tbody>
<?php
        //echo json_encode($data->result->rg_ws_public2_result_rtType->firm_act_tab);
        foreach ($data->result->rg_ws_public2_result_rtType->firm_act_tab->item as $item)
        {
?>
                <tr>
                    <td><?php echo $item->firm_act_descr . " (" . $item->firm_act_code . ")"; ?></td>
                    <td><?php echo $item->firm_act_kind_descr; ?></td>
                </tr>
<?php
        }
?>
                
                
            </tbody>
        </table>
<?php
        }//try
        catch (SoapFault $sf) {
            echo "Something's wrong: " . $sf->getMessage();
        }
        catch (Exception $e) {
            echo "Something's wrong: " . $e->getMessage();
        }
    }//end if $_POST['afm'] is set
?>
<form action="" method="POST">
    <div class="form-group col-md-6">
        <div class="input-group">
            <span class="input-group-addon"><i class="glyphicon glyphicon-search"></i></span>
            <input type="text" class="form-control" id="afm" name="afm" placeholder="ΑΦΜ">
        </div>
    </div>
    <div class="form-group col-md-6">
        <input class="form-control btn btn-primary" type="submit" value="Search">
    </div>
</form>
<?php
}


