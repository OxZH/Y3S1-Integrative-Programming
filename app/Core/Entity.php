<?php
// Base entity. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Core;

use Closure;

abstract class Entity
{
    private $loaders = [];

    abstract public function getIdentity(): ?string;

    // Related objects are held as references rather than ids. Loading them
    // eagerly would mean one extra query per row in a list, so the mapper
    // registers a closure and it only runs if a getter actually asks.
    public function setLoader(string $property, Closure $loader): void
    {
        $this->loaders[$property] = $loader;
    }

    protected function resolve(string $property): mixed
    {
        if (isset($this->loaders[$property])) {
            $loader = $this->loaders[$property];

            // Unset first, so a loader that throws is not retried on every read.
            unset($this->loaders[$property]);

            $this->{$property} = $loader();
        }

        return $this->{$property};
    }
}
