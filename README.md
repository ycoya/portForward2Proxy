## Port forward to proxy
This application opens a port and start listen on it and then forward the traffic to a remote proxy. This has the option to set the local **source port** to the outbound connection. This could be helpful in **firewalls rules** that allow traffic from a range of remote ports, the remote ports for them is our **source port** here.


The portForwardReuse.php file is an attempt to avoid droping the two sockets opened when a close connection exist from one of them. Two sockets are opened one for listening and the other to connect to the remote side. If one socket is dropped then it will try to start other one while reusing the socket that is still connected, but this is not actually working, the two dropped at the end.

Use `portForward.php` to relay traffic. If one side stops sending, the proxy
flushes the remaining bytes before shutting down that write side. The other
direction can still carry a response.

Each direction buffers at most 256 KiB of pending data. If the destination
can't keep up, the proxy pauses reads in that direction until the queue drains.

## Configuration

The config_example.php file should be rename to **config.php**, this file return an array that will create the config variables used in the application.

```bash
<?php

return [
    'localAddress' => '127.0.0.1',
    'remoteAddress' => '127.0.0.1',
    'lclPort' => 8081,
    'dstPort' => 8083,
    'socketSendSrcPorts' => [] // [5001, 50002, 5547]
];
```
The **localAddress** is the ip or interface from which the application start to listen.

The **remoteAddress** is the the proxy where the traffic is routed.

The **lclPort** is the listening port, the application waits for new connections.

The **dstPort** is the remote port to connect to, to where the traffic is routed.

The **socketSendSrcPorts** are the sources ports to choose from for the outbound connection to **remoteAddress** and **dstPort**. Realize that if you set 3 source ports, you have only 3 inbound connections accepted. If the **socketSendSrcPorts** is an empty array then the S.O. will choose a random source port as usual.

## Run
To run the script, just open a commandline in the directory of the files, and type
```
C:\portFoward2Proxy>php portForward.php
```

## Tests

With PHP 8 and the sockets extension installed, run:

```sh
python3 tests/test_port_forward.py
```

The tests use local TCP sockets to check half-closes in both directions,
delivery to a slow receiver while a second client transfers, and the configured
outbound source port.
