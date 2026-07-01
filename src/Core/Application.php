<?php

declare(strict_types=1);

namespace Msi\Campaignchi\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Application Kernel
 *
 * Central orchestrator of the plugin lifecycle:
 *  1. Holds the DI Container
 *  2. Registers all Service Providers
 *  3. Calls register() then boot() on each provider
 *
 * Usage (from bootstrap file):
 *   $app = Application::getInstance();
 *   $app->boot();
 *
 * @package Msi\Campaignchi\Core
 */
class Application
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var Container DI Container */
    private Container $container;

    /** @var ServiceProvider[] Registered providers */
    private array $providers = [];

    /** @var bool Whether the app has been booted */
    private bool $booted = false;

    // -------------------------------------------------------
    // Singleton
    // -------------------------------------------------------

    private function __construct()
    {
        $this->container = new Container();

        // Bind the container to itself so providers can resolve it
        $this->container->instance(Container::class, $this->container);
    }

    /**
     * Get or create the single Application instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------
    // Boot
    // -------------------------------------------------------

    /**
     * Boot the application:
     *  1. Load translations
     *  2. Register all service providers
     *  3. Call boot() on each provider
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->loadTextDomain();
        \Msi\Campaignchi\Core\Installer::maybeUpgrade();
        $this->registerProviders();
        $this->bootProviders();

        $this->booted = true;
    }

    // -------------------------------------------------------
    // Service Providers
    // -------------------------------------------------------

    /**
     * Register all module service providers.
     * Order matters: Core first, then Admin, Pricing, then Frontend.
     */
    private function registerProviders(): void
    {
        $providers = [
            \Msi\Campaignchi\Admin\AdminServiceProvider::class,
            \Msi\Campaignchi\Campaign\Pricing\PricingServiceProvider::class,
            \Msi\Campaignchi\Analytics\AnalyticsServiceProvider::class,
            \Msi\Campaignchi\Frontend\FrontendServiceProvider::class,
            \Msi\Campaignchi\Templates\TemplatesServiceProvider::class,
        ];

        foreach ($providers as $providerClass) {
            $provider = new $providerClass($this->container);

            $provider->register();

            $this->providers[] = $provider;
        }
    }
    /**
     * Boot all registered providers after all are registered.
     */
    private function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    // -------------------------------------------------------
    // Translations
    // -------------------------------------------------------

    /**
     * Load plugin text domain for translations.
     */
    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'campaignchi',
            false,
            CMC_BASENAME . '/languages'
        );
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    /**
     * Get the DI container.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Resolve a class from the container.
     *
     * @param string $abstract
     * @return mixed
     */
    public function make(string $abstract): mixed
    {
        return $this->container->make($abstract);
    }
}
