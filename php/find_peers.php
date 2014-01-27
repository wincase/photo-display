<?php
// A script that registers screens and returns peers
// for reference: http://docs.aws.amazon.com/aws-sdk-php/guide/latest/service-dynamodb.html
// and also useful: http://docs.aws.amazon.com/amazondynamodb/latest/developerguide/AppendixSampleDataCodePHP.html

require '../vendor/autoload.php';

// don't want to print debug through web server in general
if (!isset($_SERVER['HTTP_HOST'])) {
    $debug = true; 
} else {
    if (isset($_REQUEST['debug'])) {$debug = true;}
}

use Aws\Common\Aws;

// You'll need to edit this with your config file
// make sure you specify the correct region as dynamo is region specific
$aws = Aws::factory('/usr/www/html/photo-display/php/amz_config.json');
$client = $aws->get('DynamoDb');
$result = $client->listTables();

// TableNames contains an array of table names
$has_regions = false;
$has_screens = false;
foreach ($result['TableNames'] as $table_name) {
    if ($table_name == "media_regions") {$has_regions = true;}
    if ($table_name == "media_screens") {$has_screens = true;}
    if ($debug) {echo "Found Table: " . $table_name . "<br>\n";}
}


// Create tables if non-existent
if (!$has_regions ) {
    // This can take a few mintes so increase timelimit
    set_time_limit(600);
    
    if ($debug) {echo "Attempting to Create Table: media_regions<br>\n";}
    $client->createTable(array(
        'TableName' => 'media_regions',
        'AttributeDefinitions' => array(
            array(
                'AttributeName' => 'region_name',
                'AttributeType' => 'S'
            )
        ),
        'KeySchema' => array(
            array(
                'AttributeName' => 'region_name',
                'KeyType'       => 'HASH'
            )
        ),
        'ProvisionedThroughput' => array(
            'ReadCapacityUnits'  => 1,
            'WriteCapacityUnits' => 1
        )
    ));
    if ($debug) {echo "Created Table: media_regions<br>\n";}
    $client->waitUntilTableExists(array('TableName' => 'media_regions'));
    if ($debug) {echo "Table Exists!<br>\n";}
}


// Create tables if non-existent
if (!$has_screens ) {
    // This can take a few mintes so increase timelimit
    set_time_limit(600);
    
    if ($debug) {echo "Attempting to Create Table: media_screens<br>\n";}
    $client->createTable(array(
        'TableName' => 'media_screens',
        'AttributeDefinitions' => array(
            array(
                'AttributeName' => 'screen_id',
                'AttributeType' => 'S'
            ),
            array(
                'AttributeName' => 'screen_region_name',
                'AttributeType' => 'S'
            )
        ),
        'KeySchema' => array(
            array(
                'AttributeName' => 'screen_id',
                'KeyType'       => 'HASH'
            ),
            array(
                'AttributeName' => 'screen_region_name',
                'KeyType'       => 'RANGE'
            )

        ),
        'ProvisionedThroughput' => array(
            'ReadCapacityUnits'  => 1,
            'WriteCapacityUnits' => 1
        )
    ));
    if ($debug) {echo "Created Table: media_screens<br>\n";}
    $client->waitUntilTableExists(array('TableName' => 'media_screens'));
    if ($debug) {echo "Table Exists!<br>\n";}
}

// ok we've got tables, see what we were sent
if ($debug) {echo "Current Tables Exist<br>\n";}
$created_region = false;
$region_name = '';
$screen_id = '';
$screen_private_ip = '192.168.1.77';
$screen_public_ip = '192.168.1.66';
if (isset($_REQUEST['region'])) {$region_name = $_REQUEST['region'];}
if ($region_name == '') {$region_name = 'Default Region';}
if (isset($_REQUEST['screen_id'])) {$screen_id = $_REQUEST['screen_id'];}
if ($screen_id == '') {$screen_id = 'Default Screen';}
if (isset($_REQUEST['private_ip'])) {$screen_private_ip = $_REQUEST['private_ip'];}
if (isset($_REQUEST['public_ip'])) {$screen_public_ip = $_REQUEST['public_ip'];}
$time = time();

// have we seen this region
if ($debug) {echo "Looking up region: $region_name<br>\n";}
$result = $client->getItem(array(
    'ConsistentRead' => true,
    'TableName' => 'media_regions',
    'Key'       => array(
        'region_name'   => array('S' => $region_name)
    )
));
if ($debug) {var_dump($result); echo '<br>';}

if (!isset($result['Item']['region_name']['S'])) {
    // Add this region
    if ($debug) {echo "$region_name not found, adding region now<br>\n";}
    $result = $client->putItem(array(
        'TableName' => 'media_regions',
        'Item' => $client->formatAttributes(array(
            'region_name'      => $region_name,
            'region_active'    => true,
            'region_screen_list'   => array($screen_id)
        )),
        'ReturnConsumedCapacity' => 'TOTAL'
    ));
    $created_region = true;
    if ($debug) {echo "$region_name added<br>\n";}
} else {
    if ($debug) {echo "$region_name found!<br>\n";}
}

// have we seen this screen
if ($debug) {echo "Looking up screen: $screen_id in $region_name<br>\n";}
$result = $client->getItem(array(
    'ConsistentRead' => true,
    'TableName' => 'media_screens',
    'Key'       => array(
        'screen_id'   => array('S' => $screen_id),
        'screen_region_name'   => array('S' => $region_name)
    )
));
if ($debug) {var_dump($result); echo '<br>';}

if (!isset($result['Item']['screen_id']['S'])) {
    // Add this screen
    if ($debug) {echo "$screen_id in $region_name not found, adding screen now<br>\n";}
    $result = $client->putItem(array(
        'TableName' => 'media_screens',
        'Item' => $client->formatAttributes(array(
            'screen_id'      => $screen_id,
            'screen_region_name'    => $region_name,
            'screen_private_ip'    => $screen_private_ip,
            'screen_public_ip'    => $screen_public_ip,
            'screen_last_checkin'    => $time,
            'screen_active'    => true
        )),
        'ReturnConsumedCapacity' => 'TOTAL'
    ));
     if ($debug) {echo "$screen_id in $region_name added<br>\n";}
   
    // Make sure to push this screen onto the region screen list if we didn't just create the region
    if (!$created_region) {
        $result = $client->updateItem(array(
            'TableName' => 'region_name',
            'Key'       => array(
                'region_name'   => array('S' => $region_name)
            ),
            'AttributeUpdates' => array(
                'region_screen_list'   => array('SS' => array($screen_id)),
                'Action' => 'ADD'
            )
        ));
        if ($debug) {echo "$screen_id in $region_name pushed onto region list<br>\n";}
    }

} else {
    // Update the screen_last_checkin and IP values for this screen
    if ($debug) {echo "$screen_id in $region_name found!<br>\n";}
    $result = $client->updateItem(array(
        'TableName' => 'media_screens',
        'Key'       => array(
            'screen_id'   => array('S' => $screen_id),
            'screen_region_name'   => array('S' => $region_name)
        ),
        'AttributeUpdates' => array(
            'screen_private_ip'    =>  array('Action' => 'PUT', 'Value' => array('S' => $screen_private_ip)),
            'screen_public_ip'    =>  array('Action' => 'PUT', 'Value' => array('S' => $screen_public_ip)),
            'screen_last_checkin'    =>  array('Action' => 'PUT', 'Value' => array('N' => $time))
        )
    ));    
    if ($debug) {echo "$screen_id in $region_name updated<br>\n";}

}


// Finally return every screen we know about in a json object
// (could filter this by region, but not expecting this to be very many overall)
$iterator = $client->getIterator('Scan', array('TableName' => 'media_screens'));
$to_ret = array();
foreach ($iterator as $item) {
    $ta = array();
    $ta['screen_id'] = $item['screen_id']['S'];
    $ta['screen_region_name'] = $item['screen_region_name']['S'];
    $ta['screen_private_ip'] = $item['screen_private_ip']['S'];
    $ta['screen_public_ip'] = $item['screen_public_ip']['S'];
    $ta['screen_active'] = $item['screen_active']['N'];
    $to_ret[] = $ta;
}


//if ($debug) {echo '<hr>'; var_dump($to_ret);}
echo json_encode($to_ret);



?>
