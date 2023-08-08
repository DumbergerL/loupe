<?php

declare(strict_types=1);

namespace Loupe\Loupe\Internal\Filter\Ast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

enum Operator: string
{
    case Equals = '=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case In = 'IN';
    case LowerThan = '<';
    case LowerThanOrEquals = '<=';
    case NotEquals = '!=';
    case NotIn = 'NOT IN';

    public function buildSql(Connection $connection, float|string|array $value): string
    {
        if (\is_array($value)) {
            foreach ($value as &$v) {
                $this->quote($connection, $v);
            }
        } else {
            $this->quote($connection, $value);
        }

        return match ($this) {
            self::Equals,
            self::NotEquals,
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LowerThan,
            self::LowerThanOrEquals => $this->value . ' ' . $value,
            self::In, self::NotIn => $this->value . ' (' . implode(', ', $value) . ')',
        };
    }

    public static function fromString(string $operator): self
    {
        return match ($operator) {
            '=' => self::Equals,
            '>' => self::GreaterThan,
            '>=' => self::GreaterThanOrEquals,
            '<' => self::LowerThan,
            '<=' => self::LowerThanOrEquals,
            'IN' => self::In,
            'NOT IN' => self::NotIn,
            default => throw new \InvalidArgumentException('Invalid operator given.')
        };
    }

    public function isNegative(): bool
    {
        return match ($this) {
            self::Equals,
            self::GreaterThan,
            self::GreaterThanOrEquals,
            self::LowerThan,
            self::LowerThanOrEquals,
            self::In => false,
            self::NotIn,
            self::NotEquals => true,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Equals => self::NotEquals,
            self::NotEquals => self::Equals,
            self::GreaterThan => self::LowerThanOrEquals,
            self::GreaterThanOrEquals => self::LowerThan,
            self::LowerThan => self::GreaterThanOrEquals,
            self::LowerThanOrEquals => self::GreaterThan,
            self::In => self::NotIn,
            self::NotIn => self::In,
        };
    }

    private function quote($connection, float|string &$value): void
    {
        if (\is_string($value)) {
            $value = $connection->quote($value, ParameterType::STRING);
        }
    }
}
