<?php
require_once __DIR__ . '/vendor/autoload.php';

use \RouterOS\Client;
use \RouterOS\Query;

error_reporting(0);

// Initiate client with config object
$client = new Client([
    'host' => '192.168.0.1',
    'user' => 'user',
    'pass' => 'password'
]);

$lease = $client->query('/ip/dhcp-server/lease/print')->read();
$collect = [];

foreach ($lease as $key => $value) {
  $query = new Query('/ping');
  $query->equal('address', $lease[$key]['address']);
  $query->equal('count', '1');
  $response = $client->query($query)->read();

  $collect[] = [
    'address' => $lease[$key]['address'],
    'comment' => $lease[$key]['comment'],
    'macaddress' => $lease[$key]['mac-address'],
    'host' => $lease[$key]['host-name'],
    'ping' => $response[0]['time'],
  ];

}

header('Content-Type: application/json');
echo json_encode($collect);

?>
