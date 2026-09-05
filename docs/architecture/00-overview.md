# Telegram Module Engine — Architecture Overview
> Модуль: `bagart/telegram-module-engine`
>
> PHP namespace: `BAGArt\TelegramModuleEngine`
>
> Назначение: единая инфраструктура модульности для Telegram Bot Platform.
>
> Статус: Draft / Architecture RFC
>
> Документ: 00 — Overview (мастер-документ, поглощает бывший 01-architecture)

---
# 1. Purpose
`TelegramModuleEngine` — отдельная инфраструктурная библиотека Telegram Bot Platform, единая точка управления модульностью: обнаружение, описание, регистрация, конфигурация, подключение, отключение, зависимости, lifecycle и runtime-доступность модулей-умений.

Engine является единственным источником истины о том:

- какие модули подключены к платформе; какие модули разрешены платформой; какие capabilities предоставляют модули; какие зависимости существуют между модулями; какие модули доступны конкретному боту; какие модули подключены конкретному боту; в каком состоянии находится module activation; какие contributions предоставляет модуль; какие действия допустимы с module activation.

После внедрения Engine платформа не должна самостоятельно:

- перечислять модули; регистрировать ServiceProvider модулей; перечислять migrations/seeders/routes/frontend resources модулей; собирать permissions и menu contributions; определять доступность модулей; содержать hardcoded список модулей в Management или Menu.

Engine не реализует бизнес-логику конкретных модулей.

Главный принцип:

> Platform Core знает только TelegramModuleEngine. Конкретные модули знают о своих зависимостях и возможностях, но не требуют изменений Platform Core.

На Engine могут опираться:

- Telegram Bot Platform; Telegram Bot Management; Telegram Bot Menu Module; permission system (отдельный модуль `telegram-bot-lib-access`); runtime Telegram handlers; jobs/events; frontend; другие module packages.

---
# 2. Context
Telegram Bot Platform — Laravel-платформа для запуска множества Telegram-ботов.

Платформа имеет:

- множество Telegram bots; отдельную конфигурацию каждого bot; bot-specific groups/roles; bot-specific permission tree; bot-specific module activations; bot-specific module settings; подключаемые modules/skills; общие platform-level modules; опциональную Management module; опциональный Menu module; библиотеку `telegram-bot-lib` для работы с Telegram Bot API; дополнительные reusable libraries и modules.

Физически модули добавляются в платформу Composer-пакетами.

Примеры модулей:

- Menu; Management; Mafia; Cinema Radar; Proxy Operations; Summarizer; другие будущие skills.

Один и тот же module package может использоваться одновременно множеством ботов, но его activation и настройки являются bot-scoped.

---
# 3. Current Problem
В текущей архитектуре module integration размазана по платформе.

В разных местах присутствуют:

- явные ServiceProvider; `foreach` по спискам модулей; отдельные configuration keys; списки seeders и migrations; frontend resource registrations; route registrations; menu registrations; permission registrations; bootstrap logic; module-specific conditions; Management-specific module lists; другие ручные интеграционные точки.

Пример нежелательной архитектуры:

    foreach ((array) config('telegram.modules_seeders', []) as $seeder) {
        $this->call($seeder);
    }
Аналогично для migrations:

    foreach ((array) config('telegram.modules_migrations', []) as $migration) {
        ...
    }
Такая схема создаёт несколько проблем:

1. отсутствует единый module registry;
2. platform code знает детали конкретных modules;
3. Management и Menu должны самостоятельно знать список modules;
4. lifecycle modules не определён централизованно;
5. platform configuration смешивается с bot configuration;
6. module activation смешивается с physical installation;
7. dependencies не имеют единой модели;
8. permissions, menu и admin contributions регистрируются разрозненно;
9. трудно гарантировать tenant isolation;
10. disable/enable semantics не определены единообразно;
11. невозможно безопасно расширять систему без изменения core platform;
12. порядок загрузки модулей может случайно зависеть от порядка регистрации;
13. seeders могут ошибочно использоваться для bot-specific initialization;
14. frontend и backend имеют собственные списки modules;
15. runtime handlers могут продолжать работать после отключения module.
`TelegramModuleEngine` должен устранить эту архитектурную связанность.

---
# 4. Primary Goal
Главная цель Engine:

> Сделать module system декларативной, централизованной, tenant-aware и независимой от конкретных modules.

После внедрения Engine platform code не должен содержать конструкции вида:

    if ($module === 'cinema-radar') {
        ...
    }
или:

    foreach (config('telegram.modules_seeders', []) as $seeder) {
        ...
    }
или:

    $modules = [
        CinemaRadarModule::class,
        MafiaModule::class,
    ];
для управления module lifecycle.

Platform code должен взаимодействовать с Engine через его public contracts.

---
# 5. Non-Goals
`TelegramModuleEngine` НЕ должен:

- реализовывать бизнес-логику modules; знать детали Cinema Radar, Mafia, Proxy Operations, любых конкретных modules; знать конкретные module settings; содержать module-specific conditions; быть админкой; быть menu system; быть permission system (permission system — отдельный модуль `telegram-bot-lib-access`); быть audit system (audit — отдельный модуль); быть Telegram Bot API client; выполнять любые Telegram transport-вызовы (HTTP/API) — Engine не знает Telegram transport; быть очередью; быть scheduler; быть Composer package manager; самостоятельно устанавливать Composer packages; заменять Laravel Service Container; превращаться в универсальный framework plugin system; содержать reconciliation-механику (K8s-style reconcile loops, fencing tokens, desired/observed registries) — применяется apply-on-activation + идемпотентность + вычисляемое effective state.

Engine предоставляет инфраструктурные contracts, а конкретные modules используют их.

---
# 6. Core Architectural Model
Основная модель — три уровня состояния, которые строго разделяются:

    Composer Package
            ↓
    Module Definition
            ↓
    Platform Installation (config/tg_modules.php)
            ↓
    Bot Availability
            ↓
    Bot Module Activation (PostgreSQL)
            ↓
    Runtime
Важно различать:

    Package
    Module
    Installation
    Activation
Это разные сущности. Нельзя использовать один универсальный `enabled` для всех уровней.

Уровень 1 — Composer Package. Отвечает на вопрос: «Код модуля физически установлен?» Установка пакета означает только наличие PHP-кода и НЕ означает включение модуля.

Уровень 2 — Platform Configuration (`config/tg_modules.php`). Отвечает: «Разрешён ли модуль на данной установке платформы и с какой базовой конфигурацией?» Это deployment/configuration state.

Уровень 3 — Bot Activation (база данных). Отвечает: «Подключено ли умение для конкретного Telegram-бота?» Это tenant state.

Нельзя смешивать понятия:

- физически установлен; разрешён платформой; включён для бота; доступен; заблокирован зависимостью; временно выключен; находится в процессе включения.

Поэтому Engine должен возвращать структурированное состояние (см. раздел 34).

---
# 7. Package
Composer package — физический способ доставки module code.

Например:

- `bagart/telegram-bot-cinema-radar`; `bagart/telegram-bot-mafia`; `bagart/telegram-platform-menu`; `bagart/telegram-platform-management`.

Composer package должен быть максимально самодостаточным и может содержать:

- PHP implementation; module ServiceProvider; Module Definition; Platform Config DTO; migrations; seeders; routes; commands; permissions; menu contributions; administration contributions; frontend resources; translations; assets; tests.

Platform Core не должен перечислять эти ресурсы вручную.

Composer package НЕ является module identity.

---
# 8. Stable Module Identity
Каждый module имеет стабильный уникальный `moduleId`.

Пример:

- `menu`; `management`; `cinema-radar`; `mafia`; `proxy-operations`; `summarizer`.

Module ID:

- стабилен; уникален; не зависит от PHP class name; не зависит от ServiceProvider; не зависит от Composer package name; не должен меняться при рефакторинге namespace; не должен меняться при переносе package; используется в persistent state; используется в activation; используется для ownership contributions; используется для diagnostics и audit.

Рекомендуемый формат: `lowercase kebab-case`.

---
# 9. Module Definition
Module Definition описывает, что такое module.

Концептуально:

    ModuleDefinition(
        id: 'mafia',
        name: 'Mafia',
        description: 'Telegram Mafia game',
        serviceProvider: ...,
        dependencies: ...,
        capabilities: ...,
        contributions: ...,
        administration: ...
    )
Definition содержит декларативную metadata:

- module ID; display name; description; version information; provider; dependencies; provided capabilities; optional capabilities; permissions contribution; menu contribution; management contribution; frontend contribution; runtime contribution; settings metadata; lifecycle metadata.

Definition не хранит mutable runtime state: bot id, user id, activation state, database state. Definition должен быть immutable.

Definition не должен выполнять I/O. Definition не должен:

- обращаться к DB; обращаться к Telegram; читать request; определять текущего пользователя; выполнять jobs; изменять application state.

---
# 10. Platform Module Configuration
Все пользовательские platform-level module configuration должны быть централизованы в:

`./config/tg_modules.php`

Этот файл является декларативным списком модулей, подключённых к платформе.

Концептуальный пример:

    return [
        'menu' => new TelegramModuleConfig(
            enabled: true,
            serviceProvider: TelegramBotMenuServiceProvider::class,
            platformConfig: new TelegramBotMenuPlatformConfig(
                userUrl: '/menu',
                adminUrl: '/admin/menu',
            ),
        ),

        'management' => new TelegramModuleConfig(
            enabled: true,
            serviceProvider: TelegramBotManagementServiceProvider::class,
            platformConfig: new TelegramBotManagementPlatformConfig(
                adminUrl: '/admin/telegram',
            ),
        ),

        'cinema-radar' => new TelegramModuleConfig(
            enabled: true,
            serviceProvider: CinemaRadarServiceProvider::class,
            platformConfig: new CinemaRadarPlatformConfig(),
        ),
    ];
`config/tg_modules.php` — PHP-файл, но архитектурно это manifest. Он не должен превращаться в bootstrap script.

Плохо:

    $container->bind(...)
    $router->...
    foreach (...)
    DB::...
Хорошо:

    'module-id' => new TelegramModuleConfig(...)
Engine сам интерпретирует manifest.

`TelegramModuleConfig` — чистый DTO. Он не должен содержать бизнес-методов вроде:

    enable();
    disable();
    register();
    boot();
    save();
    resolve();
Конструктор определяет типы, обязательные параметры и defaults.

Каждый модуль может иметь собственный strongly typed Platform Config DTO:

    MafiaPlatformConfig extends TelegramModulePlatformConfig
    CinemaRadarPlatformConfig extends TelegramModulePlatformConfig
    MenuPlatformConfig extends TelegramModulePlatformConfig
Базовый `TelegramModulePlatformConfig` может содержать общие декларативные поля (menu, seeders, migrations, userUrl, adminUrl, permissions, frontend и другие стандартизированные descriptors). Однако желательно постепенно переносить специализированные поля в отдельные typed descriptors, а не превращать базовый DTO в огромный объект.

Фактический контракт будет определён в отдельных документах (см. doc 16).

---
# 11. Configuration Principles
Module configuration DTO должны быть:

- immutable; декларативными; типизированными; максимально полными; без runtime logic; без I/O; совместимыми с Laravel config cache; пригодными для статического анализа.

По возможности параметры должны быть обязательными.

Значения по умолчанию должны задаваться внутри специализированного config DTO там, где это действительно является частью module contract.

Configuration не должна содержать mutable runtime state.

Configuration must not contain executable user-provided code.

---
# 12. Resolved Module
Нужно различать:

- `ModuleDefinition` — что объявляет package; `TelegramModuleConfig` — что настроил владелец конкретной установки платформы; `ResolvedModule` — результат объединения Definition + Platform Config.

Именно `ResolvedModule` должен использовать Engine дальше.

---
# 13. Platform Enabled vs Bot Enabled
Ключевой принцип:

> `enabled` в `config/tg_modules.php` НЕ означает, что module включён у всех ботов.

Platform configuration означает:

`Module installed/available on platform.`

Bot activation означает:

`Module enabled for this particular bot.`

Например:

    Platform:
        cinema-radar = enabled

    Bot #1:
        cinema-radar = enabled

    Bot #2:
        cinema-radar = disabled

    Bot #3:
        cinema-radar = enabled
Один физический module package обслуживает множество bots. Переключение activation для отдельных ботов не должно требовать изменения Composer или `config/tg_modules.php`.

---
# 14. Three-Level Model
Система имеет три основных уровня: PLATFORM (installed modules, platform configuration, capabilities) → BOT / TENANT (module activations, module settings, permission tree, groups/roles) → USER / GROUP / ROLE (effective permissions).
Multi-Tenant модель:

    Module Package        — 1 физическая установка
    Platform Module       — 1 configuration
    Bot Module Activation — N независимых состояний
    Bot Permissions       — собственные
    Groups                — собственные
    Module Settings       — могут иметь platform/bot/group/user scope
Module installation является platform-scoped.

Module activation является bot-scoped.

Permission grants являются bot-scoped и могут быть назначены roles/groups/users согласно permission system.

---
# 15. Bot as Tenant
Каждый Telegram bot является отдельным tenant context.

Bot может иметь собственные:

- enabled modules; module settings; groups; roles; permission tree; users; menu configuration; module state.

Один module package может использоваться множеством tenants.

Нельзя предполагать, что module activation или module settings являются глобальными.

---
# 16. Bot Module Activation
Для каждого bot module может существовать activation:

    Bot #42
        module: cinema-radar
        status: enabled
Activation является runtime/tenant state.

`BotModuleActivation` минимально содержит:

- `bot_id`; `module_id`; `status`; timestamps.

Должна быть уникальность `bot_id + module_id`.

Engine отвечает за:

- enable; disable; suspend; availability; dependency validation; lifecycle; activation initialization; state transitions.

Module-specific configuration принадлежит конкретному module, а не Engine.

---
# 17. Module Configuration Layers
Необходимо различать:

    Module Defaults
            ↓
    Platform Configuration
            ↓
    Bot Configuration
            ↓
    Runtime Effective Configuration
Например:

    default language = ru
    platform language = en
    Bot #42 language = de
Effective configuration для Bot #42:

    language = de
Engine должен предоставлять механизм resolution, но не обязан знать конкретные поля module settings.

Важно не считать `Bot Module Enabled` эквивалентом `Feature Enabled Everywhere`. В будущем effective state может иметь больше уровней:

    Platform
        ↓
    Bot
        ↓
    Group
        ↓
    User
Engine должен не блокировать эту модель архитектурно (см. раздел 74).

---
# 18. Module-Specific Settings
Engine не должен хранить знания о конкретных настройках modules.

Например:

    Cinema:
        sources
        language
        ratings
является ответственностью Cinema module.

Engine знает только:

    Cinema has bot-specific settings.
Module-specific persistent data принадлежит module.

Экраны настроек: module предоставляет settings descriptor (schema) как contribution; рендеринг экранов настроек выполняется в Management/Menu, а не в Engine.

---
# 19. Module Dependencies
Modules должны объявлять явные зависимости.

Поддерживаются:

- required dependency; optional dependency; provided capability.

Пример:

    Cinema Radar
        requires:
            telegram.runtime

        optional:
            telegram.menu
Engine должен уметь обнаруживать:

- missing dependency; disabled dependency; incompatible dependency; circular dependency; unavailable capability.

Никаких скрытых зависимостей через случайно зарегистрированный ServiceProvider.

Вместо жёсткой зависимости от конкретного module package по возможности используется capability.

---
# 20. Capabilities
Module может предоставлять capabilities:

- `telegram.menu`; `telegram.navigation`; `telegram.media`; `telegram.scheduler`; другие capability IDs.

Другой module может требовать capability:

    requires:
        telegram.navigation
Это позволяет заменить реализацию capability другим module.

Лучше:

    requires:
        telegram.navigation
чем:

    requires:
        telegram-platform-menu
Module Dependency и Capability Dependency должны быть разными понятиями.

Capability availability должна рассчитываться с учётом bot activation.

Platform-level capability:

    Menu installed
не означает:

    Menu available for every bot
Bot-level capability:

    Bot #42 → telegram.navigation = available
является effective runtime state.

---
# 21. Dependency Graph
Должны существовать два уровня dependency resolution:

    Platform Dependency Graph
    Bot Activation Dependency Graph
Например:

    Platform:
        Menu installed
        Cinema installed

    Bot #42:
        Menu enabled
        Cinema enabled
Если:

    Bot #42:
        Menu disabled
        Cinema requested enabled
Engine должен определить, что Cinema cannot be activated, если Menu является required dependency.

---
# 22. Required vs Optional Dependencies
Required dependency:

    dependency unavailable
        ↓
    module cannot become READY
Optional dependency:

    dependency unavailable
        ↓
    module remains operational
        ↓
    related contribution/capability unavailable
Module должен иметь возможность работать в reduced mode, если optional capability отсутствует.

---
# 23. Dependency Disable Policy
По умолчанию отключение required dependency не должно автоматически каскадно выключать зависимые modules.

Предпочтительное поведение:

    Cannot disable Menu.

    Required by:
        Cinema Radar
        Mafia
Администратор должен явно подтвердить destructive/cascade operation, если такая операция будет поддерживаться.

Это предотвращает неожиданные изменения большого количества bot activations.

---
# 24. Module Lifecycle
Module lifecycle должен быть формализован.

Platform-level lifecycle:

    discovered
    registered
    validated
    installed
    enabled
    disabled
    uninstalled
Bot-level lifecycle:

    available
    enabled
    disabled
    suspended
    blocked
    ready
    failed
Точная state machine определена в `40.md` (lifecycle).

Включение модуля — не просто `UPDATE enabled = 1`. Предпочтительный процесс:

    request
        ↓
    resolve module
        ↓
    validate configuration
        ↓
    validate dependencies
        ↓
    authorize
        ↓
    ENABLING
        ↓
    initialize
        ↓
    ENABLED
        ↓
    publish event
        ↓
    invalidate snapshots
При ошибке: FAILED или полный rollback.

Отключение также должно проходить через Engine. Не:

    activation.enabled = false
а:

    Engine → disable(bot, module)
Engine отвечает за validation, lifecycle, persistence, events, cache invalidation, audit (публикацию данных для audit-модуля).

---
# 25. Disable != Uninstall
Отключение module:

    disable
не должно удалять module data.

Uninstall:

    uninstall
является отдельной destructive operation.

По умолчанию module data должны сохраняться при disable.

Удаление данных должно быть отдельной явно подтверждаемой операцией.

---
# 26. Module Contributions
Module не должен напрямую модифицировать чужие subsystems.

Вместо этого module декларативно предоставляет contributions.

Основные виды:

- Menu Contribution; Permission Contribution; Management Contribution; Command Contribution; Route Contribution; Frontend Contribution; Event Contribution; Job Contribution; Scheduler Contribution; Settings descriptor contribution.

Дополнительные примеры: administration page, administration navigation, bot settings, dashboard widgets.

Engine должен иметь единый механизм регистрации и получения contributions. Но Engine не должен сам отображать Menu или Admin UI.

Детальный контракт contributions определён в `33.md`.

### Typed external-key vocabulary
Engine не знает Telegram transport и не выполняет HTTP/API вызовов. Однако Engine хостит типизированный словарь внешних ключей для проверки коллизий между contributions:

- `telegram.command`; `telegram.callback`; `http.route`; `menu_item`; `webhook.endpoint`.

Collision detection выполняется в рамках этих типов ключей; интерпретация ключей (реальная регистрация command/route/webhook) — ответственность потребителей, а не Engine.

---
# 27. Contribution Ownership
Каждая contribution должна иметь owner:

`owner = moduleId`

Например:

- `cinema.menu.main`; `cinema.permission.read`; `cinema.admin.settings`.

Это позволяет:

- определить источник contribution; удалять/отключать contribution при module disable; корректно выполнять uninstall; диагностировать конфликты; строить audit; предотвращать duplicate registration.

Contributions должны быть идемпотентными.

---
# 28. Permissions
Permission SYSTEM является отдельным модулем (`telegram-bot-lib-access`). Engine только хостит permission DEFINITIONS, декларативно объявляемые модулями.

Разделение ответственности:

- Engine: какие permissions существуют (каталог `module → permissions`)?
- Authorization (access module): имеет ли пользователь permission?

Module не владеет permission grants.

Например module может объявить:

- `cinema.read`; `cinema.search`; `cinema.manage`.

Bot владеет своим permission tree.

Permission grants могут назначаться:

- roles; groups; users.

согласно permission system (access module).

Таким образом:

    Module
        ↓
    Permission Definitions
        ↓
    Bot Permission Catalog
        ↓
    Roles / Groups / Users
        ↓
    Effective Permissions
Permissions должны учитывать tenant scope. Потенциальные scopes:

- platform; bot; group; user/role.

Module не должен создавать глобальные user permissions.

---
# 29. Permission Ownership
Каждая permission definition должна иметь:

- `moduleId`;
- `permissionId`.

Например:

    module = cinema-radar
    permission = cinema.manage
Это позволяет определить, какие permissions принадлежат module.

При disable permissions не должны автоматически уничтожаться, чтобы не разрушать существующие grants.

Runtime availability должна учитывать module activation.

---
# 30. Groups and Roles
Каждый bot имеет собственные groups/roles.

Module может объявлять recommended role/group definitions.

Например:

    cinema-manager
Но module не должен предполагать существование конкретной группы во всех bots.

Module может предложить:

    recommended role:
        cinema-manager
а Bot Management может создать её по запросу администратора.

---
# 31. Menu Integration
Menu (`telegram-platform-menu`) является отдельным module. Правильное направление зависимости:

    Menu
        ↓
    TelegramModuleEngine
а не наоборот. Engine не знает Menu.

Это позволяет:

- запускать платформу без Menu; заменить Menu; иметь другой navigation provider; тестировать Engine отдельно.

Menu получает от Engine:

    Engine → effective menu contributions for bot
и строит собственное дерево.

Menu не должен знать заранее Mafia, Cinema Radar, Proxy Operations, Summarizer и другие skills.

Module может объявить menu contribution. Например:

    Cinema Radar
        → Search
        → Radar
Если module disabled для bot:

    Cinema Radar = OFF
его menu contributions не должны быть доступны этому bot.

Если user не имеет соответствующего permission:

    cinema.read
menu visibility должна дополнительно фильтроваться permission system (access module).

Таким образом:

    Module Activation
            ↓
    Menu Contribution
            ↓
    Permission Filtering
            ↓
    User Menu
---
# 32. Management Integration
Management (`telegram-platform-management`) является optional module и consumer Engine API. Правильное направление:

    Management
        ↓
    TelegramModuleEngine
Engine не знает Management.

Management не должен содержать module-specific knowledge. Нельзя:

    if ($module === 'cinema-radar') {
        ...
    }
в Management. Management не должен иметь:

    foreach (known modules)
или hardcoded module registry.

Management получает от Engine динамически:

- installed modules; available modules; enabled modules; blocked modules; module status; module metadata; module administration descriptors; module settings schemas; module navigation contributions; dependencies; diagnostics; permissions metadata.

Management является UI consumer: визуализирует и вызывает Engine, но:

- не владеет module registry; не хранит собственный список modules; не знает concrete module classes; не управляет lifecycle напрямую; не знает module-specific settings; не содержит module-specific conditions.

---
# 33. Platform Administration
Общая platform administration должна динамически показывать установленные modules.

Например:

    Platform
    └── Modules
        ├── Menu
        ├── Management
        ├── Cinema Radar
        └── Proxy Operations
Список не должен быть hardcoded в Management.

После установки нового module и добавления его в `config/tg_modules.php` он автоматически становится доступен для platform administration.

Architecture должна поддерживать две админки:

Global Platform Administration — настройка самой установки платформы:

- доступность модулей; platform configuration; диагностика; module health; installed packages.

Bot Administration — для конкретного bot/token:

- подключённые skills; доступные skills; blocked skills; module configuration; permissions; groups; module-specific settings.

Обе UI используют один Engine.

---
# 34. Bot Administration и Module Availability
Админка конкретного bot должна показывать как минимум:

    Bot #42
    ├── Enabled Skills
    │   ├── Menu
    │   ├── Cinema Radar
    │   └── Proxy
    │
    ├── Available Skills
    │   ├── Mafia
    │   └── Summarizer
    │
    └── Blocked Skills
        └── ...
Для каждого module должна быть видна:

- current status; enabled/disabled; can enable; can disable; blocking reasons; dependencies; warnings; configuration; permissions; module administration.

Engine должен предоставлять resolved `ModuleAvailability`. Availability должна позволять UI определить:

- installed; platformEnabled; botEnabled; available; canEnable; canDisable; status; blockingReasons; warnings.

Management не должен самостоятельно вычислять эти условия.

Примеры структурированного состояния:

    installed: true
    platformEnabled: true
    botEnabled: false
    available: true
    canEnable: true
    blockingReasons: []
Другой пример:

    installed: true
    platformEnabled: true
    botEnabled: false
    available: false
    canEnable: false
    blockingReasons:
        - dependency:menu-disabled
---
# 35. Module Snapshot
Engine должен уметь построить immutable read model состояния modules для конкретного бота:

    BotModuleSnapshot
        bot
        modules[]
            moduleId
            status
            availability
            capabilities
            permissions
            contributions
            admin metadata
        revision
В него входят:

- доступные модули; включённые модули; заблокированные модули; dependencies; capabilities; contributions; administration descriptors; menu descriptors; permissions metadata.

Построение snapshot:

    Engine
        ↓
    resolve platform modules
        ↓
    resolve bot activations
        ↓
    resolve dependencies
        ↓
    resolve capabilities
        ↓
    resolve contributions
        ↓
    BotModuleSnapshot
Snapshot является вычисляемым effective state (не хранимым регистром), предназначен для:

- Management; diagnostics; frontend; runtime; tests; support/debugging.

Это основной объект, который должны потреблять Management, Menu и runtime.

---
# 36. Dynamic Administration UI
Module может предоставлять administration metadata.

Administration может содержать:

- Platform Admin; Bot Admin; Navigation; Settings Schema; Health/Diagnostics; Permissions UI.

Management использует эти descriptors для построения динамической UI.

По умолчанию предпочтителен declarative UI через schemas.

Если module требует сложный интерфейс, допускается custom frontend component.

---
# 37. Settings Schema
Module может предоставить schema своих настроек.

Schema может использоваться для:

- validation; generic forms; Management UI; documentation; API; frontend; defaults.

Management не должен знать конкретные поля module settings.

---
# 38. Frontend Contributions
Frontend module integration должна быть динамической.

Module может предоставить:

- navigation item; settings page; dashboard page; custom React component; frontend asset entry; module-specific routes.

Frontend не должен иметь hardcoded module list.

Backend Engine отдаёт frontend resolved metadata.

---
# 39. Runtime Integration
Module activation должна учитываться во всех runtime entry points:

- Telegram commands; callback handlers; message handlers; middleware; web routes; webhook processing; Mini Apps; scheduled commands; queue jobs; event listeners.

Если module отключён для bot, его runtime behavior не должен выполняться для этого bot.

### Long-running процессы
Платформа содержит long-running процессы: Telegram pollers, queue workers, daemons. Поэтому недостаточно проверить module activation только во время bootstrap.

Если администратор отключил skill, уже запущенный worker может иметь устаревшие данные. Engine должен поддерживать:

- проверку activation непосредственно перед выполнением операции (guard на execution boundary); cache invalidation через lifecycle events; refresh вычисляемого effective state по требованию.

Это не reconcile loop: Engine ничего не «сходится» к desired state в фоне; state применяется в момент activation, а consumers вычисляют effective state заново, когда получают событие об изменении.

---
# 40. Jobs and Scheduled Tasks
Module lifecycle должен учитывать jobs и scheduled tasks.

После disable:

- новые jobs не должны планироваться; существующие jobs должны проверять current module activation перед выполнением; module-specific runtime operations должны быть безопасно прекращены.

Module disable не должен требовать удаления исторических jobs/data.

---
# 41. Events
Module event listeners должны учитывать activation.

Если module disabled:

    event
        ↓
    module handler
        ↓
    activation check
        ↓
    skip
Необходимо избежать ситуации, когда module продолжает реагировать на events после disable.

### Lifecycle events Engine
Engine должен публиковать структурированные события:

    ModulePlatformEnabled
    ModulePlatformDisabled

    BotModuleEnabling
    BotModuleEnabled
    BotModuleDisabling
    BotModuleDisabled
    BotModuleActivationFailed
События нужны для:

- cache invalidation; audit (данные для audit-модуля); Management notifications; runtime refresh; интеграций.

События не заменяют queries.

---
# 42. Laravel Integration
Engine интегрируется с Laravel через собственный ServiceProvider.

Laravel integration отвечает за:

- config loading; Engine registration; module registration; migrations; routes; commands; event registration; frontend resources; boot ordering.

Но Laravel ServiceProvider не является module identity.

`serviceProvider` в `TelegramModuleConfig` объявляет Laravel integration provider конкретного модуля. Но:

- ServiceProvider — механизм Laravel;
- Module — самостоятельная архитектурная сущность.

Engine должен контролировать регистрацию module providers. Platform Core не должен иметь:

    MafiaServiceProvider::class
    CinemaRadarServiceProvider::class
    ...
в своих bootstrap-классах.

### Критический вопрос Laravel Bootstrap
Нужно отдельно определить, какие вещи требуют регистрации до полного runtime Engine:

- ServiceProvider; migrations; routes; commands; frontend assets.

Особенно важно определить: может ли provider физически не регистрироваться при `enabled=false`, или часть metadata должна быть доступна раньше? Это будет отдельным документом про Laravel Bootstrap. Нельзя оставлять это неявным (см. Open Questions).

---
# 43. Registration Order
Порядок modules в:

`config/tg_modules.php`

не должен влиять на корректность системы.

Если между modules существуют dependencies, Engine должен построить dependency graph и определить корректный registration/boot order.

Нельзя полагаться на случайный порядок `foreach`.

---
# 44. Migrations
Module migrations являются частью package/module installation lifecycle.

Текущий подход:

    foreach ((array) config('telegram.modules_migrations', []) as $migration)
должен исчезнуть.

Модуль сам объявляет свои migrations. Engine должен централизовать регистрацию module migrations и предоставляет Laravel integration для этого. При этом Laravel migration system остаётся ответственным за фактическое выполнение migrations. Engine не должен превращаться в собственную систему миграций.

---
# 45. Seeders
Текущий подход:

    foreach ((array) config('telegram.modules_seeders', []) as $seeder) {
        $this->call($seeder);
    }
должен исчезнуть.

Seeder принадлежит модулю.

Необходимо различать:

- Platform Reference Seed; Bot Activation Initialization; Module Default Data; Test/Development Seed.

Нельзя использовать один глобальный список seeders для всех lifecycle операций.

Нельзя автоматически запускать все seeders каждого модуля при каждом общем `db:seed`.

Особенно запрещено автоматически проходить по всем bots при обычном platform migration/bootstrap.

Bot-specific initialization должна выполняться только в рамках activation lifecycle.

---
# 46. Activation Initialization
При первом включении module для bot может потребоваться:

- создать defaults; создать module-specific settings; создать required records; зарегистрировать bot-scoped state.

Это является частью Bot Module Activation lifecycle, а не глобального platform seeding.

Initialization должна быть:

- idempotent; transactional where possible; tenant-scoped.

---
# 47. Persistence
PostgreSQL является единственным source of truth для registry-состояния, activations и routing-данных времени выполнения (вместе с Composer и `config/tg_modules.php` как deployment-источниками, см. раздел 63).

Engine persistence должен хранить только состояние, которым действительно владеет Engine.

Например, таблица `bot_module_activations` может содержать:

- `bot_id / tenant_id`; `module_id`; `status`; timestamps (`enabled_at`, `disabled_at`, `created_at`, `updated_at`).

Конкретная схема определена в `32.md` (storage and tenancy).

Module-specific settings/data должны принадлежать module.

---
# 48. Tenant Isolation
Любое bot-specific module state должно быть tenant-scoped.

Запрещается полагаться только на:

`module_id`

при работе с bot-specific state.

Для bot-specific data должен быть определён bot/tenant scope.

Cache keys, settings, permissions, menu contributions и runtime state должны учитывать tenant.

---
# 49. Cross-Tenant Safety
Engine и module APIs должны минимизировать возможность случайного обращения к данным другого bot.

Предпочтительный API:

    $module->forBot($bot)
или эквивалентный explicit Bot Context.

Не следует иметь API, где bot context неочевиден.

---
# 50. Bot Module Context
Для runtime module operations должен существовать явный bot/module context.

Conceptually:

    BotModuleContext
        bot
        module
        activation
        settings
        capabilities
        permissions
Context не должен превращаться в God Object.

Конкретная структура будет определена после проектирования domain contracts.

---
# 51. Caching
Необходимо различать:

- Configuration Cache; Runtime Cache; Bot Module Cache; Permission Cache; Menu Cache; Availability Cache.

Bot-scoped caches должны иметь tenant-safe keys.

Нельзя кэшировать bot-specific availability только по:

`module:{moduleId}`

если результат зависит от bot.

Invalidation выполняется через lifecycle events Engine (см. раздел 41).

---
# 52. Concurrency
Два администратора могут одновременно менять один module.

Нужны:

- unique `(bot_id, module_id)`; transaction; locking или optimistic concurrency; idempotent operations; корректная обработка race condition.

Нельзя получить:

- два activation records

или:

- database = ENABLED, initialization = FAILED

без понятного состояния.

---
# 53. Security
Engine должен обеспечивать архитектурные boundaries:

- tenant isolation; authorization; safe module activation; safe configuration; no arbitrary execution from admin input; no module-specific code execution from untrusted data; safe frontend registration; safe route registration.

Configuration must not contain executable user-provided code.

Frontend visibility не является authorization. Даже если module item скрыт в UI, backend должен проверять:

- bot context; module activation; permission; authorization.

для каждого защищённого operation.

Module activation checks должны выполняться на backend/runtime boundary. Нельзя полагаться только на:

- menu hidden; button disabled; frontend route unavailable.

как на защиту.

---
# 54. Authorization
Необходимо различать права:

- Platform Administration; Bot Administration; Module Configuration; Module Activation; Permission Management.

Например:

Platform administrator может:

- install/enable/disable platform module.

Bot administrator может:

- enable/disable module for Bot #42;
- configure module for Bot #42.

Bot administrator не должен автоматически получать право изменять platform configuration.

---
# 55. Audit
Изменения module state должны быть аудируемыми.

Audit SYSTEM является отдельным модулем; Engine публикует структурированные lifecycle events (см. раздел 41), которые audit-модуль фиксирует. Engine не реализует хранение audit log сам.

Как минимум в событиях должны присутствовать данные для фиксации:

- actor; tenant/bot; module; operation; old state; new state; timestamp; source.

Примеры операций:

- `platform-module-enabled`; `platform-module-disabled`; `bot-module-enabled`; `bot-module-disabled`; `bot-module-suspended`; `module-config-changed`.

Audit должен быть tenant-aware.

---
# 56. Idempotency and Atomicity
Lifecycle operations должны быть идемпотентными.

Например:

    enable(module)
для уже enabled module не должен создавать duplicate state.

То же относится к:

- registration; contributions; initialization; seed operations; activation; disable.

Идемпотентность + apply-on-activation + вычисляемое effective state заменяют reconciliation engine: Engine не содержит фоновых reconcile loops, fencing tokens, desired/observed state registries.

Изменение activation должно быть максимально атомарным.

Концептуально:

    validate
        ↓
    check dependencies
        ↓
    create/update activation
        ↓
    initialize module state
        ↓
    register/publish required state
        ↓
    commit
При failure не должно оставаться частично активированного module state.

Конкретные transaction boundaries будут определены позднее.

---
# 57. Error Model and Diagnostics
Engine должен иметь структурированные ошибки для:

- module not found; module not installed; platform disabled; dependency unavailable; capability unavailable; activation blocked; invalid configuration; lifecycle conflict; tenant access denied; module initialization failure; runtime failure.

Errors должны позволять Management показать человеку понятную причину.

Диагностика должна быть first-class capability. Management должен уметь спросить:

> Почему Mafia нельзя включить для этого бота?

И получить структурированный ответ:

    status: blocked

    reasons:
        - dependency:telegram.navigation

    dependency:
        navigation:
            status: unavailable
Это гораздо лучше, чем:

    enabled = false
Engine должен предоставлять diagnostics API/CLI. Например:

    php artisan tg:modules
    php artisan tg:modules:validate
    php artisan tg:modules:diagnose
    php artisan tg:modules:bot 42
Диагностика должна показывать:

- installed modules; enabled modules; dependencies; capabilities; failures; bot activations; blocked modules; configuration problems.

---
# 58. Configuration Validation
Должна существовать возможность проверить:

`config/tg_modules.php`

до фактического runtime использования.

Например:

    php artisan tg:modules:validate
Должны обнаруживаться:

- duplicate module IDs; missing providers; invalid definitions; missing dependencies; cyclic dependencies; invalid configuration; unavailable capabilities; conflicting contributions (включая коллизии external keys: `telegram.command`, `telegram.callback`, `http.route`, `menu_item`, `webhook.endpoint`).

---
# 59. Module Health
Module может предоставлять health/diagnostic information.

Engine не должен знать business-specific health checks.

Он только агрегирует:

- module status; dependency status; runtime status; health status.

---
# 60. Module Isolation
Module не должен напрямую модифицировать internals другого module.

Например, Cinema не должен напрямую менять:

- Menu internal tables; Management internal state; Permission internal tables.

Вместо этого используются public contracts/contributions.

Модули никогда не коммуницируют друг с другом напрямую: взаимодействие возможно только через Engine contracts (contributions/capabilities). Библиотеки (`telegram-bot-lib`, `telegram-bot-lib-basic` и др.) связываются с модулями через обычные composer-зависимости — это не module communication.

---
# 61. Engine API
Engine API разделяется на queries и commands.

Queries:

    modules()
    module(id)
    snapshot(bot)
    availability(bot, module)
    capabilities(bot)
    contributions(bot)
    diagnostics(bot)
Commands:

    enable(bot, module)
    disable(bot, module)
В дальнейшем:

    configure(bot, module)
Изменение configuration должно быть отдельным use case.

Предварительный фасад:

    interface TelegramModuleEngine
    {
        modules(): ModuleCollection;

        module(ModuleId|string $id): ResolvedModule;

        snapshot(Bot $bot): BotModuleSnapshot;

        availability(Bot $bot, ModuleId|string $module): ModuleAvailability;

        enable(Bot $bot, ModuleId|string $module): BotModuleActivation;

        disable(Bot $bot, ModuleId|string $module): BotModuleActivation;

        capabilities(Bot $bot): CapabilityCollection;

        contributions(Bot $bot): ContributionCollection;

        diagnostics(Bot $bot, ModuleId|string $module): ModuleDiagnostics;
    }
Это предварительный контракт. Финальные интерфейсы будут разработаны в отдельных документах.

### Запрет прямой мутации
Нельзя:

    $activation->enabled = true;
    $activation->save();
Правильно:

    $engine->enable($bot, 'mafia');
Так Engine гарантирует единую последовательность:

    authorization
        ↓
    validation
        ↓
    dependencies
        ↓
    persistence
        ↓
    lifecycle
        ↓
    events
        ↓
    invalidation
---
# 62. Internal Structure
`TelegramModuleEngine` — публичная фасадная точка. Внутри ответственность разделена (названия могут измениться после проектирования contracts):

    TelegramModuleEngine
    ├── ModuleRegistry
    ├── ModuleDefinitionResolver
    ├── ModuleConfigRepository
    ├── ModuleDependencyResolver
    ├── CapabilityRegistry
    ├── ContributionRegistry
    ├── ModuleAvailabilityResolver
    ├── BotModuleRepository
    ├── BotModuleActivator
    ├── ModuleLifecycleManager
    ├── ModuleSnapshotBuilder
    └── ModuleDiagnostics
`ModuleRegistry` отвечает за:

- регистрацию Module Definition; lookup по id; проверку duplicate id; перечисление модулей; получение metadata.

Он НЕ отвечает за:

- bot activation; permissions evaluation; business logic; migrations; seeders; UI rendering.

### Слои Engine
Engine желательно разделить на:

    Domain
    Application
    Infrastructure
    Laravel
Domain — чистые models, value objects, enums, contracts. Без Laravel, Eloquent, Redis, HTTP, Telegram API.

Application — use cases: resolve, enable, disable, dependency validation, availability, snapshot, diagnostics.

Infrastructure — реализации: repositories, cache, persistence, configuration, events.

Laravel — интеграция: ServiceProvider, config, commands, migrations, routes, frontend, container bindings.

---
# 63. Source of Truth
Должны существовать только три основных источника состояния:

    Composer
        ↓
    physically installed packages

    config/tg_modules.php
        ↓
    platform module configuration

    PostgreSQL
        ↓
    bot module activations и runtime tenant state
PostgreSQL — единственная БД-источник истины для registry/activations/routing. Нельзя создавать дополнительные независимые module registries без архитектурного обоснования.

`config/tg_modules.php` является deployment/platform configuration.

Bot activation является mutable runtime state.

Нельзя записывать bot activation в:

`config/tg_modules.php`

и нельзя использовать Laravel config как источник истины для tenant-specific activation.

---
# 64. Snapshot as Integration Boundary
Consumers, которым требуется состояние modules, должны по возможности использовать Engine snapshot/resolver API.

Например:

- Management; Menu; Diagnostics; Runtime.

не должны самостоятельно собирать:

- config; DB; ServiceContainer; module classes.

для определения module state.

---
# 65. Dependency Direction
Направление зависимостей:

    Platform
        ↓
    TelegramModuleEngine
        ↓
    Module Contracts
        ↑
    Concrete Modules
Engine не зависит от:

- Mafia; Cinema Radar; Menu; Management; Proxy; Basic.

`telegram-bot-lib` — отдельная библиотека работы с Telegram Bot API (Telegram API abstraction). Engine не должен зависеть от `telegram-bot-lib`, если это не необходимо для самого Engine. Это разные уровни:

- `telegram-bot-lib` — Telegram API abstraction;
- `TelegramModuleEngine` — module infrastructure.

Engine не выполняет Telegram transport-вызовов и не содержит HTTP/API client logic.

Module runtime может использовать `telegram-bot-lib` (через composer-зависимость), если это необходимо. Menu module может технически не зависеть от `telegram-bot-lib`, если его функции этого не требуют.

---
# 66. Optional Modules
Следующие компоненты могут быть optional:

- Management; Menu; Basic; Cinema; Mafia; Proxy; другие modules.

Engine должен работать без Management.

Engine должен работать без Menu.

Отсутствие optional module не должно ломать platform bootstrap.

---
# 67. Basic Module
`telegram-bot-lib-basic` является ознакомительным/примерным module/library layer.

Engine не должен содержать специальных условий для Basic.

Basic не должен иметь специальных привилегий в Platform Core.

Basic должен использовать те же public contracts, что и любой другой module. Если нужны examples/extensions, они должны демонстрировать реальные Engine contracts.

---
# 68. Future Feature-Level Activation
Первая версия Engine работает на уровне module:

    Cinema = ON/OFF
Архитектура должна не препятствовать будущему:

    Cinema
        Search = ON
        Radar = ON
        Torrents = OFF
Аналогично, effective state в будущем может расшириться до цепочки Platform → Bot → Group → User.

Feature-level activation не является обязательной частью первой версии.

---
# 69. Future Module Marketplace
Engine не обязан поддерживать marketplace.

Но module identity и Definition должны позволять в будущем:

- module catalog; compatibility metadata; version information; capabilities; requirements; installation metadata.

Remote/untrusted module execution не является частью текущей задачи.

---
# 70. Backward Compatibility
Внедрение Engine должно учитывать существующую платформу.

Нужно постепенно заменить на Engine contracts:

- legacy module configuration (`telegram.modules_*`); legacy provider lists; legacy seeder lists; legacy migration registration; legacy frontend registration; legacy menu registration; legacy management registration; legacy bootstrap loops.

Engine должен постепенно забрать из Platform:

- Bootstrap: module providers, module initialization.; Database: module migration registration, module seed registration.; Frontend: module resource registration.; Menu: module discovery.; Management: module discovery, module status, module availability, admin links.; Permissions: module permission catalog (definitions; grants остаются в access module).; Runtime: module activation lookup, effective module resolution.; Configuration: `telegram.modules_*` legacy configuration.

Migration должна быть поэтапной. Не требуется одномоментный rewrite всей платформы.

После миграции платформа должна выглядеть концептуально так:

    Laravel
        ↓
    TelegramModuleEngine
        ↓
    Modules
Если появляется новый модуль, Platform Core не меняется.

---
# 71. Architectural Tests
Главный архитектурный тест: добавление нового skill не должно требовать изменения Platform Core. Если приходится править Platform Core — модульность недостаточна.

### Add New Module Test
    composer require bagart/telegram-bot-example
    # + config/tg_modules.php:
    'example' => new TelegramModuleConfig(...)
После этого Engine автоматически предоставляет:

- registry; availability; bot activation; dependency information; menu contributions; administration contributions; permissions metadata; diagnostics.

### Bot Activation Test
Один package может быть установлен один раз. Но:

    Bot A → Example ON
    Bot B → Example OFF
    Bot C → Example ON
Это не должно требовать изменения Composer или `config/tg_modules.php`.

### Menu Removal Test
Если Menu отключён (`Menu = platform disabled`), то:

- независимые modules продолжают работать; modules requiring navigation становятся blocked; Management показывает причину; runtime не считает navigation capability доступной.

### Management Removal Test
Если Management package удалить:

- Engine продолжает работать; боты продолжают работать; skills продолжают работать; только Management UI исчезает.

### Long-running Process Test
Сценарий: worker запущен, Mafia enabled. Затем admin disables Mafia. Worker не должен бесконечно продолжать исполнять Mafia из устаревшего snapshot. Он должен:

    detect change (lifecycle event / activation check)
        ↓
    refresh computed state
        ↓
    stop/skip Mafia execution
---
# 72. Architectural Invariants
Следующие правила являются обязательными архитектурными инвариантами.

### I-01
Module ID стабилен и не зависит от PHP class name или Composer package name.

### I-02
Platform installation и Bot activation являются разными сущностями.

### I-03
`config/tg_modules.php` не содержит bot-specific activation state.

### I-04
Bot module activation всегда является tenant-scoped.

### I-05
Management не содержит concrete module knowledge.

### I-06
Menu не содержит concrete module knowledge.

### I-07
Engine не содержит business logic конкретных modules.

### I-08
Module-specific settings принадлежат module.

### I-09
Permission definitions хостятся Engine (объявлены модулями); permission system (grants, evaluation) является отдельным модулем `telegram-bot-lib-access`.

### I-10
Все contributions имеют module ownership.

### I-11
Disable не удаляет module data.

### I-12
Uninstall является отдельной destructive operation.

### I-13
Required dependencies проверяются до activation.

### I-14
Optional dependencies не должны блокировать module.

### I-15
Порядок module declaration не влияет на корректность system.

### I-16
Module definitions/configuration не выполняют I/O.

### I-17
Bot-specific cache/state не может быть shared между tenants.

### I-18
Runtime handlers должны учитывать module activation.

### I-19
Jobs и scheduled tasks должны учитывать module activation.

### I-20
Frontend visibility не заменяет backend authorization.

### I-21
Lifecycle operations должны быть идемпотентными.

### I-22
Management получает module list через Engine.

### I-23
Menu получает module contributions через Engine.

### I-24
Platform module configuration и runtime bot state не смешиваются.

### I-25
Module-specific code не должен напрямую изменять internal state другого module.

### I-26
Модули не коммуницируют друг с другом напрямую; библиотеки связываются только через composer-зависимости.

### I-27
Engine не выполняет Telegram transport-вызовов (HTTP/API); он хостит только типизированный словарь внешних ключей (`telegram.command`, `telegram.callback`, `http.route`, `menu_item`, `webhook.endpoint`) для collision detection.

### I-28
PostgreSQL — единственный source of truth для registry/activations/routing state.

### I-29
Никаких reconciliation-механик (reconcile loops, fencing tokens, desired/observed registries): применяется apply-on-activation + идемпотентность + вычисляемое effective state.

### I-30
Audit system является отдельным модулем; Engine только публикует lifecycle events с данными для аудита.

---
# 73. Expected Result
После внедрения `TelegramModuleEngine` добавление нового module должно сводиться к:

1. Install Composer package.
2. Provide module definition.
3. Add module to `config/tg_modules.php`.
4. Run required platform migrations.
5. Module becomes available to Platform.
6. Bot Management автоматически показывает его как available.
7. Administrator enables it for selected bots.
8. Module initializes bot-specific state.
9. Module contributions become active.
При этом не требуется вручную изменять:

- Platform bootstrap; Management module list; Menu module list; Permission registry list; Seeder foreach; Frontend module list.

---
# 74. Design Principle
Главное правило всей системы:

> TelegramModuleEngine управляет существованием, конфигурацией, доступностью, зависимостями и активацией модулей, но никогда не управляет бизнес-логикой конкретного модуля.

Например Engine знает:

    cinema-radar
    platformEnabled = true
    requires = telegram.navigation
    provides = cinema.search
Но Engine не знает:

- как ищется фильм; как выбираются источники; как дедуплицируются результаты; как рассчитывается рейтинг; как работает бизнес-логика Cinema Radar.

Итоговый принцип:

> Module package описывает, что он предоставляет. Platform решает, какие modules установлены. Bot решает, какие из установленных modules активированы. Permission system (отдельный модуль) решает, кто внутри bot может ими пользоваться. Management и Menu только визуализируют и используют эти данные через public contracts Engine.

Иными словами:

    Package
        ↓
    Module Definition
        ↓
    Platform Installation
        ↓
    Bot Activation
        ↓
    Capabilities / Contributions
        ↓
    Permissions / Groups / Users
        ↓
    Runtime
`TelegramModuleEngine` является связующим инфраструктурным слоем этой модели и единой точкой управления module lifecycle.

---
# 75. Documentation Structure
Architecture documentation maintained as a set of focused documents:

    docs/architecture/
    │
    ├── README.md
    ├── EXTRACTED-IDEAS.md
    │
    ├── 00-overview.md        — master overview (этот документ)
    ├── 02.md                 — module model (включая бывший 42)
    ├── 08.md                 — platform configuration / tg_modules contract
    ├── 12.md                 — frontend integration
    ├── 16.md                 — configuration and settings (включая бывший 59)
    ├── 32.md                 — storage and tenancy (включая бывший 13, 50)
    ├── 33.md                 — module contributions (включая бывший 13-content, 50)
    ├── 38.md                 — runtime / workers / snapshots (включая бывший 39, 51)
    ├── 40.md                 — module lifecycle (включая бывший 53)
    └── 41.md                 — observability / diagnostics
Сквозные ссылки: lifecycle — `40.md`; contributions — `33.md`; storage/tenancy (бывший 15-content) — `32.md`; configuration/settings — `16.md`; module model — `02.md`.

---
# 76. Open Questions
Следующие вопросы не должны решаться импульсивно при реализации и должны быть рассмотрены в последующих RFC:

1. Точная структура `TelegramModuleDefinition`.
2. Точный контракт `TelegramModuleConfig`.
3. Нужен ли отдельный `TelegramModuleManifest`.
4. Точная state machine module activation.
5. Точная dependency resolution model.
6. Формат capability IDs.
7. Формат contribution IDs и external keys.
8. Точная структура `BotModuleActivation`.
9. Где хранить module-specific settings.
10. Как реализовать settings schema.
11. Как реализовать declarative admin UI.
12. Как реализовать custom frontend contributions.
13. Точный lifecycle migrations/seeders.
14. Точный lifecycle jobs/events.
15. Transaction boundaries.
16. Cache invalidation.
17. Audit storage (в audit-модуле).
18. Runtime activation checks (guard на execution boundary).
19. Compatibility strategy со старым кодом.
20. Возможность feature-level activation в будущем.
21. Laravel Bootstrap: что требует регистрации до полного runtime Engine; регистрировать ли provider при `enabled=false`.
Эти вопросы должны быть решены в специализированных документах, а не через неявные решения в implementation code. Код до утверждения соответствующих контрактов писать не следует.
