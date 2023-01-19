<?php

namespace Terminal42\Loupe\Internal;

use Terminal42\Loupe\Exception\InvalidDocumentException;
use Terminal42\Loupe\Exception\InvalidJsonException;

class LoupeTypes
{
    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_ARRAY_STRING = 'array<string>';
    public const TYPE_ARRAY_NUMBER = 'array<number>';


    public static function getTypeFromValue(mixed $variable): string
    {
        if (is_float($variable) || is_int($variable)) {
            return self::TYPE_NUMBER;
        }

        if (is_array($variable)) {
            foreach ($variable as $v) {
                $type = self::getTypeFromValue($v);

                if ($type === self::TYPE_NUMBER) {
                    return self::TYPE_ARRAY_NUMBER;
                }

                // Everything else will be converted to a string
                return self::TYPE_ARRAY_STRING;
            }
        }

        // Everything else will be converted to a string
        return self::TYPE_STRING;
    }

    public static function convertValueToType(mixed $attributeValue, string $type): array|string|float
    {
        return match ($type) {
            self::TYPE_STRING => self::convertToString($attributeValue),
            self::TYPE_NUMBER => self::convertToFloat($attributeValue),
            self::TYPE_ARRAY_STRING => self::convertToArrayOfStrings($attributeValue),
            self::TYPE_ARRAY_NUMBER => self::convertToArrayOfFloats($attributeValue),
        };
    }

    public static function isSingleType(string $type): bool
    {
        return match ($type) {
            self::TYPE_STRING, self::TYPE_NUMBER => true,
            default => false
        };
    }

    private static function convertToString(mixed $attributeValue): string
    {
        if (is_string($attributeValue)) {
            return $attributeValue;
        }

        if (is_array($attributeValue)) {
            return 'array';
        }

        return (string) $attributeValue;
    }

    private static function convertToFloat(mixed $attributeValue): float
    {
        if (is_float($attributeValue)) {
            return $attributeValue;
        }

        if (is_int($attributeValue)) {
            return (float) $attributeValue;
        }

        if (is_string($attributeValue)) {
            return (float) $attributeValue;
        }

        return 0;
    }

    private static function convertToArrayOfStrings(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToString($v);
        }

        return $result;
    }

    private static function convertToArrayOfFloats(array $attributeValue): array
    {
        $result = [];

        foreach ($attributeValue as $k => $v) {
            $result[$k] = self::convertToFloat($v);
        }

        return $result;
    }
}
