<?php
/**
 * Aquí se intenta; que si se cae la conexi'on de entrada(msgSock) o la de salida(sockSend), se elimina el objeto y se vuelve a establecer
 * ambas conexiones;
 */
require('./lib/ConnectionHandler.php');
extract(require('config.php'));

error_reporting(E_ALL);

/* Permitir al script esperar para conexiones. */
set_time_limit(0);

/* Activar el volcado de salida implícito, así veremos lo que estamos obteniendo
 * mientras llega. */
ob_implicit_flush();

//Preparing sockets
$sockListen = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sockListen == false) {
    echo "socket_create() listen falló: razón: " . socket_strerror(socket_last_error()) . "\n";
    exit;
}

if (socket_bind($sockListen, $localAddress, $lclPort) === false) {
    echo "socket_bind() para listen falló: razón: " . socket_strerror(socket_last_error($sockListen)) . "\n";
    exit;
}

//Starting to listen
if (socket_listen($sockListen, empty($socketSendSrcPorts) ? 5 : count($socketSendSrcPorts)) === false) {
    echo "socket_listen() listen failed: reason: " . socket_strerror(socket_last_error($sockListen)) . "\n";
    exit;
}
socket_set_nonblock($sockListen);
echo "Listen on $localAddress:$lclPort\n";

$connections = [];
$connectionsToRemove = [];

$outBoundLocalPortEnabled = !empty($socketSendSrcPorts);
$_socketSendSrcPorts = [];
if ($outBoundLocalPortEnabled) {
    $_socketSendSrcPorts = $socketSendSrcPorts;
}

while (true) {

    if (!$outBoundLocalPortEnabled || ($outBoundLocalPortEnabled && !empty($_socketSendSrcPorts))) {
        $sockSendLocalPort = array_shift($_socketSendSrcPorts);
        $con = new ConnectionHandler($sockListen, $remoteAddress, $dstPort, $localAddress, $sockSendLocalPort);
        if($con->isConnected()) {
            $connections[] = $con;
            echo json_encode($_socketSendSrcPorts) . "\n";
        } else {
            unset($con);
            $con = null;
            if ($outBoundLocalPortEnabled) {
                array_unshift($_socketSendSrcPorts, $sockSendLocalPort);
            }
        }
    }

    foreach($connections as $key => $connection) {
        $connection->forward();
        if(!$connection->isConnected()) {
            $connectionsToRemove[] = $key;
        }
    }

    foreach($connectionsToRemove as $index) {
        // echo "removing $index...\n";
        if($outBoundLocalPortEnabled) {
            $_socketSendSrcPorts[] = $connections[$index]->getSockSendSrcPort();
        }
        unset($connections[$index]);
    }

    if(count($connectionsToRemove) != 0) {
        $connectionsToRemove = [];
    }
    usleep(500);
}
