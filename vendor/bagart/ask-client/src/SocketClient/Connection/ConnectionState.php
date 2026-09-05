<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Connection;

enum ConnectionState
{
    case CONNECTING;
    case TLS_HANDSHAKE;
    case READY;
    case WRITING;
    case READING;
    case IDLE;
    case CLOSED;
}
