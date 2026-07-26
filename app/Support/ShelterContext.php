<?php

namespace App\Support;

/**
 * Holds the "which shelter am I operating right now?" session key and its
 * accessors.
 *
 * WHY THIS CLASS EXISTS: the key used to be a constant on the ResolvesCenter
 * trait. PHP 8.2 allows constants to be DECLARED in a trait, but they can only
 * be read through a class that uses the trait -- never as
 * `ResolvesCenter::ACTIVE_CENTER_KEY`. Doing so throws
 * "Cannot access trait constant ... directly" at runtime, which took down every
 * barangay screen because the view composer read it that way.
 *
 * A plain final class has no such restriction and gives both the trait and the
 * view composer one place to read from.
 */
final class ShelterContext
{
    public const SESSION_KEY = 'active_center_id';

    /** The active shelter id, or null when nothing has been chosen yet. */
    public static function id(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    public static function set(int $id): void
    {
        session([self::SESSION_KEY => $id]);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
