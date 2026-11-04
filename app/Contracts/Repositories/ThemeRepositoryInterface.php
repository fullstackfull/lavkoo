<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Model;

interface ThemeRepositoryInterface extends RepositoryInterface
{
    /** The single active theme, if any. */
    public function getActiveTheme(): ?Model;

    /** Whether a slug is already taken (optionally excluding an id). */
    public function slugExists(string $slug, ?int $exceptId = null): bool;
}
