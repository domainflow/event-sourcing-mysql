# DomainFlow EventSourcing MySQL

[![Tests](https://github.com/domainflow/event-sourcing-mysql/actions/workflows/tests.yml/badge.svg)](https://github.com/domainflow/event-sourcing-mysql/actions/workflows/tests.yml)
![Packagist Version](https://img.shields.io/packagist/v/domainflow/event-sourcing-mysql)
![PHP Version](https://img.shields.io/packagist/php-v/domainflow/event-sourcing-mysql)
![License](https://img.shields.io/github/license/domainflow/event-sourcing-mysql)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%210-brightgreen.svg)

A PDO/MySQL storage adapter for [`domainflow/event-sourcing-core`](https://github.com/domainflow/event-sourcing-core)
— implements `EventStorageInterface`, `SnapshotStorageInterface`, `SnapshotHistoryStorageInterface`, and `ProcessManagerStorageInterface` against MySQL 8. No domain logic of its own — no aggregate modeling, no business rules, just translation between Core's interfaces and SQL.

## Requirements

- PHP 8.4+
- `ext-pdo`
- A reachable MySQL 8+ instance
- InnoDB (the default). The atomicity guarantee below is a transaction, and MyISAM has none.


## Installation

```bash
composer require domainflow/event-sourcing-mysql
```


## Usage

```php
use DomainFlow\EventSourcingMySQL\Storage\MySqlEventStorage;
use DomainFlow\EventSourcingMySQL\Snapshot\MySqlSnapshotStorage;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=my_app;charset=utf8mb4', $user, $pass);

$facade = new EventSourcingFacade(
    new MySqlEventStorage($pdo),
    new MySqlSnapshotStorage($pdo)
);

$order = new Order();
$order->create($orderId, 'customer-1');
$facade->persist($order);
```

## Development

```bash
docker compose up -d          # start a local MySQL 8 instance
composer install
composer quality              # lint + static analysis + full test suite (100% coverage required) + audit
```

## License

MIT — see [LICENSE](LICENSE).
