<?php
// Data Mapper base class - the ORM layer. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Core;

use InvalidArgumentException;
use PDO;

// Moves rows between the database and the entity objects. Entities hold no SQL
// and mappers hold no business rules. Subclasses supply the table, its key, the
// allowed columns, and the two conversions.
//
// Values are always bound. Table and column names cannot be bound - SQL has no
// placeholder for them - so every identifier is checked against columns() first.
abstract class DataMapper
{
    // Subclasses inherit both of these. Do not redeclare them in a subclass:
    // PHP requires a redeclared property to repeat the parent type exactly.
    protected PDO $pdo;

    // One row loaded twice returns the same object.
    protected array $identityMap = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    abstract protected function table(): string;

    abstract protected function primaryKey(): string;

    abstract protected function columns(): array;

    abstract protected function toEntity(array $row): Entity;

    abstract protected function toRow(Entity $entity): array;

    public function find(string $id): ?Entity
    {
        if (isset($this->identityMap[$id])) {
            return $this->identityMap[$id];
        }

        $row = $this->selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :pk LIMIT 1', $this->table(), $this->primaryKey()),
            [':pk' => $id]
        );

        return $row === null ? null : $this->register($this->toEntity($row));
    }

    public function findBy(array $conditions = [], ?string $orderBy = null, string $direction = 'ASC'): array
    {
        $where  = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $this->assertColumn($column);
            $where[] = sprintf('`%s` = :%s', $column, $column);
            $params[':' . $column] = $value;
        }

        $sql = sprintf('SELECT * FROM `%s`', $this->table());

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if ($orderBy !== null) {
            $this->assertColumn($orderBy);
            $sql .= sprintf(' ORDER BY `%s` %s', $orderBy, $this->direction($direction));
        }

        return $this->hydrateAll($this->select($sql, $params));
    }

    public function insert(Entity $entity): void
    {
        $row = $this->toRow($entity);

        foreach (array_keys($row) as $column) {
            $this->assertColumn($column);
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table(),
            implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row))),
            implode(', ', array_map(function ($c) { return ':' . $c; }, array_keys($row)))
        );

        $this->execute($sql, $this->bind($row));
        $this->register($entity);
    }

    public function update(Entity $entity): void
    {
        $id = $entity->getIdentity();

        if ($id === null) {
            throw new InvalidArgumentException('Cannot update an unsaved entity.');
        }

        $row = $this->toRow($entity);
        unset($row[$this->primaryKey()]);

        $assignments = [];

        foreach (array_keys($row) as $column) {
            $this->assertColumn($column);
            $assignments[] = sprintf('`%s` = :%s', $column, $column);
        }

        $params = $this->bind($row);
        $params[':pk'] = $id;

        $this->execute(sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :pk',
            $this->table(),
            implode(', ', $assignments),
            $this->primaryKey()
        ), $params);
    }

    public function delete(string $id): void
    {
        $this->execute(
            sprintf('DELETE FROM `%s` WHERE `%s` = :pk', $this->table(), $this->primaryKey()),
            [':pk' => $id]
        );

        unset($this->identityMap[$id]);
    }

    protected function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    protected function selectOne(string $sql, array $params = []): ?array
    {
        return $this->select($sql, $params)[0] ?? null;
    }

    protected function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    protected function hydrateAll(array $rows): array
    {
        $entities = [];

        foreach ($rows as $row) {
            $id = $row[$this->primaryKey()] ?? null;

            if ($id !== null && isset($this->identityMap[$id])) {
                $entities[] = $this->identityMap[$id];
                continue;
            }

            $entities[] = $this->register($this->toEntity($row));
        }

        return $entities;
    }

    protected function assertColumn(string $column): void
    {
        if (!in_array($column, $this->columns(), true)) {
            // Does not echo the value back: repeating a crafted payload invites XSS.
            throw new InvalidArgumentException('Unknown column on ' . static::class . '.');
        }
    }

    protected function direction(string $direction): string
    {
        return strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
    }

    // First one wins. Replacing it would let two references to the same row
    // drift apart.
    protected function register(Entity $entity): Entity
    {
        $id = $entity->getIdentity();

        if ($id === null) {
            return $entity;
        }

        return $this->identityMap[$id] ??= $entity;
    }

    private function bind(array $row): array
    {
        $params = [];

        foreach ($row as $column => $value) {
            $params[':' . $column] = $value;
        }

        return $params;
    }
}
