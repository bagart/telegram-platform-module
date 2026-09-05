<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\SocketClient\Reactor;

final class ReactorEvents
{
    /** @var list<resource> */
    public array $readable = [];

    /** @var list<resource> */
    public array $writable = [];

    /** @var list<resource> */
    public array $exceptional = [];

    /**
     * @param  list<resource>  $readable
     * @param  list<resource>  $writable
     * @param  list<resource>  $exceptional
     */
    public function __construct(
        array $readable = [],
        array $writable = [],
        array $exceptional = [],
    ) {
        $this->readable = $readable;
        $this->writable = $writable;
        $this->exceptional = $exceptional;
    }
}
