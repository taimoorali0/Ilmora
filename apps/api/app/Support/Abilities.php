<?php

namespace App\Support;

/**
 * Resolves role → ability with wildcards from config('ilmora.roles').
 * "fees.*" allows "fees.edit"; "*" allows everything.
 */
final class Abilities
{
    /** @param string[] $roles */
    public static function allows(array $roles, string $ability, ?array $map = null): bool
    {
        $map ??= config('ilmora.roles');
        foreach ($roles as $role) {
            foreach ($map[$role] ?? [] as $granted) {
                if ($granted === '*' || $granted === $ability) {
                    return true;
                }
                if (str_ends_with($granted, '.*') && str_starts_with($ability, substr($granted, 0, -1))) {
                    return true;
                }
            }
        }
        return false;
    }
}
