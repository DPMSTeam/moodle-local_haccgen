<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Session-scoped cache helper for local_haccgen.
 *
 * Provides a simple wrapper around Moodle's cache API for storing
 * temporary generation data during the multi-step workflow.
 *
 * @package     local_haccgen
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_haccgen;

/**
 * Helper class for storing and retrieving session-scoped data.
 */
class session_store {

    /**
     * Get the cache instance used for session storage.
     *
     * @return \cache Cache instance.
     */
    private static function cache(): \cache {
        return \cache::make('local_haccgen', 'sessiondata');
    }

    /**
     * Retrieve a value from the session cache.
     *
     * @param string $key Cache key.
     * @return mixed|null Cached value or null if not found.
     */
    public static function get(string $key) {
        return self::cache()->get($key);
    }

    /**
     * Store a value in the session cache.
     *
     * @param string $key Cache key.
     * @param mixed $value Value to store.
     * @return void
     */
    public static function set(string $key, $value): void {
        self::cache()->set($key, $value);
    }

    /**
     * Delete a value from the session cache.
     *
     * @param string $key Cache key.
     * @return void
     */
    public static function delete(string $key): void {
        self::cache()->delete($key);
    }

    /**
     * Clear all session-scoped cache data for this plugin.
     *
     * @return void
     */
    public static function clear(): void {
        self::cache()->purge();
    }
}
