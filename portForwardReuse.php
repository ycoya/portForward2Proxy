<?php
/**
 * Aquí se trata de que cuando msgSock se cae, se intenta levantar esto sin caer la conexi'on de salida(sockSend) y reusarla. Tambi'en
 * a la inversa, si se cae la conexi'on de salida, tratar de levantarla de nuevo y seguir usando el msgSock de entrada.
 */
require('./lib/ConnectionHandlerReuse.php');
extract(require('config.php'));

if(!defined('SOCKET_EAGAIN')) {
    define('SOCKET_EAGAIN', 10035);
}

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

$checkForNewConn = true;
while (true) {
    // echo "check new connection\n";
    if ($checkForNewConn && (!$outBoundLocalPortEnabled || ($outBoundLocalPortEnabled && !empty($_socketSendSrcPorts)))) {
        $sockSendLocalPort = array_shift($_socketSendSrcPorts);
        echo "starting new connection\n";
        $con = new ConnectionHandlerReuse($sockListen, $remoteAddress, $dstPort, $localAddress, $sockSendLocalPort);
        echo "starting handler\n";
        
        if ($con->isConnected()) {
            echo "starting connected\n";
            $connections[] = $con;
        } else {
            echo "no connected\n";
            if ($outBoundLocalPortEnabled) {
                array_push($_socketSendSrcPorts, $sockSendLocalPort);
            }
        }
    }

    foreach ($connections as $key => $connection) {
        // echo "is connected?\n";            
        if($connection->isConnected()) {
            // echo "forward before\n";
            $connection->forward();
            // echo "forward after\n";
        }

        if (!$connection->isAsocketConnected()) {
            $connectionsToRemove[] = $key;
        } else if (!$connection->isSockSendStatusConnected()) {
            echo "lost connection to server\n";
            $connection->startConx2Server();
        } else if (!$connection->isMsgSockStatusConnected()) {
            echo "lost connection to client\n";
            $checkForNewConn = false;
            $connection->startAcceptCnx();
            if ($connection->isMsgSockStatusConnected()) {
                $checkForNewConn = true;
            }
        }
    }

    foreach ($connectionsToRemove as $index) {
        //echo "removing $index...\n";
        unset($connections[$index]);
    }
    if (count($connectionsToRemove) != 0) {
        $connectionsToRemove = [];
    }
    usleep(500);
}
