<?php

declare(strict_types=1);

use BAGArt\TelegramModuleEngine\Tenancy\BotContext;
use BAGArt\TelegramModuleEngine\Routing\CommandRouteLookup;
use BAGArt\TelegramModuleEngine\Routing\RouteEntry;
use BAGArt\TelegramModuleEngine\Routing\RouteResolver;
use BAGArt\TelegramModuleEngine\Routing\RoutingTable;

/**
 * CommandRouteLookup turns the resolved routing table into a command =>
 * processor map for the update selector: dispatchable entries only, first
 * (priority-honoring, module-id-stable) entry wins on collisions, memoized
 * per bot, refresh() selective.
 */
function lookupTable(array $entries): RouteResolver
{
    return new class ($entries) implements RouteResolver {
        public function __construct(private readonly array $entries)
        {
        }

        public function resolve(BotContext $context): RoutingTable
        {
            return new RoutingTable($context->botId, $this->entries);
        }
    };
}

it('resolves a declared command route to its processor', function () {
    $lookup = new CommandRouteLookup(lookupTable([
        new RouteEntry('menu', 'command', '/menu', payload: ['processor' => 'App\\MenuProcessor']),
    ]));

    expect($lookup->processorOf('menu', 'bot1'))->toBe('App\\MenuProcessor')
        ->and($lookup->processorOf('menu@otherbot', 'bot1'))->toBeNull();
});

it('accepts the telegram.command entry type and strips the slash', function () {
    $lookup = new CommandRouteLookup(lookupTable([
        new RouteEntry('menu', 'telegram.command', '/Menu', payload: ['processor' => 'App\\MenuProcessor']),
    ]));

    expect($lookup->processorOf('Menu', 'bot1'))->toBe('App\\MenuProcessor');
});

it('ignores entries without a processor payload', function () {
    $lookup = new CommandRouteLookup(lookupTable([
        new RouteEntry('menu', 'command', '/menu'),
        new RouteEntry('menu', 'command', '/x', payload: ['processor' => 42]),
    ]));

    expect($lookup->processorOf('menu', 'bot1'))->toBeNull()
        ->and($lookup->processorOf('x', 'bot1'))->toBeNull();
});

it('resolves collisions by priority first, then module id', function () {
    $lookup = new CommandRouteLookup(lookupTable([
        new RouteEntry('mafia', 'command', '/start', priority: 10, payload: ['processor' => 'App\\MafiaStart']),
        new RouteEntry('menu', 'command', '/start', priority: 10, payload: ['processor' => 'App\\MenuStart']),
        new RouteEntry('low', 'command', '/start', priority: 0, payload: ['processor' => 'App\\LowStart']),
    ]));

    expect($lookup->processorOf('start', 'bot1'))->toBe('App\\MafiaStart');
});

it('caches per bot and refresh() is selective', function () {
    $entries = [new RouteEntry('menu', 'command', '/menu', payload: ['processor' => 'App\\MenuProcessor'])];
    $lookup = new CommandRouteLookup(lookupTable($entries));

    expect($lookup->processorOf('menu', 'bot1'))->toBe('App\\MenuProcessor');

    $lookup->refresh('bot2');
    expect($lookup->processorOf('menu', 'bot1'))->toBe('App\\MenuProcessor');

    $lookup->refresh('bot1');
    expect($lookup->processorOf('menu', 'bot1'))->toBe('App\\MenuProcessor');
});
