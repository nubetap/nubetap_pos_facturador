<?php

namespace App\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

abstract class BaseDTO implements Arrayable, JsonSerializable
{
    /**
     * Convert DTO to array
     */
    public function toArray(): array
    {
        $properties = get_object_vars($this);
        $result = [];

        foreach ($properties as $key => $value) {
            if ($value instanceof Arrayable) {
                $result[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $result[$key] = array_map(function ($item) {
                    return $item instanceof Arrayable ? $item->toArray() : $item;
                }, $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * JSON serialize
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Create DTO from array
     */
    public static function fromArray(array $data): static
    {
        return new static(...$data);
    }

    /**
     * Create DTO from request
     */
    public static function fromRequest($request): static
    {
        $data = $request instanceof \Illuminate\Http\Request
            ? $request->validated()
            : $request;

        return static::fromArray($data);
    }
}
