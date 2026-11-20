<?php

namespace App\Services\Navigation;

/**
 * One sidebar entry. A null url means the page does not exist yet.
 */
final readonly class MenuItem
{
    /**
     * @param  list<MenuItem>  $children
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public ?string $url = null,
        public bool $active = false,
        public ?string $badge = null,
        public array $children = [],
    ) {}

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Active itself or through one of its children.
     */
    public function isOpen(): bool
    {
        if ($this->active) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->isOpen()) {
                return true;
            }
        }

        return false;
    }
}
