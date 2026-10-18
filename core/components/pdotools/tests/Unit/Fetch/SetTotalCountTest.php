<?php

declare(strict_types=1);

namespace ModxPro\PdoTools\Tests\Unit\Fetch;

use ModxPro\PdoTools\Fetch;
use PHPUnit\Framework\TestCase;

/**
 * MySQL 8.4 removed SQL_CALC_FOUND_ROWS: setTotal() must count via the same
 * SELECT wrapped as a derived table, with the trailing LIMIT/OFFSET stripped
 * (GROUP BY / HAVING / DISTINCT semantics identical to FOUND_ROWS()).
 */
final class SetTotalCountTest extends TestCase
{
    /**
     * Minimal xPDOQuery double: toSQL() mirrors the real builder, which appends
     * LIMIT/OFFSET from the query array at the end of the statement.
     */
    private function makeQuery(int $limit, int $offset): object
    {
        return new class($limit, $offset) {
            public function __construct(private int $limit, private int $offset)
            {
            }

            public function toSQL(): string
            {
                $sql = 'SELECT `modResource`.`id`, `Data`.`price` FROM `modx_site_content` AS `modResource`'
                    . ' LEFT JOIN `modx_ms3_products` AS `Data` ON `modResource`.`id` = `Data`.`id`'
                    . " WHERE `modResource`.`deleted` = 0 GROUP BY `modResource`.`id` HAVING `Data`.`price` > 0";
                if ($this->limit > 0) {
                    $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
                }

                return $sql;
            }
        };
    }

    private function makeFetch(object $query, object $modx): Fetch
    {
        $fetch = (new \ReflectionClass(Fetch::class))->newInstanceWithoutConstructor();

        foreach (['modx' => $modx, 'config' => ['setTotal' => true, 'return' => 'data', 'totalVar' => 'total'], 'query' => $query, 'time' => microtime(true)] as $property => $value) {
            $prop = new \ReflectionProperty(Fetch::class, $property);
            $prop->setAccessible(true);
            $prop->setValue($fetch, $value);
        }

        return $fetch;
    }

    public function testTotalUsesDerivedCountWithoutCalcFoundRows(): void
    {
        $captured = new class() {
            public ?string $sql = null;
            /** @var array<string, mixed> */
            public array $placeholders = [];
            public float $queryTime = 0.0;
            public int $executedQueries = 0;

            public function prepare(string $sql): object
            {
                return new class($sql, $this) {
                    public function __construct(private string $sql, private object $captured)
                    {
                    }

                    public function execute(): bool
                    {
                        $this->captured->sql = $this->sql;

                        return true;
                    }

                    public function fetch(int $mode = PDO::FETCH_ASSOC)
                    {
                        return 42;
                    }
                };
            }

            public function setPlaceholder(string $key, $value): void
            {
                $this->placeholders[$key] = $value;
            }
        };

        $fetch = $this->makeFetch($this->makeQuery(10, 20), $captured);
        $fetch->setTotal();

        $expected = 'SELECT COUNT(*) FROM ('
            . 'SELECT `modResource`.`id`, `Data`.`price` FROM `modx_site_content` AS `modResource`'
            . ' LEFT JOIN `modx_ms3_products` AS `Data` ON `modResource`.`id` = `Data`.`id`'
            . " WHERE `modResource`.`deleted` = 0 GROUP BY `modResource`.`id` HAVING `Data`.`price` > 0"
            . ') AS `_pdo_total`';

        self::assertSame($expected, $captured->sql);
        self::assertStringNotContainsString('SQL_CALC_FOUND_ROWS', (string) $captured->sql);
        self::assertStringNotContainsString('LIMIT', (string) $captured->sql);
        self::assertSame('total', array_key_first($captured->placeholders));
        self::assertSame(42, $captured->placeholders['total']);
        self::assertSame(1, $captured->executedQueries);
    }

    public function testTotalSkippedWhenSetTotalDisabled(): void
    {
        $captured = new class() {
            public ?string $sql = null;
            /** @var array<string, mixed> */
            public array $placeholders = [];
            public float $queryTime = 0.0;
            public int $executedQueries = 0;

            public function prepare(string $sql): object
            {
                return new class($sql, $this) {
                    public function __construct(private string $sql, private object $captured)
                    {
                    }

                    public function execute(): bool
                    {
                        $this->captured->sql = $this->sql;

                        return true;
                    }

                    public function fetch(int $mode = PDO::FETCH_ASSOC)
                    {
                        return 0;
                    }
                };
            }

            public function setPlaceholder(string $key, $value): void
            {
                $this->placeholders[$key] = $value;
            }
        };

        $fetch = $this->makeFetch($this->makeQuery(10, 20), $captured);

        $config = new \ReflectionProperty(Fetch::class, 'config');
        $config->setAccessible(true);
        $config->setValue($fetch, ['setTotal' => false, 'return' => 'data', 'totalVar' => 'total']);

        $fetch->setTotal();

        self::assertNull($captured->sql);
        self::assertSame([], $captured->placeholders);
    }
}
