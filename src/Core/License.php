<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * License Gate (RTL-Theme / ionCube)
 *
 * Encapsulates the official RTL License check so the rest of the plugin can
 * gate features behind a single, cached `License::isActive()` call instead of
 * repeating the raw snippet.
 *
 * The protected license file is shipped at the plugin root:
 *   RTL_License_050f8026f618586d.php
 *
 * The check is intentionally strict — it verifies the file's SHA-1 before
 * including it, so a tampered/replaced license file is rejected and the
 * product behaves as "not activated".
 *
 * @package Msi\Campaignchi\Core
 */
final class License
{
    /** Expected RTL license class name (also the file base name). */
    private const CLASS_NAME = 'RTL_License_050f8026f618586d';

    /** SHA-1 of the genuine, unmodified license file. */
    private const FILE_HASH  = 'e06b0c76314cfcb12d140c6eae9b7f4e46d3ea4a';

    /** @var bool|null Per-request cache of the activation state. */
    private static ?bool $active = null;

    /**
     * Whether the product is licensed and active right now.
     *
     * Result is cached for the duration of the request. Any failure
     * (missing file, hash mismatch, missing class/method, exception)
     * resolves to false — i.e. "not activated".
     */
    public static function isActive(): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        return self::$active = self::evaluate();
    }

    /**
     * Run the RTL License check exactly once.
     */
    private static function evaluate(): bool
    {
        $filePath = self::filePath();
        $fileHash = @sha1_file($filePath);

        if ($fileHash !== self::FILE_HASH || !file_exists($filePath)) {
            return false;
        }

        require_once $filePath;

        if (!class_exists(self::CLASS_NAME) || !method_exists(self::CLASS_NAME, 'isActive')) {
            return false;
        }

        try {
            $className = self::CLASS_NAME;
            $license   = new $className();
            return $license->isActive() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Absolute path to the RTL license file.
     *
     * Defaults to the plugin root. Filterable so the file can be relocated
     * (e.g. during development) without touching this class.
     */
    private static function filePath(): string
    {
        $default = self::pluginRoot()
            . DIRECTORY_SEPARATOR . self::CLASS_NAME . '.php';

        if (function_exists('apply_filters')) {
            /** @var string $path */
            $path = apply_filters('cmc_license_file_path', $default);
            return $path;
        }

        return $default;
    }

    /**
     * Plugin root directory (where the license file lives).
     */
    private static function pluginRoot(): string
    {
        // CMC_PATH is defined in campaignchi.php; fall back to this file's
        // location (src/Core/ → plugin root) if it is ever unavailable.
        if (defined('CMC_PATH')) {
            return rtrim((string) constant('CMC_PATH'), '/\\');
        }

        return dirname(__DIR__, 2);
    }
}
